<?php

return [
    'mqtt' => [
        'host'      => '127.0.0.1',
        'port'      => 1883,
        'client_id' => 'php-server-' . uniqid(),
        'username'  => '',
        'password'  => '',
    ],
    'websocket' => [
        'host' => '0.0.0.0',
        'port' => 8080,
    ],
    'topics' => [
        'device_status'    => 'device/+/status',
        'device_heartbeat' => 'device/+/heartbeat',
        'device_command'   => 'device/{device_id}/command',
    ],
    'device' => [
        'heartbeat_timeout' => 30,
    ],
    'internal_bridge' => [
        'port' => 8081,
    ],
];
