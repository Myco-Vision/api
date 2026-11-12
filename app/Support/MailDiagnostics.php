<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Shared mail failure sanitization, logging, and configuration checks across auth and billing mailers.
 */
class MailDiagnostics
{
    /**
     * Log a sanitized mail failure and return an actionable configuration hint.
     *
     * @param  array<string, mixed>  $extraContext
     */
    public static function diagnose(string $context, \Throwable $e, array $extraContext = []): string
    {
        $mailer = (string) config('mail.default');
        $host = (string) config('mail.mailers.smtp.host');
        $port = (string) config('mail.mailers.smtp.port');
        $safeMsg = self::sanitize($e->getMessage());

        Log::error($context, array_merge($extraContext, [
            'exception' => $e::class,
            'message'   => $safeMsg,
            'mailer'    => $mailer,
            'host'      => $host,
            'port'      => $port,
        ]));

        if (str_contains($safeMsg, '127.0.0.1') || str_contains($safeMsg, 'Connection refused')) {
            return 'The server is still configured for localhost SMTP (MAIL_HOST=127.0.0.1). Set MAIL_HOST=smtp.gmail.com and MAIL_PORT=587 in your deployment environment variables, then redeploy.';
        }

        if (
            ($mailer === 'smtp' && str_contains($host, '@'))
            || str_contains($safeMsg, 'getaddrinfo')
            || str_contains($safeMsg, 'php_network_getaddresses')
            || str_contains($safeMsg, 'Name or service not known')
        ) {
            $hostLabel = $host !== '' ? $host : 'empty';
            return "Cannot resolve MAIL_HOST ({$hostLabel}). Set MAIL_HOST=smtp.gmail.com (with a dot, not @) and MAIL_PORT=587 in your deployment environment variables, then redeploy.";
        }

        if (str_contains($safeMsg, '535') || str_contains($safeMsg, '534') || stripos($safeMsg, 'authenticat') !== false) {
            return 'SMTP authentication failed. Check MAIL_USERNAME and MAIL_PASSWORD (16-character Google App Password without spaces or surrounding quotes) in your deployment environment variables, then redeploy.';
        }

        if (str_contains($safeMsg, 'STARTTLS') || str_contains($safeMsg, 'stream_socket_enable_crypto') || str_contains($safeMsg, '530')) {
            return "TLS/SSL handshake failed on {$host}:{$port}. Set MAIL_PORT=587 with MAIL_SCHEME=null (or MAIL_PORT=465 with MAIL_SCHEME=ssl) in your deployment environment variables, then redeploy.";
        }

        if (str_contains($safeMsg, 'UnsupportedScheme') || stripos($safeMsg, 'scheme') !== false) {
            return 'Invalid MAIL_SCHEME configuration. Set MAIL_SCHEME=null (with MAIL_PORT=587) in your deployment environment variables, then redeploy.';
        }

        if (str_contains($safeMsg, 'is not defined') || str_contains($safeMsg, 'Unsupported mail transport')) {
            return "Invalid MAIL_MAILER ({$mailer}). Set MAIL_MAILER=smtp without extra spaces or quotes in your deployment environment variables, then redeploy.";
        }

        if (str_contains($safeMsg, 'RFC 2822') || str_contains($safeMsg, '"From"') || str_contains($safeMsg, '"Sender"')) {
            return 'Invalid MAIL_FROM_ADDRESS configuration. Set MAIL_FROM_ADDRESS to a valid email address without quotes in your deployment environment variables, then redeploy.';
        }

        if (stripos($safeMsg, 'timed out') !== false || str_contains($safeMsg, 'Connection could not be established')) {
            return "Could not connect to SMTP server {$host}:{$port} (" . Str::limit($safeMsg, 120) . '). Check MAIL_HOST, MAIL_PORT, and outbound SMTP access, then try again.';
        }

        return 'Mail error [' . class_basename($e) . " via {$mailer}]: " . Str::limit($safeMsg, 140);
    }

    /**
     * Detect non-delivering or visibly incomplete mail config outside automated tests.
     */
    public static function configurationWarning(): ?string
    {
        if (app()->environment('testing')) {
            return null;
        }

        $mailer = strtolower(trim((string) config('mail.default')));

        if (in_array($mailer, ['log', 'array'], true)) {
            return "Emails are not sent because MAIL_MAILER is set to '{$mailer}'. Set MAIL_MAILER=smtp and redeploy.";
        }

        if ($mailer === 'smtp') {
            $host = trim((string) config('mail.mailers.smtp.host'));
            $username = trim((string) config('mail.mailers.smtp.username'));
            $password = trim((string) config('mail.mailers.smtp.password'));

            if ($host === '' || str_contains($host, '@')) {
                $hostLabel = $host !== '' ? $host : 'empty';
                return "Invalid MAIL_HOST ({$hostLabel}). Set MAIL_HOST=smtp.gmail.com and MAIL_PORT=587, then redeploy.";
            }

            if (app()->environment('production') && in_array($host, ['127.0.0.1', 'localhost'], true)) {
                return "MAIL_HOST points to {$host} in production. Set MAIL_HOST=smtp.gmail.com and MAIL_PORT=587, then redeploy.";
            }

            if (! in_array($host, ['127.0.0.1', 'localhost', 'mailhog', 'mailpit'], true) && ($username === '' || $password === '')) {
                return 'SMTP credentials are incomplete (MAIL_USERNAME or MAIL_PASSWORD is empty). Check deployment environment variables and redeploy.';
            }
        }

        return null;
    }

    /**
     * Strip configured secret values from an exception message before logging or returning it.
     */
    public static function sanitize(string $message): string
    {
        $secrets = array_filter([
            config('mail.mailers.smtp.password'),
            config('mail.mailers.smtp.username'),
            config('database.connections.pgsql.password'),
            config('app.key'),
        ], static fn ($val) => is_string($val) && $val !== '');

        return str_replace($secrets, '[redacted]', $message);
    }
}

