<?php

return [
    'enabled' => env('CLAMAV_UPLOAD_SCANNING_ENABLED', true),

    'connection' => env('CLAMAV_CONNECTION', 'unix'),
    'socket_path' => env('CLAMAV_SOCKET_PATH', '/run/clamav/clamd.sock'),
    'host' => env('CLAMAV_HOST', '127.0.0.1'),
    'port' => (int) env('CLAMAV_PORT', 3310),
    'timeout' => (float) env('CLAMAV_TIMEOUT', 5.0),

    'quarantine_path' => env('CLAMAV_QUARANTINE_PATH', '/home/devops/hasbi/quarantine'),
];
