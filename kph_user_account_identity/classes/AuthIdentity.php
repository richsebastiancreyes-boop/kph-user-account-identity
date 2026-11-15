<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/Validator.php';

// Links a user to a Google or Facebook account.
class AuthIdentity
{
    private PDO $db;

    public const PROVIDERS = ["google", "facebook"];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // Returns the UserID that owns this Google/Facebook account, or null if it is not linked yet.
    public function findUserId(string $provider, string $providerUserId): ?int
    {
        $stmt = $this->db->prepare("SELECT UserID FROM `auth_identity` WHERE Provider = ? AND ProviderUserID = ?");
        $stmt->execute([$provider, $providerUserId]);
        $userId = $stmt->fetchColumn();
        return $userId === false ? null : (int)$userId;
    }

    // Links a Google/Facebook account to a user. Returns the new AuthIdentityID.
    public function link(int $userId, string $provider, string $providerUserId): int
    {
        $provider = Validator::oneOf($provider, self::PROVIDERS, "Provider");
        $providerUserId = Validator::text($providerUserId, "Provider user ID", 255);

        if ($this->findUserId($provider, $providerUserId) !== null) {
            throw new InvalidArgumentException("This $provider account is already linked to a user.");
        }
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `auth_identity` WHERE UserID = ? AND Provider = ?");
        $stmt->execute([$userId, $provider]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException("This user already has a $provider account linked.");
        }

        $stmt = $this->db->prepare("INSERT INTO `auth_identity` (UserID, Provider, ProviderUserID) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $provider, $providerUserId]);
        return (int)$this->db->lastInsertId();
    }

    // The linked accounts of a user (the provider's own ID is not shown).
    public function getByUser(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT AuthIdentityID, UserID, Provider, LinkedAt FROM `auth_identity` WHERE UserID = ? ORDER BY AuthIdentityID");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}
