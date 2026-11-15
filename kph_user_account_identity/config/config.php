<?php
// ============================================================
// KameraPH | Module 1: User Account and Identity
// The ONLY file that holds settings. Change values here, nowhere else.
// If config.local.php exists, its values override the defaults below.
// ============================================================

$config = [
    // ---- Database (XAMPP defaults; override in config.local.php for Azure/host) ----
    "host"   => "localhost",
    "port"   => 3306,
    "dbname" => "kph_user_account_identity",
    "user"   => "root",
    "pass"   => "",

    // ---- Secrets ----
    // 64 hex characters (32 bytes). Used to encrypt ID numbers and to scramble tokens.
    // PLACEHOLDER (all zeros) for sharing. Put your own 64 hex characters here. Never change it later.
    "encryption_key" => "0000000000000000000000000000000000000000000000000000000000000000",

    // Shared secret that the other 9 modules send in the X-Module-Key header
    // so that they may read user data on the server side. Share it with the other groups.
    "module_key" => "kph-shared-module-key-change-me",

    // ---- Rules ----
    "token_minutes" => [
        "verify_email"   => 30,
        "verify_phone"   => 10,
        "reset_password" => 30,
        "session"        => 1440
    ],
    "max_addresses_per_user" => 10,

    // While payment, email and SMS are simulated, the API returns the code/token
    // in the response of send-token. Set to false for the final demo.
    "show_token_in_response" => true,

    // ---- Other modules (base API addresses, no slash at the end) ----
    "modules" => [
        "sellers"        => "http://localhost/kph_seller_verification/api",
        "catalog"        => "http://localhost/kph_catalog_specification/api",
        "engagement"     => "http://localhost/kph_marketing_engagement/api",
        "shopping"       => "http://localhost/kph_shopping_promotions/api",
        "orders"         => "http://localhost/kph_order_payment/api",
        "fulfillment"    => "http://localhost/kph_fulfillment_logistics/api",
        "shipping"       => "http://localhost/kph_shipping_delivery/api",
        "support"        => "http://localhost/kph_post_purchase_support/api",
        "administration" => "http://localhost/kph_administration_analytics/api"
    ],

    // Module 1 works alone. Set to true only after Module 4 (notifications)
    // and Module 10 (logs) agree on the endpoints used in api/helpers.php.
    "call_other_modules" => false
];

$localConfigFile = __DIR__ . '/config.local.php';
if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (is_array($localConfig)) {
        $config = array_replace_recursive($config, $localConfig);
    }
}

return $config;
