<?php
// Encrypts ID numbers (AES-256-GCM) and scrambles one-time tokens (HMAC-SHA256).
// The key comes from config/config.php.

require_once __DIR__ . '/../config/Database.php';

class Crypto
{
    private static function key(): string
    {
        $hex = Database::getConfig()["encryption_key"];
        if (!ctype_xdigit($hex) || strlen($hex) !== 64) {
            throw new RuntimeException("encryption_key in config.php must be 64 hex characters.");
        }
        return hex2bin($hex);
    }

    // Returns text that is safe to save in a VARCHAR column: base64(iv + tag + cipher).
    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = "";
        $cipher = openssl_encrypt($plain, "aes-256-gcm", self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    // Only for server-side checks. The API never returns the result to a caller.
    public static function decrypt(string $stored): ?string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), "aes-256-gcm", self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    // One-way scramble for tokens. The same token always gives the same result, so it can be searched.
    public static function hashToken(string $token): string
    {
        return hash_hmac("sha256", $token, self::key());
    }
}
