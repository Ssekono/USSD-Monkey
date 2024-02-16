<?php

$config = [
    "environment" => "production",
    "input_format" => "chained",
    "output_format" => "conend",
    "enable_chained_input" => true,
    "enable_back_and_forth_menu_nav" => true,
    "chars_per_line" => null,
    "menu_items_separator" => "|",
    "nav_next" => "0",
    "nav_prev" => "00",
    "sanitizePhoneNumber" => true,
    "error_title" => "Error Occurred",
    "error_message" => "Something went wrong. Please try again later",
    "disabled_func" => [],
    "request_variables" => [
        "session_id" => "sessionId",
        "service_code" => "serviceCode",
        "phone_number" => "phoneNumber",
        "request_string" => "text"
    ],
    'redis' => [
        'scheme' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 6379,
        // 'password' => 'your_password', // Uncomment and specify if your Redis server requires authentication
    ]
];

return $config;
