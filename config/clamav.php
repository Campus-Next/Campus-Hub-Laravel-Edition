<?php

return [
    'host' => env('CLAMAV_HOST'),
    'port' => (int) env('CLAMAV_PORT'),

    'quarantine_path' => env('CLAMAV_QUARANTINE_PATH', '/home/devops/hasbi/quarantine'),
];
