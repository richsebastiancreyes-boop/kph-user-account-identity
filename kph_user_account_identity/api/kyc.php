<?php
// ================================================================
// API Endpoint: /api/kyc.php
// Module 1: User Account & Identity
// ================================================================

require_once __DIR__ . "/helpers.php";

$kycModel = new KycVerification();
$userRoleModel = new UserRole();
$uploadHelper = new UploadHelper();

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
$id = isset($_GET["id"]) && $_GET["id"] !== "" ? (int) $_GET["id"] : null;
$action = $_GET["action"] ?? null;

try {
    if ($method === "GET") {
        $me = currentUser();

        if (isset($_GET["all"])) {
            if ($me === null && !hasModuleKey()) {
                respond(401, ["error" => "Authentication required."]);
            }
            $status = $_GET["status"] ?? null;
            respond(200, $kycModel->getAll($status));
        }

        if ($id !== null) {
            $row = $kycModel->getById($id);
            if (!$row) {
                respond(404, ["error" => "KYC submission not found."]);
            }
            respond(200, $row);
        }

        $targetUserId = isset($_GET["user_id"]) && $_GET["user_id"] !== ""
            ? (int) $_GET["user_id"]
            : ($me ? (int) $me["UserID"] : null);

        if ($targetUserId === null) {
            respond(401, ["error" => "Authentication required."]);
        }

        $latest = $kycModel->getLatestByUser($targetUserId);
        $view = $kycModel->toStatusView($targetUserId, $latest);
        // Add KYCStatus alias for UI compatibility alongside VerificationStatus
        $view["KYCStatus"] = $latest ? $latest["VerificationStatus"] : "Unverified";
        if ($latest) {
            $view["IDType"] = $latest["IDType"];
        }
        respond(200, $view);
    }

    if ($method === "POST") {
        $me = currentUser();

        if ($action === "approve") {
            if ($id === null) {
                respond(400, ["error" => "KYC id is required."]);
            }
            if ($me === null && !hasModuleKey()) {
                respond(401, ["error" => "Authentication required."]);
            }
            $body = jsonBody();
            $actingId = $me ? (int) $me["UserID"] : (int) ($body["ReviewedByAdminID"] ?? 1);
            $approved = $kycModel->approve($id, $actingId);
            // Automatically grant seller role if not already assigned
            if (!$userRoleModel->hasRole((int) $approved["UserID"], "seller")) {
                $userRoleModel->assignByName((int) $approved["UserID"], "seller");
            }
            respond(200, [
                "message" => "KYC approved and Seller role granted.",
                "KYC" => $approved,
            ]);
        }

        if ($action === "reject") {
            if ($id === null) {
                respond(400, ["error" => "KYC id is required."]);
            }
            if ($me === null && !hasModuleKey()) {
                respond(401, ["error" => "Authentication required."]);
            }
            $body = jsonBody();
            $actingId = $me ? (int) $me["UserID"] : (int) ($body["ReviewedByAdminID"] ?? 1);
            $reason = $body["RejectionReason"] ?? $body["Reason"] ?? null;
            $rejected = $kycModel->reject($id, $actingId, $reason);
            respond(200, [
                "message" => "KYC rejected.",
                "KYC" => $rejected,
            ]);
        }

        // Submit new KYC
        $me = requireUser();
        $userId = (int) $me["UserID"];

        if (!empty($_POST) || !empty($_FILES)) {
            $data = $_POST;
            if (!empty($_FILES["IDImage"])) {
                $data["IDImageURL"] = $uploadHelper->saveImage($_FILES["IDImage"], "kyc");
            }
            if (!empty($_FILES["SelfieImage"])) {
                $data["SelfieImageURL"] = $uploadHelper->saveImage($_FILES["SelfieImage"], "kyc");
            }
        } else {
            $data = jsonBody();
        }

        $kycId = $kycModel->submit($userId, $data);
        $latest = $kycModel->getById($kycId);
        respond(201, [
            "message" => "KYC verification submitted.",
            "KYCID" => $kycId,
            "KYC" => $latest,
        ]);
    }

    respond(405, ["error" => "Method not allowed."]);
} catch (Throwable $e) {
    handleApiException($e);
}
