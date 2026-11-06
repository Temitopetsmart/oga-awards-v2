<?php
// Private settings. Never commit backend/config.php (it is in .gitignore) and never put it in a public folder.
// Copy this file to backend/config.php and fill it in on each machine.
return [
    'APP_ENV'      => 'local',
    'APP_DEBUG'    => true,            // set to false on the live site
    'APP_TIMEZONE' => 'Africa/Lagos',
    'APP_URL'      => 'http://localhost/oga-awards-v2/',

    // Database (XAMPP defaults)
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_NAME' => 'oga_award_v2',
    'DB_USER' => 'root',
    'DB_PASS' => '',

    // Master key that encrypts the secrets you save in Admin -> Settings (SMTP password, Flutterwave keys).
    // 64 hex characters. Generate one:  php -r "echo bin2hex(random_bytes(32));"
    // Keep the SAME value for the life of the site: changing it makes saved secrets unreadable.
    'APP_ENCRYPTION_KEY' => '35c08ca98796445538a95f331c32a629d233537dbfa837eb5e378f47c1ee7573',     // if your config.php already has SMTP_ENCRYPTION_KEY, keep it as it is (same purpose)

    // OPTIONAL fallbacks. Normally leave these empty and enter the keys in Admin -> Settings instead.
    'FLW_WEBHOOK_HASH' => '',
];