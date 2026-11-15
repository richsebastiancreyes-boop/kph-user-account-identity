<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/UserProfile.php';
require_once __DIR__ . '/UserRole.php';
require_once __DIR__ . '/AuthIdentity.php';
require_once __DIR__ . '/VerificationToken.php';
require_once __DIR__ . '/KycVerification.php';

// The main class of the module: sign up, log in, edit account, verify, reset password, suspend.
// Nothing returned from this class contains PasswordHash.
class User
{
    private PDO $db;
    private UserProfile $profiles;
    private UserRole $roles;
    private AuthIdentity $identities;
    private VerificationToken $tokens;

    public const ACTIVE = "Active";
    public const SUSPENDED = "Suspended";

    // PasswordHash is NOT in this list on purpose.
    private const SAFE_COLUMNS = "UserID, FirstName, MiddleName, LastName, Email, PhoneNumber, EmailVerifiedAt,
                                  PhoneVerifiedAt, DateJoined, LastLoginAt, Status, SuspensionReason";

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->profiles = new UserProfile();
        $this->roles = new UserRole();
        $this->identities = new AuthIdentity();
        $this->tokens = new VerificationToken();
    }

    // ------------------------------------------------------------
    // Sign up
    // ------------------------------------------------------------

    // Registers a new user with a profile and the buyer role. Returns the new UserID.
    // Needs FirstName, LastName, Email, PhoneNumber, Password. Optional: MiddleName, Username, Bio, DateOfBirth.
    public function register(array $data): int
    {
        $first = Validator::name($data["FirstName"] ?? null, "First name");
        $middle = Validator::name($data["MiddleName"] ?? null, "Middle name", false);
        $last = Validator::name($data["LastName"] ?? null, "Last name");
        $email = Validator::email($data["Email"] ?? null);
        $phone = Validator::phone($data["PhoneNumber"] ?? null);
        $password = Validator::password($data["Password"] ?? null);

        $this->requireEmailFree($email, 0);
        $this->requirePhoneFree($phone, 0);

        // Everything is checked BEFORE the first insert, so a mistake never leaves half a user behind.
        $profile = $this->profiles->validate($data, true, 0, (string)strstr($email, "@", true));

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $this->db->beginTransaction();
        $userId = $this->insertUser($first, $middle, $last, $email, $phone, $hash, false);
        $this->profiles->create($userId, $profile, (string)strstr($email, "@", true));
        $this->roles->assignByName($userId, "buyer");
        $this->db->commit();

        return $userId;
    }

    // ------------------------------------------------------------
    // Log in
    // ------------------------------------------------------------

    // Log in with email OR phone number, plus the password. Returns the user.
    public function login($identifier, $password): array
    {
        $identifier = Validator::text($identifier, "Email or phone number", 191);
        if (!is_string($password) || $password === "") {
            throw new InvalidArgumentException("Password is required.");
        }

        $row = $this->findByIdentifier($identifier);

        // One message for every failure, so nobody can find out which emails or phones exist.
        // A user who signed up with Google/Facebook only has no password, so they land here too.
        if (!$row || $row["PasswordHash"] === null || !password_verify($password, $row["PasswordHash"])) {
            throw new AuthenticationException("The email/phone number or the password is not correct.");
        }
        $this->requireActive($row);

        return $this->markLoggedIn((int)$row["UserID"]);
    }

    // Log in with Google or Facebook.
    // Linked account found -> that user logs in. Not found -> a new user and link are created.
    // Returns ["User" => ..., "IsNewUser" => true/false].
    //
    // NOTE: in a real system the server must first ask Google/Facebook if the token is real.
    // KameraPH simulates the provider, so the data sent to this method is trusted.
    public function socialLogin(array $data): array
    {
        $provider = Validator::oneOf($data["Provider"] ?? null, AuthIdentity::PROVIDERS, "Provider");
        $providerUserId = Validator::text($data["ProviderUserID"] ?? null, "Provider user ID", 255);

        // 1. This Google/Facebook account is already linked.
        $linkedUserId = $this->identities->findUserId($provider, $providerUserId);
        if ($linkedUserId !== null) {
            $row = $this->findRowById($linkedUserId);
            $this->requireActive($row);
            return ["User" => $this->markLoggedIn($linkedUserId), "IsNewUser" => false];
        }

        // 2. Not linked yet. We need the details to find or create the user.
        $email = Validator::email($data["Email"] ?? null);
        $existing = $this->findByIdentifier($email);

        if ($existing) {
            // Only link to an account whose email is already verified. If we linked an unverified one,
            // a stranger could register your email first and then take over your Google login.
            if ($existing["EmailVerifiedAt"] === null) {
                throw new InvalidArgumentException("An account with this email already exists. Log in with your password and verify your email first.");
            }
            $this->requireActive($existing);
            $this->identities->link((int)$existing["UserID"], $provider, $providerUserId);
            return ["User" => $this->markLoggedIn((int)$existing["UserID"]), "IsNewUser" => false];
        }

        // 3. A brand new user. No password (PasswordHash stays NULL). Google/Facebook already verified the email.
        $first = Validator::name($data["FirstName"] ?? null, "First name");
        $middle = Validator::name($data["MiddleName"] ?? null, "Middle name", false);
        $last = Validator::name($data["LastName"] ?? null, "Last name");

        // The phone number is optional here ("" means not added yet). It can be added later on the profile page.
        $phone = "";
        if (isset($data["PhoneNumber"]) && trim((string)$data["PhoneNumber"]) !== "") {
            $phone = Validator::phone($data["PhoneNumber"]);
            $this->requirePhoneFree($phone, 0);
        }
        $profile = $this->profiles->validate($data, true, 0, (string)strstr($email, "@", true));

        $this->db->beginTransaction();
        $userId = $this->insertUser($first, $middle, $last, $email, $phone, null, true);
        $this->profiles->create($userId, $profile, (string)strstr($email, "@", true));
        $this->roles->assignByName($userId, "buyer");
        $this->identities->link($userId, $provider, $providerUserId);
        $this->db->commit();

        return ["User" => $this->markLoggedIn($userId), "IsNewUser" => true];
    }

    // ------------------------------------------------------------
    // Read
    // ------------------------------------------------------------

    // Gets one user (no password, no token). Returns null if the user does not exist.
    public function getById(int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT " . self::SAFE_COLUMNS . " FROM `user` WHERE UserID = ?");
        $stmt->execute([$userId]);
        return $this->format($stmt->fetch() ?: null);
    }

    // Full account view for the user's own profile/settings pages (merges user, profile, roles, KYC status).
    public function getAccountView(int $userId): ?array
    {
        $user = $this->getById($userId);
        if ($user === null) {
            return null;
        }
        $profile = $this->profiles->getByUserId($userId) ?: [];
        $roleList = $this->roles->getRolesByUser($userId);
        $roleNames = array_map(fn($r) => ucfirst(strtolower($r["RoleName"])), $roleList);
        $primaryRole = "Buyer";
        if (in_array("Admin", $roleNames, true)) {
            $primaryRole = "Admin";
        } elseif (in_array("Seller", $roleNames, true)) {
            $primaryRole = "Seller";
        } elseif (!empty($roleNames)) {
            $primaryRole = $roleNames[0];
        }

        $kyc = (new KycVerification())->getLatestByUser($userId);
        $kycStatus = "Not verified";
        if ($kyc !== null) {
            $kycStatus = ($kyc["VerificationStatus"] === KycVerification::APPROVED)
                ? "Verified"
                : $kyc["VerificationStatus"];
        }

        return array_merge($user, [
            "Username"           => $profile["Username"] ?? (string)strstr($user["Email"], "@", true),
            "Bio"                => $profile["Bio"] ?? "",
            "ProfilePictureURL"  => $profile["ProfilePictureURL"] ?? "",
            "DateOfBirth"        => $profile["DateOfBirth"] ?? "",
            "Role"               => $primaryRole,
            "Roles"              => $roleList,
            "VerificationStatus" => $kycStatus,
            "KYCSubmittedAt"     => $kyc["SubmittedAt"] ?? null,
            "RejectionReason"    => $kyc["RejectionReason"] ?? null,
        ]);
    }

    // For the admin. Filters: status, search (name, email or phone), limit, offset.
    public function getAll(array $filters = []): array
    {
        $where = [];
        $values = [];

        if (!empty($filters["status"])) {
            $where[] = "Status = ?";
            $values[] = Validator::oneOf($filters["status"], [self::ACTIVE, self::SUSPENDED], "Status");
        }
        if (!empty($filters["search"])) {
            $search = Validator::text($filters["search"], "Search", 100);
            $like = "%" . str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $search) . "%";
            $where[] = "(FirstName LIKE ? OR LastName LIKE ? OR Email LIKE ? OR PhoneNumber LIKE ?)";
            array_push($values, $like, $like, $like, $like);
        }

        $limit = isset($filters["limit"]) ? Validator::id($filters["limit"], "Limit") : 20;
        $offset = 0;
        if (isset($filters["offset"]) && (string)$filters["offset"] !== "0") {
            $offset = Validator::id($filters["offset"], "Offset");
        }
        $limit = min($limit, 100);

        $sql = "SELECT " . self::SAFE_COLUMNS . " FROM `user`";
        if (count($where) > 0) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY UserID LIMIT $limit OFFSET $offset";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        return array_map([$this, "format"], $stmt->fetchAll());
    }

    // The short version that any logged-in user may see (no email, no phone, no status).
    public function toPublic(array $user): array
    {
        return [
            "UserID"     => $user["UserID"],
            "FirstName"  => $user["FirstName"],
            "LastName"   => $user["LastName"],
            "DateJoined" => $user["DateJoined"]
        ];
    }

    // ------------------------------------------------------------
    // Change
    // ------------------------------------------------------------

    // The user edits their own details. Only the fields that are sent are changed.
    // A new email or phone number must be verified again.
    public function update(int $userId, array $data): array
    {
        if ($this->getById($userId) === null) {
            throw new OutOfBoundsException("User not found.");
        }

        $sets = [];
        $values = [];

        if (Validator::has($data, "FirstName")) {
            $sets[] = "FirstName = ?";
            $values[] = Validator::name($data["FirstName"], "First name");
        }
        if (Validator::has($data, "MiddleName")) {
            $sets[] = "MiddleName = ?";
            $values[] = Validator::name($data["MiddleName"], "Middle name", false);
        }
        if (Validator::has($data, "LastName")) {
            $sets[] = "LastName = ?";
            $values[] = Validator::name($data["LastName"], "Last name");
        }
        if (Validator::has($data, "Email")) {
            $email = Validator::email($data["Email"]);
            $this->requireEmailFree($email, $userId);
            $sets[] = "Email = ?";
            $values[] = $email;
            if ($email !== $this->currentValue($userId, "Email")) {
                $sets[] = "EmailVerifiedAt = NULL";
            }
        }
        if (Validator::has($data, "PhoneNumber")) {
            $phone = Validator::phone($data["PhoneNumber"]);
            $this->requirePhoneFree($phone, $userId);
            $sets[] = "PhoneNumber = ?";
            $values[] = $phone;
            if ($phone !== $this->currentValue($userId, "PhoneNumber")) {
                $sets[] = "PhoneVerifiedAt = NULL";
            }
        }
        if (count($sets) === 0) {
            throw new InvalidArgumentException("Nothing to update. Send FirstName, MiddleName, LastName, Email or PhoneNumber.");
        }

        $values[] = $userId;
        $stmt = $this->db->prepare("UPDATE `user` SET " . implode(", ", $sets) . " WHERE UserID = ?");
        $stmt->execute($values);

        return $this->getById($userId);
    }

    // Changes the password of a logged-in user. A user who signed up with Google/Facebook has no
    // password yet, so they do not need the current one.
    public function changePassword(int $userId, $currentPassword, $newPassword): void
    {
        $row = $this->findRowById($userId);
        if ($row === null) {
            throw new OutOfBoundsException("User not found.");
        }
        $newPassword = Validator::password($newPassword);

        if ($row["PasswordHash"] !== null) {
            if (!is_string($currentPassword) || !password_verify($currentPassword, $row["PasswordHash"])) {
                throw new InvalidArgumentException("The current password is not correct.");
            }
        }
        $this->savePassword($userId, $newPassword);
    }

    // Deletes a user's own account after confirming their password (if the account has a password).
    public function deleteAccount(int $userId, $password = null): void
    {
        $row = $this->findRowById($userId);
        if ($row === null) {
            throw new OutOfBoundsException("User not found.");
        }
        if ($row["PasswordHash"] !== null) {
            if (!is_string($password) || $password === "") {
                throw new InvalidArgumentException("Password is required.");
            }
            if (!password_verify($password, $row["PasswordHash"])) {
                throw new InvalidArgumentException("Wrong password.");
            }
        }

        $this->db->beginTransaction();
        // Clean up any cross-module shopping carts/items owned by this user if those tables exist
        foreach (["cart", "shopping_cart"] as $cartTable) {
            try {
                $stmtCarts = $this->db->prepare("SELECT CartID FROM `$cartTable` WHERE UserID = ?");
                $stmtCarts->execute([$userId]);
                $cartIds = $stmtCarts->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($cartIds)) {
                    $in = implode(",", array_fill(0, count($cartIds), "?"));
                    $this->db->prepare("DELETE FROM `cart_item` WHERE CartID IN ($in)")->execute($cartIds);
                    $this->db->prepare("DELETE FROM `$cartTable` WHERE UserID = ?")->execute([$userId]);
                }
            } catch (Throwable $ignored) {
            }
        }
        foreach (["verification_token", "kyc_verification", "auth_identity", "user_address", "user_profile", "user_role"] as $table) {
            $stmt = $this->db->prepare("DELETE FROM `$table` WHERE UserID = ?");
            $stmt->execute([$userId]);
        }
        $stmt = $this->db->prepare("DELETE FROM `user` WHERE UserID = ?");
        $stmt->execute([$userId]);
        $this->db->commit();
    }

    // ------------------------------------------------------------
    // Verification codes and password reset
    // ------------------------------------------------------------

    // Creates a code for verify_email, verify_phone or reset_password.
    // Returns the code, or null when there is nothing to send (unknown user, suspended user, already verified).
    // The API gives the same answer in both cases, so nobody can find out which emails exist.
    public function sendToken($identifier, $purpose): ?string
    {
        $purpose = Validator::oneOf($purpose, VerificationToken::PURPOSES, "Purpose");
        $identifier = Validator::text($identifier, "Email or phone number", 191);

        $row = $this->findByIdentifier($identifier);
        if (!$row || $row["Status"] !== self::ACTIVE) {
            return null;
        }
        if ($purpose === "verify_email" && $row["EmailVerifiedAt"] !== null) {
            return null;
        }
        if ($purpose === "verify_phone" && ($row["PhoneNumber"] === "" || $row["PhoneVerifiedAt"] !== null)) {
            return null;
        }
        return $this->tokens->create((int)$row["UserID"], $purpose);
    }

    // A correct code marks the email or phone as verified. Returns the user.
    public function verifyContact($identifier, $purpose, $token): array
    {
        $purpose = Validator::oneOf($purpose, ["verify_email", "verify_phone"], "Purpose");
        $identifier = Validator::text($identifier, "Email or phone number", 191);
        $token = Validator::text($token, "Code", 100);

        $row = $this->findByIdentifier($identifier);
        if (!$row) {
            throw new InvalidArgumentException("The code is not correct.");
        }
        $this->tokens->verify((int)$row["UserID"], $purpose, $token);

        $column = ($purpose === "verify_email") ? "EmailVerifiedAt" : "PhoneVerifiedAt";
        $stmt = $this->db->prepare("UPDATE `user` SET $column = NOW() WHERE UserID = ?");
        $stmt->execute([$row["UserID"]]);

        return $this->getById((int)$row["UserID"]);
    }

    // A reset token lets the user set a new password. The old password stops working.
    public function resetPassword($identifier, $token, $newPassword): void
    {
        $identifier = Validator::text($identifier, "Email or phone number", 191);
        $token = Validator::text($token, "Token", 200);
        $newPassword = Validator::password($newPassword);

        $row = $this->findByIdentifier($identifier);
        if (!$row) {
            throw new InvalidArgumentException("The code is not correct.");
        }
        $this->tokens->verify((int)$row["UserID"], "reset_password", $token);
        $this->savePassword((int)$row["UserID"], $newPassword);
    }

    // Resets password when only the reset token is provided (as in reset-password.html?token=...).
    public function resetPasswordByToken($token, $newPassword): void
    {
        $token = Validator::text($token, "Token", 200);
        $newPassword = Validator::password($newPassword);

        $check = $this->tokens->checkResetToken($token);
        if (!$check["ok"]) {
            $messages = [
                "invalid" => "This reset link is not valid.",
                "expired" => "This reset link has expired.",
                "used"    => "This reset link was already used."
            ];
            throw new InvalidArgumentException($messages[$check["reason"]] ?? "This reset link is not valid.");
        }
        $userId = (int)$check["UserID"];
        $this->tokens->verify($userId, "reset_password", $token);
        $this->savePassword($userId, $newPassword);
    }

    // Changes email or phone after verifying the code sent to the current user.
    public function changeVerifiedContact(int $userId, string $type, string $newValue, string $code): array
    {
        $type = strtolower(trim($type));
        if ($type === "email") {
            $cleanVal = Validator::email($newValue);
            $this->requireEmailFree($cleanVal, $userId);
            $this->tokens->verify($userId, "verify_email", $code);
            $stmt = $this->db->prepare("UPDATE `user` SET Email = ?, EmailVerifiedAt = NOW() WHERE UserID = ?");
            $stmt->execute([$cleanVal, $userId]);
        } elseif ($type === "phone") {
            $cleanVal = Validator::phone($newValue);
            $this->requirePhoneFree($cleanVal, $userId);
            $this->tokens->verify($userId, "verify_phone", $code);
            $stmt = $this->db->prepare("UPDATE `user` SET PhoneNumber = ?, PhoneVerifiedAt = NOW() WHERE UserID = ?");
            $stmt->execute([$cleanVal, $userId]);
        } else {
            throw new InvalidArgumentException("Type must be email or phone.");
        }
        return $this->getById($userId);
    }

    // Validates a proposed new email or phone before sending a verification code.
    public function validateNewContact(int $userId, string $type, string $newValue): string
    {
        $user = $this->getById($userId);
        if (!$user) {
            throw new OutOfBoundsException("User not found.");
        }
        $type = strtolower(trim($type));
        if ($type === "email") {
            $cleanVal = Validator::email($newValue);
            if ($cleanVal === strtolower($user["Email"])) {
                throw new InvalidArgumentException("This is already your email.");
            }
            $this->requireEmailFree($cleanVal, $userId);
            return $cleanVal;
        }
        if ($type === "phone") {
            $cleanVal = Validator::phone($newValue);
            if ($cleanVal === $user["PhoneNumber"]) {
                throw new InvalidArgumentException("This is already your phone number.");
            }
            $this->requirePhoneFree($cleanVal, $userId);
            return $cleanVal;
        }
        throw new InvalidArgumentException("Type must be email or phone.");
    }

    // ------------------------------------------------------------
    // Admin actions
    // ------------------------------------------------------------

    // Suspends a user. A reason is needed. An admin cannot suspend their own account.
    public function suspend(int $userId, $reason, int $actingUserId): array
    {
        $user = $this->getById($userId);
        if ($user === null) {
            throw new OutOfBoundsException("User not found.");
        }
        $reason = Validator::text($reason, "Reason", 1000);
        if ($userId === $actingUserId) {
            throw new InvalidArgumentException("You cannot suspend your own account.");
        }
        if ($user["Status"] === self::SUSPENDED) {
            throw new InvalidArgumentException("This user is already suspended.");
        }

        $stmt = $this->db->prepare("UPDATE `user` SET Status = ?, SuspensionReason = ? WHERE UserID = ?");
        $stmt->execute([self::SUSPENDED, $reason, $userId]);
        return $this->getById($userId);
    }

    // Lets a suspended user in again.
    public function reactivate(int $userId): array
    {
        $user = $this->getById($userId);
        if ($user === null) {
            throw new OutOfBoundsException("User not found.");
        }
        if ($user["Status"] !== self::SUSPENDED) {
            throw new InvalidArgumentException("This user is not suspended.");
        }

        $stmt = $this->db->prepare("UPDATE `user` SET Status = ?, SuspensionReason = NULL WHERE UserID = ?");
        $stmt->execute([self::ACTIVE, $userId]);
        return $this->getById($userId);
    }

    // ------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------

    private function insertUser(string $first, ?string $middle, string $last, string $email, string $phone, ?string $hash, bool $emailVerified): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `user` (FirstName, MiddleName, LastName, Email, PhoneNumber, PasswordHash, EmailVerifiedAt, Status)
             VALUES (?, ?, ?, ?, ?, ?, " . ($emailVerified ? "NOW()" : "NULL") . ", ?)"
        );
        $stmt->execute([$first, $middle, $last, $email, $phone, $hash, self::ACTIVE]);
        return (int)$this->db->lastInsertId();
    }

    // The full row WITH PasswordHash. Used only inside this class. It is never returned to the API.
    private function findRowById(int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM `user` WHERE UserID = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    // Finds a user by email (when it has an @) or by phone number.
    private function findByIdentifier(string $identifier): ?array
    {
        if (strpos($identifier, "@") !== false) {
            $stmt = $this->db->prepare("SELECT * FROM `user` WHERE Email = ? ORDER BY UserID LIMIT 1");
            $stmt->execute([strtolower(trim($identifier))]);
            return $stmt->fetch() ?: null;
        }

        $phone = Validator::normalizePhone($identifier);
        if ($phone === null) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM `user` WHERE PhoneNumber = ? ORDER BY UserID LIMIT 1");
        $stmt->execute([$phone]);
        return $stmt->fetch() ?: null;
    }

    private function requireActive(array $row): void
    {
        if ($row["Status"] !== self::ACTIVE) {
            throw new ForbiddenException("This account is suspended. Please contact KameraPH support.");
        }
    }

    // Saves LastLoginAt and returns the safe user.
    private function markLoggedIn(int $userId): array
    {
        $stmt = $this->db->prepare("UPDATE `user` SET LastLoginAt = NOW() WHERE UserID = ?");
        $stmt->execute([$userId]);
        return $this->getById($userId);
    }

    private function savePassword(int $userId, string $newPassword): void
    {
        $stmt = $this->db->prepare("UPDATE `user` SET PasswordHash = ? WHERE UserID = ?");
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    }

    private function currentValue(int $userId, string $column): ?string
    {
        $stmt = $this->db->prepare("SELECT $column FROM `user` WHERE UserID = ?");
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    private function requireEmailFree(string $email, int $exceptUserId): void
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `user` WHERE Email = ? AND UserID <> ?");
        $stmt->execute([$email, $exceptUserId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException("This email is already registered.");
        }
    }

    private function requirePhoneFree(string $phone, int $exceptUserId): void
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `user` WHERE PhoneNumber = ? AND UserID <> ?");
        $stmt->execute([$phone, $exceptUserId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException("This phone number is already registered.");
        }
    }

    private function format(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row["UserID"] = (int)$row["UserID"];
        return $row;
    }
}
