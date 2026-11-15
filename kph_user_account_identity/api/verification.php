<?php
// ================================================================
// API Endpoint: /api/verification.php
// Module 1: User Account & Identity
// ================================================================

require_once __DIR__ . "/helpers.php";

$userModel = new User();
$config = Database::getConfig();

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

try {
    if ($method === "POST") {
        $body = jsonBody();
        $me = currentUser();

        $type = isset($body["Type"]) ? strtolower(trim((string) $body["Type"])) : null;
        $purpose = $body["Purpose"] ?? null;
        $identifier = $body["Identifier"] ?? null;

        if ($type !== null) {
            if ($me === null) {
                respond(401, ["error" => "Authentication required."]);
            }
            if ($type === "email") {
                $purpose = "verify_email";
                $identifier = $me["Email"] ?? null;
                if (!$identifier) {
                    respond(400, ["error" => "Your account does not have an email address yet."]);
                }
            } elseif ($type === "phone") {
                $purpose = "verify_phone";
                $identifier = $me["PhoneNumber"] ?? null;
                if (!$identifier) {
                    respond(400, ["error" => "Your account does not have a phone number yet."]);
                }
            } else {
                respond(400, ["error" => "Type must be email or phone."]);
            }
        }

        if (!$identifier || !$purpose) {
            respond(400, ["error" => "Type (email/phone) or Identifier and Purpose are required."]);
        }

        $rawToken = $userModel->sendToken($identifier, $purpose);
        $masked = $purpose === "verify_email" ? maskEmail($identifier) : ($purpose === "verify_phone" ? maskPhone($identifier) : $identifier);
        $minutes = $config["token_minutes"][$purpose] ?? 15;

        $out = [
            "message" => "Verification code sent.",
            "to" => $masked,
            "minutes" => $minutes,
        ];
        if ($rawToken !== null && !empty($config["show_token_in_response"])) {
            $out["code"] = $rawToken;
            $out["Token"] = $rawToken;
        }
        respond(200, $out);
    }

    if ($method === "PUT") {
        $body = jsonBody();
        $me = currentUser();

        $type = isset($body["Type"]) ? strtolower(trim((string) $body["Type"])) : null;
        $code = $body["Code"] ?? $body["Token"] ?? null;
        $identifier = $body["Identifier"] ?? null;
        $purpose = $body["Purpose"] ?? null;

        if ($type !== null) {
            if ($me === null) {
                respond(401, ["error" => "Authentication required."]);
            }
            if ($type === "email") {
                $identifier = $me["Email"] ?? null;
                $purpose = "verify_email";
            } elseif ($type === "phone") {
                $identifier = $me["PhoneNumber"] ?? null;
                $purpose = "verify_phone";
            } else {
                respond(400, ["error" => "Type must be email or phone."]);
            }
        }

        if (!$identifier || !$purpose || !$code) {
            respond(400, ["error" => "Verification code and target contact are required."]);
        }

        $updated = $userModel->verifyContact($identifier, $purpose, (string) $code);
        $accountView = $userModel->getAccountView((int) $updated["UserID"]);

        respond(200, [
            "message" => "Verification successful.",
            "User" => $accountView,
        ]);
    }

    respond(405, ["error" => "Method not allowed."]);
} catch (Throwable $e) {
    handleApiException($e);
}
