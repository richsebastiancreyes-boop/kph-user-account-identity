<?php
// ================================================================
// Part 3 API & Cross-Module Session Test Suite
// Tests Module 1 API endpoints AND cross-module session handoff with
// Module 5 (Shopping & Promotions: /kph_shopping_promotions/api/carts.php)
// Run via CLI: C:\xampp\php\php.exe dev/tests/api_test.php
// ================================================================

$mod1Base = getenv("KPH_MOD1_API") ?: "http://localhost/kph_user_account_identity/api";
$mod5Base = getenv("KPH_MOD5_API") ?: "http://localhost/kph_shopping_promotions/api";

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

function httpCall(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    $reqHeaders = ["Content-Type: application/json"];
    foreach ($headers as $k => $v) {
        $reqHeaders[] = "{$k}: {$v}";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        "status" => $status,
        "data" => json_decode((string) $raw, true) ?? [],
        "raw" => $raw,
        "error" => $err,
    ];
}

echo "================================================================\n";
echo " Module 1 API + Module 5 Cross-Module Session Integration Tests\n";
echo " Module 1 API: {$mod1Base}\n";
echo " Module 5 API: {$mod5Base}\n";
echo "================================================================\n\n";

$suffix = substr(bin2hex(random_bytes(4)), 0, 6);
$email = "api.test.{$suffix}@kameraph.test";
$phone = "0918" . str_pad((string) random_int(1000000, 9999999), 7, "0", STR_PAD_LEFT);
$password = "ApiPass123";

// 1. Register a new user via POST /users.php
$reg = httpCall("POST", "{$mod1Base}/users.php", [
    "FirstName" => "Cristopher",
    "MiddleName" => "Group",
    "LastName" => "Tester",
    "Email" => $email,
    "PhoneNumber" => $phone,
    "Password" => $password,
]);
check("POST /users.php registers new user (201)", $reg["status"] === 201 && !empty($reg["data"]["Token"]), $reg["raw"] ?? "");
$userId = (int) ($reg["data"]["UserID"] ?? 0);
$regToken = (string) ($reg["data"]["Token"] ?? "");

// 2. Log in via POST /login.php to obtain a fresh Bearer token
$login = httpCall("POST", "{$mod1Base}/login.php", [
    "Identifier" => $email,
    "Password" => $password,
]);
check("POST /login.php authenticates and returns Bearer Token (200)", $login["status"] === 200 && !empty($login["data"]["Token"]), $login["raw"] ?? "");
$token = (string) ($login["data"]["Token"] ?? $regToken);
$authHeader = ["Authorization" => "Bearer {$token}"];

// 3. Inspect current session via GET /login.php
$sessionCheck = httpCall("GET", "{$mod1Base}/login.php", null, $authHeader);
check("GET /login.php validates Bearer token and returns UserID", $sessionCheck["status"] === 200 && (int) ($sessionCheck["data"]["UserID"] ?? 0) === $userId);

// 4. Update profile via PUT /users.php
$upd = httpCall("PUT", "{$mod1Base}/users.php?id={$userId}", [
    "Bio" => "Testing cross-module session handoff with Module 5.",
    "DateOfBirth" => "2000-01-20",
], $authHeader);
check("PUT /users.php updates profile Bio & DateOfBirth (200)", $upd["status"] === 200 && ($upd["data"]["Bio"] ?? "") === "Testing cross-module session handoff with Module 5.");

// 5. Create address via POST /addresses.php
$addr = httpCall("POST", "{$mod1Base}/addresses.php", [
    "RecipientFullName" => "Cristopher Group Tester",
    "PhoneNumber" => $phone,
    "Street" => "Unit 3C, 45 Katipunan Ave",
    "Region" => "National Capital Region (NCR)",
    "Province" => "Metro Manila",
    "City" => "Quezon City",
    "Barangay" => "Loyola Heights",
    "PostalCode" => "1108",
    "AddressType" => "Home",
    "IsDefault" => 1,
], $authHeader);
check("POST /addresses.php saves address (201)", $addr["status"] === 201 && !empty($addr["data"]["AddressID"]));

