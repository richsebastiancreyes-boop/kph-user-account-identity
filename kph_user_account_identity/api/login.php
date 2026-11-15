<?php
// ================================================================
// API Endpoint: /api/login.php
// Module 1: User Account & Identity
// Supports:
//   POST   /api/login.php   -> Authenticate user, issue session token
//   GET    /api/login.php   -> Inspect current session user
//   DELETE /api/login.php   -> Revoke current session token (logout)
// ================================================================

require_once __DIR__ . "/helpers.php";

$userModel = new User();
$tokenModel = new VerificationToken();

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
$action = $_GET["action"] ?? null;

try {
    if ($method === "GET") {
        $me = requireUser();
        $view = $userModel->getAccountView((int) $me["UserID"]);
        respond(200, [
            "authenticated" => true,
            "UserID" => (int) $me["UserID"],
            "User" => $view,
        ]);
    }

    if ($method === "DELETE" || ($method === "POST" && $action === "logout")) {
        $token = getBearerToken();
        $body = $method === "POST" ? jsonBody() : [];
        if ($token === null && isset($body["Token"])) {
            $token = trim((string) $body["Token"]);
        }
        if ($token !== null && $token !== "") {
            $tokenModel->revokeSession($token);
        }
        respond(200, [
            "message" => "Logged out.",
        ]);
    }

    if ($method === "POST") {
        $body = jsonBody();
        $identifier = $body["Identifier"] ?? $body["Email"] ?? $body["PhoneNumber"] ?? null;
        $password = $body["Password"] ?? null;

        $loggedIn = $userModel->login($identifier, $password);
        $userId = (int) $loggedIn["UserID"];

        $sessionToken = $tokenModel->createSession($userId);
        $accountView = $userModel->getAccountView($userId) ?? $loggedIn;

        respond(200, [
            "message" => "Login successful.",
            "UserID" => $userId,
            "Token" => $sessionToken,
            "User" => $accountView,
        ]);
    }

    respond(405, ["error" => "Method not allowed."]);
} catch (Throwable $e) {
    handleApiException($e);
}
