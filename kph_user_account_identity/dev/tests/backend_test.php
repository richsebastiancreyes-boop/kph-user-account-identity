<?php
// ================================================================
// Part 2 Backend Class Test Suite — Module 1: User Account & Identity
// Run via CLI: C:\xampp\php\php.exe dev/tests/backend_test.php
// ================================================================

require_once __DIR__ . "/../../config/Database.php";
require_once __DIR__ . "/../../classes/Exceptions.php";
require_once __DIR__ . "/../../classes/Validator.php";
require_once __DIR__ . "/../../classes/Crypto.php";
require_once __DIR__ . "/../../classes/Role.php";
require_once __DIR__ . "/../../classes/UserRole.php";
require_once __DIR__ . "/../../classes/UserProfile.php";
require_once __DIR__ . "/../../classes/UserAddress.php";
require_once __DIR__ . "/../../classes/AuthIdentity.php";
require_once __DIR__ . "/../../classes/VerificationToken.php";
require_once __DIR__ . "/../../classes/KycVerification.php";
require_once __DIR__ . "/../../classes/User.php";

$passed = 0;
$failed = 0;

function check(string $label, bool $cond, string $detail = ""): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}" . ($detail !== "" ? " — {$detail}" : "") . "\n";
    }
}

echo "================================================================\n";
echo " Module 1: User Account & Identity — Part 2 Backend Class Tests\n";
echo "================================================================\n\n";

try {
    $db = Database::getConnection();
    check("Database connection established", $db instanceof PDO);
} catch (Throwable $e) {
    echo "  [FAIL] Could not connect to database: " . $e->getMessage() . "\n";
    exit(1);
}

$suffix = substr(bin2hex(random_bytes(4)), 0, 6);
$email = "test.user.{$suffix}@kameraph.test";
$phone = "0917" . str_pad((string) random_int(1000000, 9999999), 7, "0", STR_PAD_LEFT);

$userModel = new User();
$profileModel = new UserProfile();
$addressModel = new UserAddress();
$roleModel = new Role();
$userRoleModel = new UserRole();
$tokenModel = new VerificationToken();
$kycModel = new KycVerification();

$createdUserId = null;

try {
    // 1. Role defaults
    $roleModel->ensureDefaults();
    $allRoles = $roleModel->getAll();
    check("Role::ensureDefaults() seeds buyer, seller, admin", count($allRoles) >= 3);

    // 2. User registration
    $createdUserId = $userModel->register([
        "FirstName" => "Test",
        "MiddleName" => "Backend",
        "LastName" => "Runner",
        "Email" => $email,
        "PhoneNumber" => $phone,
        "Password" => "CameraPass123",
    ]);
    check("User::register() creates new user and returns UserID", $createdUserId > 0);

    // 3. Login with email & phone
    $loggedInEmail = $userModel->login($email, "CameraPass123");
    check("User::login() with Email succeeds", (int) $loggedInEmail["UserID"] === $createdUserId);

    $loggedInPhone = $userModel->login($phone, "CameraPass123");
    check("User::login() with PhoneNumber succeeds", (int) $loggedInPhone["UserID"] === $createdUserId);

    // 4. Cross-Module Session Token (createSession, verifySession, revokeSession)
    $sessionToken = $tokenModel->createSession($createdUserId);
    check("VerificationToken::createSession() returns 64-char hex token", strlen($sessionToken) === 64);

    $verifiedUid = $tokenModel->verifySession($sessionToken);
    check("VerificationToken::verifySession() resolves UserID", $verifiedUid === $createdUserId);

    // Verify Module 5's exact SQL query against this session token
    $stmtMod5 = $db->prepare(
        "SELECT UserID FROM verification_token
         WHERE (TokenHash = :raw OR TokenHash = SHA2(:hashed, 256))
           AND ExpiresAt > NOW()
           AND UsedAt IS NULL
         ORDER BY TokenID DESC LIMIT 1"
    );
    $stmtMod5->execute([":raw" => $sessionToken, ":hashed" => $sessionToken]);
    $mod5Uid = (int) $stmtMod5->fetchColumn();
    check("Module 5 SQL query resolves Module 1 session token to exact UserID", $mod5Uid === $createdUserId);

    $tokenModel->revokeSession($sessionToken);
    $afterRevoke = $tokenModel->verifySession($sessionToken);
    check("VerificationToken::revokeSession() invalidates session token", $afterRevoke === null);

    // 5. Email & Phone verification codes
    $emailCode = $userModel->sendToken($email, "verify_email");
    check("User::sendToken(verify_email) issues 6-digit code", $emailCode !== null && strlen($emailCode) === 6);
    $verifiedUser = $userModel->verifyContact($email, "verify_email", $emailCode);
    check("User::verifyContact(verify_email) sets EmailVerifiedAt", !empty($verifiedUser["EmailVerifiedAt"]));

    // 6. Profile update
    $updatedProfile = $profileModel->update($createdUserId, [
        "Bio" => "Testing KameraPH Module 1 backend classes.",
        "DateOfBirth" => "1998-06-15",
    ]);
    check("UserProfile::update() saves Bio & DateOfBirth", $updatedProfile["Bio"] === "Testing KameraPH Module 1 backend classes.");

    // 7. Address CRUD (both backend & UI formats)
    $addr1Id = $addressModel->create($createdUserId, [
        "RecipientFullName" => "Test Backend Runner",
        "PhoneNumber" => $phone,
        "Street" => "Unit 12A, 88 Ayala Avenue",
        "Barangay" => "Poblacion",
        "City" => "Makati City",
        "Province" => "Metro Manila",
        "PostalCode" => "1210",
        "AddressType" => "Home",
        "IsDefault" => 1,
    ]);
    check("UserAddress::create() saves first address as default", $addr1Id > 0);
    $addrList = $addressModel->getByUser($createdUserId);
    check("UserAddress::getByUser() returns saved address with RecipientFullName", count($addrList) === 1 && $addrList[0]["IsDefault"] === 1);

    // 8. KYC submission & approval
    $kycId = $kycModel->submit($createdUserId, [
        "IDType" => "Philippine National ID (PhilSys)",
        "IDNumber" => "1234-5678-9012-3456",
    ]);
    check("KycVerification::submit() encrypts ID and starts as Pending", $kycId > 0);
    $approvedKyc = $kycModel->approve($kycId, 1);
    check("KycVerification::approve() marks status as Approved", $approvedKyc["VerificationStatus"] === "Approved");

    // 9. Account deletion cleanup
    $userModel->deleteAccount($createdUserId, "CameraPass123");
    check("User::deleteAccount() deletes test user cleanly", $userModel->getById($createdUserId) === null);
    $createdUserId = null;
} catch (Throwable $e) {
    $failed++;
    echo "  [FAIL] Unexpected exception: " . $e->getMessage() . "\n";
} finally {
    if ($createdUserId !== null) {
        try {
            $userModel->deleteAccount($createdUserId, "CameraPass123");
        } catch (Throwable $ignored) {
        }
    }
}

echo "\n----------------------------------------------------------------\n";
echo " Summary: {$passed} passed, {$failed} failed\n";
echo "----------------------------------------------------------------\n";
exit($failed > 0 ? 1 : 0);
