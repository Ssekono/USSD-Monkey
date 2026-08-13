<?php

return [
    "ussd_menu_file" => null,
    "chars_per_line" => null, // Set to wrap display lines exceeding this width; null disables wrapping
    "items_per_page" => 6,
    "menu_items_separator" => "|",
    "session_ttl" => 180, // Seconds a session's Redis keys survive since the last request
    "nav_next" => "0",
    "nav_prev" => "00",
    "sanitization" => [
        "enabled" => true,
        "country_code" => "256", // Configurable country code
        "local_number_length" => 9 // Number of digits excluding the leading zero
    ],
    "available_adapters" => [
        "africastalking" => \GantryMotion\USSDMonkey\Adapters\AfricasTalkingAdapter::class,
        "uconnect"       => \GantryMotion\USSDMonkey\Adapters\UConnectAdapter::class,
        "dmark"          => \GantryMotion\USSDMonkey\Adapters\DMarkAdapter::class,
        "trueafrican"    => \GantryMotion\USSDMonkey\Adapters\TrueAfricanAdapter::class,
    ],
    'redis' => [
        'scheme' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 6379,
    ]
];
