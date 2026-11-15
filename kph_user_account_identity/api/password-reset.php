<?php
// ================================================================
// API Endpoint: /api/password-reset.php
// Module 1: User Account & Identity
// ================================================================

require_once __DIR__ . "/helpers.php";

$userModel = new User();
$tokenModel = new VerificationToken();
$config = Database::getConfig();

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

try {
    if ($method === "GET") {
        $token = trim((string) ($_GET["token"] ?? ""));
        $check = $tokenModel->checkResetToken($token);
        respond(200, [
            "ok" => $check["ok"],
            "reason" => $check["reason"] ?? null,
        ]);
    }

    if ($method === "POST") {
        $body = jsonBody();
        $identifier = trim((string) ($body["Identifier"] ?? $body["Email"] ?? $body["PhoneNumber"] ?? ""));
        if ($identifier === "") {
            respond(400, ["error" => "Email or phone number is required."]);
        }

        $rawToken = $userModel->sendToken($identifier, "reset_password");
        $out = [
            "message" => "If an account exists, a reset link was sent.",
            "found" => $rawToken !== null,
        ];
        if ($rawToken !== null && !empty($config["show_token_in_response"])) {
            $out["token"] = $rawToken;
            $out["Token"] = $rawToken;
        }
        respond(200, $out);
    }

    if ($method === "PUT") {
        $body = jsonBody();
        $token = $body["Token"] ?? null;
        $newPassword = $body["Password"] ?? $body["NewPassword"] ?? null;
        $identifier = $body["Identifier"] ?? null;

        if ($identifier !== null && trim((string) $identifier) !== "") {
            $userModel->resetPassword($identifier, (string) $token, $newPassword);
        } else {
            $userModel->resetPasswordByToken($token, $newPassword);
        }

        respond(200, ["message" => "Password has been reset successfully."]);
    }

    respond(405, ["error" => "Method not allowed."]);
} catch (Throwable $e) {
    handleApiException($e);
}
