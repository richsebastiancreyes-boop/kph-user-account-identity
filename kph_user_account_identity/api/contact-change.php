<?php
// ================================================================
// API Endpoint: /api/contact-change.php
// Module 1: User Account & Identity
// ================================================================

require_once __DIR__ . "/helpers.php";

$userModel = new User();
$tokenModel = new VerificationToken();
$config = Database::getConfig();

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

try {
    $me = requireUser();
    $userId = (int) $me["UserID"];

    if ($method === "POST") {
        $body = jsonBody();
        $type = strtolower(trim((string) ($body["Type"] ?? "")));
        $value = trim((string) ($body["Value"] ?? ""));

        $cleanValue = $userModel->validateNewContact($userId, $type, $value);
        $purpose = $type === "email" ? "verify_email" : "verify_phone";
        $rawCode = $tokenModel->create($userId, $purpose);

        $masked = $type === "email" ? maskEmail($cleanValue) : maskPhone($cleanValue);

        $out = [
            "message" => "Verification code sent to new " . $type . ".",
            "to" => $masked,
            "value" => $cleanValue,
            "minutes" => $config["token_minutes"][$purpose] ?? 15,
        ];
        if (!empty($config["show_token_in_response"])) {
            $out["code"] = $rawCode;
        }

        respond(200, $out);
    }

    if ($method === "PUT") {
        $body = jsonBody();
        $type = strtolower(trim((string) ($body["Type"] ?? "")));
        $value = trim((string) ($body["Value"] ?? ""));
        $code = trim((string) ($body["Code"] ?? $body["Token"] ?? ""));

        $userModel->changeVerifiedContact($userId, $type, $value, $code);
        $updated = $userModel->getAccountView($userId);

        respond(200, [
            "message" => ucfirst($type) . " updated and verified.",
            "User" => $updated,
        ]);
    }

    respond(405, ["error" => "Method not allowed."]);
} catch (Throwable $e) {
    handleApiException($e);
}
