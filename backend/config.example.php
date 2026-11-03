<?php
// Private settings. Never commit this file (it is in .gitignore) and never put it in a public folder.
return [
    'APP_ENV'      => 'local',
    'APP_DEBUG'    => true,            // set to false on the live site
    'APP_TIMEZONE' => 'Africa/Lagos',

    // Database (XAMPP defaults)
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_NAME' => 'oga_award_v2',
    'DB_USER' => 'root',
    'DB_PASS' => '',

    // Flutterwave (used from phase 5). Keys go ONLY here, never in JavaScript or the database.
    'FLW_PUBLIC_KEY'   => '',
    'FLW_SECRET_KEY'   => '',
    'FLW_WEBHOOK_HASH' => '',

    // Email / SMTP (used from phase 7)
    'SMTP_HOST'     => 'smtp.gmail.com',
    'SMTP_PORT'     => '587',
    'SMTP_USER'     => 'theprowriteservices@gmail.com',
    'SMTP_PASSWORD' => 'owzdnjnntyehibdb',
    'SMTP_SECURE'    => 'tls',   // 'tls' for port 587, 'ssl' for port 465
    'MAIL_FROM'     => 'theprowriteservices@gmail.com',
    'MAIL_FROM_NAME' => 'OGA Awards',
];