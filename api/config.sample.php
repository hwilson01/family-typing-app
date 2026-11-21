<?php
// Copy to config.php (which setup.php does for you) and fill in.
// config.php is never committed and is blocked from the web by api/.htaccess.
return [
    // 'mysql' in production (Hostinger), 'sqlite' for local testing.
    'db_driver'   => 'mysql',

    // --- MySQL (production) ---
    // From the app, connect to 127.0.0.1 (not the srvNNNN host, which is
    // only for outside connections).
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'db_name'     => 'CHANGE_ME',
    'db_user'     => 'CHANGE_ME',
    'db_pass'     => 'CHANGE_ME',

    // --- SQLite (local testing only) ---
    'db_path'     => __DIR__ . '/../db/local.sqlite',

    // Hashed with password_hash(). setup.php writes this; never store plaintext.
    'family_password_hash' => '',

    // Anthropic API key for AI "made for you" passages. Leave '' to keep that
    // feature off; add your key (with its own spend limit) to turn it on.
    'anthropic_api_key' => '',

    // Monthly cap on AI-generated passages (cost control).
    'ai_month_cap' => 200,

    // Lockout policy for profile PINs.
    'pin_max_attempts' => 5,
    'pin_lockout_minutes' => 15,
];
