<?php
require_once __DIR__ . '/../config/Database.php';

// Calls the APIs of OTHER modules (guide 3.5), only through the addresses in config.php.
// Module 1 works alone: when call_other_modules is false, or when the other module does not
// answer, these methods quietly return false. They never throw and never stop the real action.
class ModuleApi
{
    // Sends one request. Returns [status code, data]. Status 0 means the other module could not be reached.
    public static function call(string $moduleName, string $method, string $path, ?array $body = null): array
    {
        $modules = Database::getConfig()["modules"];
        if (!isset($modules[$moduleName])) {
            return [0, null];
        }

        $ch = curl_init($modules[$moduleName] . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "X-Module-Key: " . Database::getConfig()["module_key"]
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$code, json_decode((string)$response, true)];
    }

    // Module 4: puts a message in the user's notifications. Returns true if Module 4 accepted it.
    public static function notifyUser(int $userId, string $title, string $message, string $type, ?string $referenceType = null, ?int $referenceId = null): bool
    {
        if (!Database::getConfig()["call_other_modules"]) {
            return false;
        }
        [$code] = self::call("engagement", "POST", "/notifications.php", [
            "UserID"        => $userId,
            "Title"         => $title,
            "Message"       => $message,
            "Type"          => $type,
            "ReferenceType" => $referenceType,
            "ReferenceID"   => $referenceId
        ]);
        return $code === 201 || $code === 200;
    }

    // Module 10: writes one line in the system log. Returns true if Module 10 accepted it.
    public static function logAction(?int $adminId, string $actionType, string $entity, int $entityId, string $details): bool
    {
        if (!Database::getConfig()["call_other_modules"]) {
            return false;
        }
        [$code] = self::call("administration", "POST", "/logs.php", [
            "AdminID"       => $adminId,
            "ActionType"    => $actionType,
            "EntityAffected" => $entity,
            "EntityID"      => $entityId,
            "ActionDetails" => $details,
            "IPAddress"     => $_SERVER["REMOTE_ADDR"] ?? null
        ]);
        return $code === 201 || $code === 200;
    }
}
