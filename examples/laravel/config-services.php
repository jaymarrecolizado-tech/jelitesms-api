<?php

// Merge into your config/services.php 'services' array.

return [
    // ... existing services ...

    'sms' => [
        'url' => env('SMS_API_URL', 'http://localhost/projects/jelite_sms_api'),
        'key' => env('SMS_API_KEY'),
    ],
];