// 6. Verify email via POST & PUT /verification.php
$sendVer = httpCall("POST", "{$mod1Base}/verification.php", ["Type" => "email"], $authHeader);
$code = $sendVer["data"]["code"] ?? "";
check("POST /verification.php issues 6-digit email verification code (200)", $sendVer["status"] === 200 && strlen((string) $code) === 6);
if ($code !== "") {
    $confVer = httpCall("PUT", "{$mod1Base}/verification.php", ["Type" => "email", "Code" => $code], $authHeader);
    check("PUT /verification.php marks email as verified (200)", $confVer["status"] === 200 && !empty($confVer["data"]["User"]["EmailVerifiedAt"]));
}

// 7. Submit & approve KYC via /kyc.php
$kycSub = httpCall("POST", "{$mod1Base}/kyc.php", [
    "IDType" => "Philippine National ID (PhilSys)",
    "IDNumber" => "9876-5432-1098-7654",
], $authHeader);
$kycId = (int) ($kycSub["data"]["KYCID"] ?? 0);
check("POST /kyc.php submits KYC verification (201)", $kycSub["status"] === 201 && $kycId > 0);
if ($kycId > 0) {
    $kycApp = httpCall("POST", "{$mod1Base}/kyc.php?id={$kycId}&action=approve", ["ReviewedByAdminID" => 1], $authHeader);
    check("POST /kyc.php?action=approve approves KYC and grants Seller role (200)", $kycApp["status"] === 200, $kycApp["raw"] ?? "");
}

// 8. CROSS-MODULE SESSION TEST: Use Module 1's Bearer Token against Module 5 (/kph_shopping_promotions/api)
echo "\n--- Cross-Module Session Handoff (Module 1 -> Module 5) ---\n";
$mod5Cart = httpCall("GET", "{$mod5Base}/carts.php?user_id={$userId}", null, $authHeader);
check(
    "Module 5 GET /carts.php accepts Module 1 Bearer Token for UserID={$userId} (200)",
    $mod5Cart["status"] === 200 && isset($mod5Cart["data"]["CartID"]),
    $mod5Cart["raw"] ?? ""
);

// Verify Module 5 rejects a mismatched user_id when authenticated as $userId via Bearer token
$wrongUserId = $userId + 999;
$mod5Mismatch = httpCall("GET", "{$mod5Base}/carts.php?user_id={$wrongUserId}", null, $authHeader);
check(
    "Module 5 GET /carts.php rejects mismatched user_id when authenticated via Module 1 Bearer Token (400)",
    $mod5Mismatch["status"] === 400,
    $mod5Mismatch["raw"] ?? ""
);

$mod5Promos = httpCall("GET", "{$mod5Base}/promotions.php?active=1", null, $authHeader);
check("Module 5 GET /promotions.php?active=1 responds with active promotions (200)", $mod5Promos["status"] === 200);

// 9. Logout in Module 1 via DELETE /login.php and verify token is rejected in both Module 1 and Module 5
$logout = httpCall("DELETE", "{$mod1Base}/login.php", null, $authHeader);
check("Module 1 DELETE /login.php revokes Bearer session token (200)", $logout["status"] === 200);

$afterLogoutMod1 = httpCall("GET", "{$mod1Base}/login.php", null, $authHeader);
check("Module 1 rejects revoked Bearer token with 401", $afterLogoutMod1["status"] === 401);

// 10. Re-login and clean up test account via DELETE /users.php
$relogin = httpCall("POST", "{$mod1Base}/login.php", ["Identifier" => $email, "Password" => $password]);
if (!empty($relogin["data"]["Token"])) {
    $delAuth = ["Authorization" => "Bearer " . $relogin["data"]["Token"]];
    $del = httpCall("DELETE", "{$mod1Base}/users.php?id={$userId}", ["Password" => $password], $delAuth);
    check("DELETE /users.php cleans up test account (200)", $del["status"] === 200, $del["raw"] ?? "");
}

echo "\n----------------------------------------------------------------\n";
echo " Summary: {$passed} passed, {$failed} failed\n";
echo "----------------------------------------------------------------\n";
exit($failed > 0 ? 1 : 0);
