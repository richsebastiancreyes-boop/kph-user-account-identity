<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Crypto.php';

// One-time codes for email verification, phone verification, password reset, and cross-module sessions.
// Only the scrambled (hashed) token is saved. The real token is given once, when it is created.
class VerificationToken
{
    private PDO $db;

    public const PURPOSES = ["verify_email", "verify_phone", "reset_password", "session"];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // Creates a token and returns it. Older unused tokens of the same purpose stop working.
    public function create(int $userId, string $purpose): string
    {
        $purpose = Validator::oneOf($purpose, self::PURPOSES, "Purpose");
        if ($purpose === "session") {
            return $this->createSession($userId);
        }

        // A 6-digit code is easy to type from an SMS or email. A reset link needs a long random token.
        $token = ($purpose === "reset_password") ? bin2hex(random_bytes(32)) : (string)random_int(100000, 999999);
        $minutes = (int)(Database::getConfig()["token_minutes"][$purpose] ?? 30);

        $this->db->beginTransaction();
        $stmt = $this->db->prepare("UPDATE `verification_token` SET UsedAt = NOW() WHERE UserID = ? AND Purpose = ? AND UsedAt IS NULL");
        $stmt->execute([$userId, $purpose]);

        $stmt = $this->db->prepare(
            "INSERT INTO `verification_token` (UserID, Purpose, TokenHash, ExpiresAt)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))"
        );
        $stmt->execute([$userId, $purpose, Crypto::hashToken($token), $minutes]);
        $this->db->commit();

        return $token;
    }

    // Creates a cross-module Bearer session token stored as SHA-256 (matching Module 5's SHA2(token, 256) check).
    public function createSession(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $minutes = (int)(Database::getConfig()["token_minutes"]["session"] ?? 1440);
        $sha256 = hash("sha256", $token);

        $stmt = $this->db->prepare(
            "INSERT INTO `verification_token` (UserID, Purpose, TokenHash, ExpiresAt)
             VALUES (?, 'session', ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))"
        );
        $stmt->execute([$userId, $sha256, $minutes]);

        return $token;
    }

    // Verifies a Bearer session token and returns the active UserID (or null if invalid/expired/revoked).
    public function verifySession(string $token): ?int
    {
        $token = trim($token);
        if ($token === "") {
            return null;
        }
        $sha256 = hash("sha256", $token);
        $hmac = Crypto::hashToken($token);

        $stmt = $this->db->prepare(
            "SELECT vt.UserID
             FROM `verification_token` vt
             JOIN `user` u ON u.UserID = vt.UserID
             WHERE (vt.TokenHash = ? OR vt.TokenHash = ? OR vt.TokenHash = ?)
               AND vt.ExpiresAt > NOW()
               AND vt.UsedAt IS NULL
               AND u.Status = 'Active'
             ORDER BY vt.TokenID DESC LIMIT 1"
        );
        $stmt->execute([$sha256, $hmac, $token]);
        $uid = $stmt->fetchColumn();
        return $uid === false ? null : (int)$uid;
    }

    // Revokes a Bearer session token on logout so neither Module 1 nor Module 5 accepts it anymore.
    public function revokeSession(string $token): void
    {
        $token = trim($token);
        if ($token === "") {
            return;
        }
        $sha256 = hash("sha256", $token);
        $hmac = Crypto::hashToken($token);

        $stmt = $this->db->prepare(
            "UPDATE `verification_token`
             SET UsedAt = NOW(), ExpiresAt = NOW()
             WHERE (TokenHash = ? OR TokenHash = ? OR TokenHash = ?)
               AND UsedAt IS NULL"
        );
        $stmt->execute([$sha256, $hmac, $token]);
    }

    // Checks the token and marks it as used. Returns true, or throws a clear message.
    public function verify(int $userId, string $purpose, string $token): bool
    {
        $purpose = Validator::oneOf($purpose, self::PURPOSES, "Purpose");
        $token = trim($token);
        if ($token === "") {
            throw new InvalidArgumentException("Token is required.");
        }

        $stmt = $this->db->prepare(
            "SELECT TokenID, UsedAt, (ExpiresAt < NOW()) AS IsExpired
             FROM `verification_token`
             WHERE UserID = ? AND Purpose = ? AND TokenHash = ?
             ORDER BY TokenID DESC LIMIT 1"
        );
        $stmt->execute([$userId, $purpose, Crypto::hashToken($token)]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new InvalidArgumentException("The code is not correct.");
        }
        if ($row["UsedAt"] !== null) {
            throw new InvalidArgumentException("This code was already used. Please ask for a new one.");
        }
        if ((int)$row["IsExpired"] === 1) {
            throw new InvalidArgumentException("This code has expired. Please ask for a new one.");
        }

        // "AND UsedAt IS NULL" makes sure two requests at the same time cannot both use the token.
        $stmt = $this->db->prepare("UPDATE `verification_token` SET UsedAt = NOW() WHERE TokenID = ? AND UsedAt IS NULL");
        $stmt->execute([$row["TokenID"]]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException("This code was already used. Please ask for a new one.");
        }
        return true;
    }

    // Checks a password reset token without consuming it (used by reset-password.html on page load).
    public function checkResetToken(string $token): array
    {
        $token = trim($token);
        if ($token === "") {
            return ["ok" => false, "reason" => "invalid"];
        }
        $stmt = $this->db->prepare(
            "SELECT TokenID, UserID, UsedAt, (ExpiresAt < NOW()) AS IsExpired
             FROM `verification_token`
             WHERE Purpose = 'reset_password' AND TokenHash = ?
             ORDER BY TokenID DESC LIMIT 1"
        );
        $stmt->execute([Crypto::hashToken($token)]);
        $row = $stmt->fetch();

        if (!$row) {
            return ["ok" => false, "reason" => "invalid"];
        }
        if ($row["UsedAt"] !== null) {
            return ["ok" => false, "reason" => "used"];
        }
        if ((int)$row["IsExpired"] === 1) {
            return ["ok" => false, "reason" => "expired"];
        }
        return ["ok" => true, "UserID" => (int)$row["UserID"]];
    }
}
