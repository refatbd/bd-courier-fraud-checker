<?php

return [
    "pathao_user" => env("PATHAO_USER", ""),
    "pathao_password" => env("PATHAO_PASSWORD", ""),

    "redx_phone" => env("REDX_PHONE", ""),
    "redx_password" => env("REDX_PASSWORD", ""),

    // 🟦 Recommended: Official Steadfast REST API
    "steadfast_api_key"    => env("STEADFAST_API_KEY", ""),
    "steadfast_secret_key" => env("STEADFAST_SECRET_KEY", ""),

    // 🟦 Legacy: Web portal session authentication
    "steedfast_user"     => env("STEADFAST_USER", ""),
    "steedfast_password" => env("STEADFAST_PASSWORD", ""),

    "carrybee_phone"    => env("CARRYBEE_PHONE", ""),
    "carrybee_password" => env("CARRYBEE_PASSWORD", ""),

    "paperfly_user"     => env("PAPERFLY_USER", ""),
    "paperfly_password" => env("PAPERFLY_PASSWORD", ""),

    'message' => [
        "pathao_user"          => 'PATHAO_USER',
        "pathao_password"      => 'PATHAO_PASSWORD',
        "redx_phone"           => 'REDX_PHONE',
        "redx_password"        => 'REDX_PASSWORD',
        "steadfast_api_key"    => 'STEADFAST_API_KEY (Recommended)',
        "steadfast_secret_key" => 'STEADFAST_SECRET_KEY (Recommended)',
        "steedfast_user"       => 'STEADFAST_USER (Legacy)',
        "steedfast_password"   => 'STEADFAST_PASSWORD (Legacy)',
        "carrybee_phone"       => 'CARRYBEE_PHONE',
        "carrybee_password"    => 'CARRYBEE_PASSWORD',
        "paperfly_user"        => 'PAPERFLY_USER',
        "paperfly_password"    => 'PAPERFLY_PASSWORD',
    ],

];
