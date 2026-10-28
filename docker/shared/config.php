<?php

// Development configuration for the docker environment ONLY - no secret: the
// credentials belong to a MariaDB that exists only inside the local compose
// network. A real installation gets its config.php from the installer, in
// shared/ next to the release, and it never travels.
return [
    'debug' => true,
    'db' => [
        'host' => 'db',
        'port' => 3306,
        'name' => 'mitgliederverwaltung',
        'user' => 'mitgliederverwaltung',
        'password' => 'dev-password',
    ],
    // Development key only (32 bytes, base64).
    'server_key' => 'ZGV2LXNlcnZlci1rZXktMzItYnl0ZXMtbG9uZyEhISE=',
    'cron_token' => 'dev-cron-token',
];
