<?php
// ================================================================
// API Endpoint: /api/social.php
// Module 1: User Account & Identity
// ================================================================

require_once __DIR__ . "/helpers.php";

$userModel = new User();
$identityModel = new AuthIdentity();
$tokenModel = new VerificationToken();

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

try {
    if ($method === "GET") {
        $me = requireUser();
        $targetId = isset($_GET["user_id"]) && $_GET["user_id"] !== "" ? (int) $_GET["user_id"] : (int) $me["UserID"];
        if ($targetId !== (int) $me["UserID"] && !isAdminUser($me) && !hasModuleKey()) {
            respond(403, ["error" => "Access denied."]);
        }
        respond(200, $identityModel->getByUser($targetId));
    }

    if ($method === "POST") {
        $body = jsonBody();
        $provider = trim((string) ($body["Provider"] ?? ""));
        $email = isset($body["Email"]) ? strtolower(trim((string) $body["Email"])) : "";

        // If the frontend simulated OAuth popup did not send ProviderUserID, derive a deterministic one
        if (empty($body["ProviderUserID"]) && $provider !== "" && $email !== "") {
            $body["ProviderUserID"] = strtolower($provider) . "_" . substr(hash("sha256", strtolower($provider) . ":" . $email), 0, 24);
        }

        $res = $userModel->socialLogin($body);
        $loggedInUser = $res["User"];
        $isNew = (bool) $res["IsNewUser"];
        $userId = (int) $loggedInUser["UserID"];

        $sessionToken = $tokenModel->createSession($userId);
        $view = $userModel->getAccountView($userId) ?? $loggedInUser;

        respond($isNew ? 201 : 200, [
            "message" => $isNew ? "Account created via " . ucfirst(strtolower($provider)) : "Signed in with " . ucfirst(strtolower($provider)),
            "UserID" => $userId,
            "Token" => $sessionToken,
            "IsNewUser" => $isNew,
            "User" => $view,
        ]);
    }

    respond(405, ["error" => "Method not allowed."]);
} catch (Throwable $e) {
    handleApiException($e);
}
