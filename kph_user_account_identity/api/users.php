<?php
// ================================================================
// API Endpoint: /api/users.php
// Module 1: User Account & Identity
// ================================================================

require_once __DIR__ . "/helpers.php";

$userModel = new User();
$profileModel = new UserProfile();
$tokenModel = new VerificationToken();

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
$rawId = $_GET["id"] ?? null;
$id = ($rawId !== null && $rawId !== "" && $rawId !== "null" && $rawId !== "undefined") ? (int) $rawId : null;
$action = $_GET["action"] ?? null;

try {
    if ($method === "GET") {
        if (isset($_GET["all"]) || isset($_GET["status"]) || isset($_GET["search"])) {
            $rows = $userModel->getAll($_GET);
            $me = currentUser();
            $privileged = hasModuleKey() || isAdminUser($me);
            if (!$privileged) {
                $rows = array_map(fn($r) => $userModel->toPublic($r), $rows);
            }
            respond(200, $rows);
        }

        $me = currentUser();
        $targetId = $id ?? ($me ? (int) $me["UserID"] : null);

        if ($targetId === null) {
            $rows = $userModel->getAll($_GET);
            $privileged = hasModuleKey();
            if (!$privileged) {
                $rows = array_map(fn($r) => $userModel->toPublic($r), $rows);
            }
            respond(200, $rows);
        }

        $view = $userModel->getAccountView($targetId);
        if (!$view) {
            respond(404, ["error" => "User not found."]);
        }

        $isOwner = $me !== null && (int) $me["UserID"] === $targetId;
        $privileged = $isOwner || hasModuleKey() || isAdminUser($me);

        if ($privileged) {
            respond(200, $view);
        }

        $pub = $userModel->toPublic($view);
        $pub["Username"] = $view["Username"] ?? null;
        $pub["Bio"] = $view["Bio"] ?? null;
        $pub["ProfilePictureURL"] = $view["ProfilePictureURL"] ?? null;
        $pub["Role"] = $view["Role"] ?? "Buyer";
        $pub["Roles"] = $view["Roles"] ?? [];
        $pub["VerificationStatus"] = $view["VerificationStatus"] ?? "Not verified";
        respond(200, $pub);
    }

    if ($method === "POST") {
        $body = jsonBody();

        if ($action === "change-password") {
            $me = requireUser();
            $targetId = $id ?? (int) $me["UserID"];
            if ($targetId !== (int) $me["UserID"] && !isAdminUser($me)) {
                respond(403, ["error" => "You can only change your own password."]);
            }
            $currentPwd = $body["CurrentPassword"] ?? null;
            $newPwd = $body["NewPassword"] ?? $body["Password"] ?? null;
            $userModel->changePassword($targetId, $currentPwd, $newPwd);
            respond(200, ["message" => "Password changed successfully."]);
        }

        if ($action === "suspend") {
            if ($id === null) {
                respond(400, ["error" => "User id is required."]);
            }
            $me = currentUser();
            if ($me === null && !hasModuleKey()) {
                respond(401, ["error" => "Authentication required."]);
            }
            $actingId = $me ? (int) $me["UserID"] : (int) ($body["ActingUserID"] ?? 1);
            $userModel->suspend($id, $body["Reason"] ?? null, $actingId);
            respond(200, [
                "message" => "Account suspended.",
                "User" => $userModel->getAccountView($id),
            ]);
        }

        if ($action === "reactivate") {
            if ($id === null) {
                respond(400, ["error" => "User id is required."]);
            }
            $me = currentUser();
            if ($me === null && !hasModuleKey()) {
                respond(401, ["error" => "Authentication required."]);
            }
            $userModel->reactivate($id);
            respond(200, [
                "message" => "Account reactivated.",
                "User" => $userModel->getAccountView($id),
            ]);
        }

        // Default POST: Register a new user
        $newId = $userModel->register($body);
        $sessionToken = $tokenModel->createSession($newId);
        $view = $userModel->getAccountView($newId);

        respond(201, [
            "message" => "Account registered successfully.",
            "UserID" => $newId,
            "Token" => $sessionToken,
            "User" => $view,
        ]);
    }

    if ($method === "PUT") {
        $me = requireUser();
        $targetId = $id ?? (int) $me["UserID"];

        if ($targetId !== (int) $me["UserID"] && !isAdminUser($me) && !hasModuleKey()) {
            respond(403, ["error" => "You can only update your own account."]);
        }

        $body = jsonBody();

        $userFields = ["FirstName", "MiddleName", "LastName", "Email", "PhoneNumber"];
        $hasUserFields = false;
        foreach ($userFields as $f) {
            if (array_key_exists($f, $body)) {
                $hasUserFields = true;
                break;
            }
        }

        $profileFields = ["Username", "Bio", "ProfilePictureURL", "DateOfBirth"];
        $hasProfileFields = false;
        foreach ($profileFields as $f) {
            if (array_key_exists($f, $body)) {
                $hasProfileFields = true;
                break;
            }
        }

        if (!$hasUserFields && !$hasProfileFields) {
            respond(400, ["error" => "Nothing to update."]);
        }

        if ($hasUserFields) {
            $userModel->update($targetId, $body);
        }
        if ($hasProfileFields) {
            $profileModel->update($targetId, $body);
        }

        $updatedView = $userModel->getAccountView($targetId);
        respond(200, $updatedView);
    }

    if ($method === "DELETE") {
        $me = requireUser();
        $targetId = $id ?? (int) $me["UserID"];

        if ($targetId !== (int) $me["UserID"] && !isAdminUser($me) && !hasModuleKey()) {
            respond(403, ["error" => "You can only delete your own account."]);
        }

        $body = jsonBody();
        $password = $body["Password"] ?? null;
        $userModel->deleteAccount($targetId, $password);

        respond(200, ["message" => "Account deleted."]);
    }

    respond(405, ["error" => "Method not allowed."]);
} catch (Throwable $e) {
    handleApiException($e);
}
