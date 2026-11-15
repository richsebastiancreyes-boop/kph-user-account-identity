<?php
// Connects to MySQL with PDO. One connection is shared by the whole request.

class Database
{
    private static ?PDO $connection = null;

    public static function getConfig(): array
    {
        static $config = null;
        if ($config === null) {
            $config = require __DIR__ . '/config.php';
        }
        return $config;
    }

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $config = self::getConfig();
            $port = $config["port"] ?? 3306;
            $dsn = "mysql:host=" . $config["host"] . ";port=" . $port . ";dbname=" . $config["dbname"] . ";charset=utf8mb4";

            self::$connection = new PDO($dsn, $config["user"], $config["pass"], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false
            ]);
        }
        return self::$connection;
    }

    public static function connect(): PDO
    {
        return self::getConnection();
    }
}
