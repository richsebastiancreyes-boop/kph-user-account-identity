<?php
// ================================================================
// API Endpoint: /api/addresses.php
// Module 1: User Account & Identity
// ================================================================

require_once __DIR__ . "/helpers.php";

$addressModel = new UserAddress();

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
$id = isset($_GET["id"]) && $_GET["id"] !== "" ? (int) $_GET["id"] : null;
$queryUserId = isset($_GET["user_id"]) && $_GET["user_id"] !== "" ? (int) $_GET["user_id"] : null;

try {
    if ($method === "GET") {
        $me = currentUser();

        if ($id !== null) {
            $addr = $addressModel->getById($id);
            if (!$addr) {
                respond(404, ["error" => "Address not found."]);
            }
            $ownerId = (int) $addr["UserID"];
            if ($me === null && !hasModuleKey()) {
                respond(401, ["error" => "Authentication required."]);
            }
            if ($me !== null && (int) $me["UserID"] !== $ownerId && !isAdminUser($me) && !hasModuleKey()) {
                respond(403, ["error" => "Access denied."]);
            }
            respond(200, $addr);
        }

        $targetUserId = $queryUserId ?? ($me ? (int) $me["UserID"] : null);
        if ($targetUserId === null) {
            respond(401, ["error" => "Authentication required."]);
        }

        if ($me !== null && (int) $me["UserID"] !== $targetUserId && !isAdminUser($me) && !hasModuleKey()) {
            respond(403, ["error" => "Access denied."]);
        }

        respond(200, $addressModel->getByUser($targetUserId));
    }

    if ($method === "POST") {
        $me = requireUser();
        $body = jsonBody();
        $targetUserId = isset($body["UserID"]) && (isAdminUser($me) || hasModuleKey())
            ? (int) $body["UserID"]
            : (int) $me["UserID"];

        $newId = $addressModel->create($targetUserId, $body);
        $created = $addressModel->getById($newId);
        respond(201, [
            "message" => "Address saved.",
            "AddressID" => $newId,
            "Address" => $created,
        ]);
    }

    if ($method === "PUT") {
        $me = requireUser();
        if ($id === null) {
            respond(400, ["error" => "Address id is required."]);
        }
        $body = jsonBody();
        $updated = $addressModel->update($id, (int) $me["UserID"], $body);
        respond(200, [
            "message" => "Address updated.",
            "Address" => $updated,
        ]);
    }

    if ($method === "DELETE") {
        $me = requireUser();
        if ($id === null) {
            respond(400, ["error" => "Address id is required."]);
        }
        $addressModel->delete($id, (int) $me["UserID"]);
        respond(200, ["message" => "Address deleted."]);
    }

    respond(405, ["error" => "Method not allowed."]);
} catch (Throwable $e) {
    handleApiException($e);
}
