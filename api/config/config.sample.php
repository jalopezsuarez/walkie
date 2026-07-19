<?php
/**
 * Walkie API — configuration sample.
 *
 * Copy this file to `config.php` and fill in the real values on the server.
 * `config.php` is git-ignored on purpose so secrets never land in the repo.
 *
 *   cp config/config.sample.php config/config.php
 *
 * Generate a fresh APP_KEY with:
 *   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
 */

return [
    // --- Database (values shown are the InfinityFree account for walkie) ---
    'db' => [
        'host'     => 'sql312.infinityfree.com',
        'port'     => 3306,
        'name'     => 'if0_42263887_walkie',
        'user'     => 'if0_42263887',
        'pass'     => 'CHANGE_ME',            // MySQL password
        'charset'  => 'utf8mb4',
    ],

    // --- Application ---
    'app' => [
        // 32 random bytes, base64 encoded. MUST be set and kept stable.
        'key'         => 'CHANGE_ME_base64_32_bytes',
        // Random secret required by the one-shot api/install.php installer.
        'install_key' => 'CHANGE_ME_random',
        'url'         => 'https://walkie.howto.rocks/api',
        'web_origin'  => 'https://walkie.howto.rocks',
        'debug'       => false,   // NEVER true in production
    ],

    // --- Email delivery of login codes (SMTP relay, e.g. Brevo) ---
    'mail' => [
        'from'      => 'service@example.com',
        'from_name' => 'Walkie',
        'smtp' => [
            'host'     => 'smtp-relay.example.com',
            'port'     => 587,
            'security' => 'tls',              // 'tls' (STARTTLS) | 'ssl' | 'none'
            'username' => 'CHANGE_ME',
            'password' => 'CHANGE_ME',
        ],
        // When true the 6-digit code is only written to storage/mail.log and
        // (if app.debug is also true) returned in the API response.
        // Useful in development. Turn OFF in production.
        'log_only'  => false,
    ],

    // --- Timeouts (seconds) ---
    'ttl' => [
        'login_code'   => 300,        // 5 minutes
        'session'      => 60 * 60 * 24 * 30, // 30 days
        'pairing'      => 60,         // 1 minute (QR rotates for security)
        'audio_msg'    => 60 * 60,        // 1 hour
        'text_msg'     => 60 * 60 * 24,   // 24 hours
    ],

    // --- Rate limiting (requests per fixed window in seconds) ---
    'limits' => [
        'code_per_email'   => [3, 900],    // 3 codes / 15 min per email
        'code_per_ip'      => [10, 900],   // 10 codes / 15 min per IP
        'verify_per_ip'    => [20, 900],   // 20 verify attempts / 15 min per IP
        'message_per_user' => [60, 60],    // 60 messages / min per user
        'api_per_ip'       => [240, 60],   // 240 requests / min per IP (global)
        'max_code_tries'   => 5,           // wrong-code tries before invalidation
    ],

    // --- Upload limits ---
    'upload' => [
        'max_text_len'   => 4000,          // characters
        'max_audio_bytes'=> 2 * 1024 * 1024, // 2 MB per voice note
    ],

    // OAuth 2.0 token layer. Optional — sensible defaults apply if omitted.
    'oauth' => [
        'access_ttl'  => 60 * 60,            // access token (JWT) lifetime: 1 h
        'refresh_ttl' => 60 * 60 * 24 * 30,  // refresh token lifetime: 30 days
        'jwt_key'     => '',                 // HS256 key; empty = reuse app.key
    ],
];
