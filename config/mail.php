<?php

// Trim whitespace, strip accidental surrounding quotes from dashboard env UIs,
// and treat empty or literal "null" strings as unset so defaults apply cleanly.
$cleanMailEnv = static function (string $key, mixed $default = null): mixed {
    $value = env($key);

    if ($value === null) {
        return $default;
    }

    if (is_string($value)) {
        $value = trim($value);
        if (
            strlen($value) >= 2
            && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))
        ) {
            $value = trim(substr($value, 1, -1));
        }

        if ($value === '' || strtolower($value) === 'null') {
            return $default;
        }
    }

    return $value;
};

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => strtolower((string) $cleanMailEnv('MAIL_MAILER', 'log')),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            // Normalize common encryption aliases ('tls'/'ssl') to valid Symfony Mailer DSN schemes
            'scheme' => match (strtolower((string) $cleanMailEnv('MAIL_SCHEME', ''))) {
                ''      => null,
                'tls'   => 'smtp',
                'ssl'   => 'smtps',
                default => strtolower((string) $cleanMailEnv('MAIL_SCHEME', '')),
            },
            'url' => $cleanMailEnv('MAIL_URL'),
            'host' => (string) $cleanMailEnv('MAIL_HOST', '127.0.0.1'),
            'port' => (int) $cleanMailEnv('MAIL_PORT', 2525),
            'username' => $cleanMailEnv('MAIL_USERNAME'),
            'password' => $cleanMailEnv('MAIL_PASSWORD'),
            'timeout' => ($mailTimeout = $cleanMailEnv('MAIL_TIMEOUT')) !== null ? (int) $mailTimeout : 15,
            'local_domain' => $cleanMailEnv('MAIL_EHLO_DOMAIN', parse_url((string) $cleanMailEnv('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => $cleanMailEnv('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => $cleanMailEnv('MAIL_FROM_NAME', $cleanMailEnv('APP_NAME', 'Laravel')),
    ],

];
