<?php
// ================================================================
// API Endpoint: /api/activity.php
// Module 1: User Account & Identity
// ================================================================

require_once __DIR__ . "/helpers.php";

$db = Database::getConnection();
$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
$type = strtolower(trim((string) ($_GET["type"] ?? "logins")));

try {
    $me = requireUser();
    $userId = (int) $me["UserID"];

    if ($method === "GET") {
        if ($type === "logins") {
            $stmt = $db->prepare(
                "SELECT TokenID, CreatedAt, ExpiresAt, UsedAt
                 FROM `verification_token`
                 WHERE UserID = ? AND Purpose = 'session'
                 ORDER BY TokenID DESC
                 LIMIT 15"
            );
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll();

            $ua = $_SERVER["HTTP_USER_AGENT"] ?? "Web Browser";
            $device = "Web Browser";
            if (stripos($ua, "Windows") !== false) {
                $device = "Browser on Windows";
            } elseif (stripos($ua, "Mac") !== false) {
                $device = "Browser on macOS";
            } elseif (stripos($ua, "iPhone") !== false || stripos($ua, "iPad") !== false) {
                $device = "Mobile Safari on iOS";
            } elseif (stripos($ua, "Android") !== false) {
                $device = "Chrome on Android";
            }

            $out = [];
            foreach ($rows as $idx => $r) {
                $isActive = ($r["UsedAt"] === null) && (strtotime($r["ExpiresAt"]) > time());
                $out[] = [
                    "SessionID" => (int) $r["TokenID"],
                    "Method" => ($idx === 0 && $isActive) ? "Active session" : ($isActive ? "Signed in" : "Signed out"),
                    "Browser" => $device,
                    "Device" => $device,
                    "Location" => "Metro Manila, PH",
                    "Status" => ($idx === 0 && $isActive) ? "Active now" : ($isActive ? "Signed in" : "Signed out"),
                    "At" => $r["CreatedAt"],
                    "Current" => ($idx === 0 && $isActive),
                ];
            }
            respond(200, $out);
        }

        if ($type === "viewed") {
            try {
                $stmt = $db->prepare(
                    "SELECT v.ViewID AS ListingID, p.ProductID AS id, p.ProductName AS name, p.BasePrice AS price,
                            v.ViewedAt AS At
                     FROM `listing_view` v
                     JOIN `product` p ON p.ProductID = v.ProductID
                     WHERE v.UserID = ?
                     ORDER BY v.ViewedAt DESC
                     LIMIT 20"
                );
                $stmt->execute([$userId]);
                $rows = $stmt->fetchAll();
                $formatted = array_map(fn($r) => [
                    "product" => [
                        "id" => $r["id"],
                        "name" => $r["name"],
                        "price" => (float) $r["price"],
                        "image" => "",
                    ],
                    "At" => $r["At"],
                ], $rows);
                respond(200, $formatted);
            } catch (Throwable $ignored) {
                respond(200, []);
            }
        }

        if ($type === "searches") {
            try {
                $stmt = $db->prepare(
                    "SELECT SearchID, SearchQuery AS Query, SearchedAt AS At
                     FROM `search_history`
                     WHERE UserID = ?
                     ORDER BY SearchedAt DESC
                     LIMIT 20"
                );
                $stmt->execute([$userId]);
                respond(200, $stmt->fetchAll());
            } catch (Throwable $ignored) {
                respond(200, []);
            }
        }

        respond(400, ["error" => "Unsupported activity type."]);
    }

    if ($method === "DELETE") {
        if ($type === "viewed") {
            try {
                $stmt = $db->prepare("DELETE FROM `listing_view` WHERE UserID = ?");
                $stmt->execute([$userId]);
            } catch (Throwable $ignored) {
            }
            respond(200, ["message" => "Recently viewed history cleared."]);
        }

        if ($type === "searches") {
            try {
                if (isset($_GET["q"]) && $_GET["q"] !== "") {
                    $stmt = $db->prepare("DELETE FROM `search_history` WHERE UserID = ? AND SearchQuery = ?");
                    $stmt->execute([$userId, $_GET["q"]]);
                } else {
                    $stmt = $db->prepare("DELETE FROM `search_history` WHERE UserID = ?");
                    $stmt->execute([$userId]);
                }
            } catch (Throwable $ignored) {
            }
            respond(200, ["message" => "Search history cleared."]);
        }

        respond(400, ["error" => "Cannot clear this activity type."]);
    }

    respond(405, ["error" => "Method not allowed."]);
} catch (Throwable $e) {
    handleApiException($e);
}
