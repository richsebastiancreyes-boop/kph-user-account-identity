<?php
// ================================================================
// API Endpoint: /api/roles.php
// Module 1: User Account & Identity
// ================================================================

require_once __DIR__ . "/helpers.php";

$roleModel = new Role();
$userRoleModel = new UserRole();

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

try {
    if ($method === "GET") {
        if (isset($_GET["user_id"]) && $_GET["user_id"] !== "") {
            $uid = (int) $_GET["user_id"];
            respond(200, $userRoleModel->getRolesByUser($uid));
        }
        $roleModel->ensureDefaults();
        respond(200, $roleModel->getAll());
    }

    if ($method === "POST") {
        $me = currentUser();
        if ($me === null && !hasModuleKey()) {
            respond(401, ["error" => "Authentication required."]);
        }
        $body = jsonBody();
        $userRoleId = $userRoleModel->assignFromData($body);
        respond(201, [
            "message" => "Role assigned.",
            "UserRoleID" => $userRoleId,
        ]);
    }

    if ($method === "DELETE") {
        $me = currentUser();
        if ($me === null && !hasModuleKey()) {
            respond(401, ["error" => "Authentication required."]);
        }
        $body = jsonBody();
        $userId = isset($_GET["user_id"]) ? (int) $_GET["user_id"] : (int) ($body["UserID"] ?? 0);
        $roleId = isset($_GET["role_id"]) ? (int) $_GET["role_id"] : (int) ($body["RoleID"] ?? 0);

        if ($roleId <= 0 && !empty($body["RoleName"])) {
            $r = $roleModel->getByName((string) $body["RoleName"]);
            if ($r) {
                $roleId = (int) $r["RoleID"];
            }
        }

        if ($userId <= 0 || $roleId <= 0) {
            respond(400, ["error" => "user_id and role_id (or RoleName) are required."]);
        }

        $actingId = $me ? (int) $me["UserID"] : (int) ($body["ActingUserID"] ?? 0);
        $userRoleModel->remove($userId, $roleId, $actingId);
        respond(200, ["message" => "Role removed."]);
    }

    respond(405, ["error" => "Method not allowed."]);
} catch (Throwable $e) {
    handleApiException($e);
}
