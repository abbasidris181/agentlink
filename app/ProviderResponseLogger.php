<?php

namespace App;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use JsonException;
use Monolog\Formatter\JsonFormatter;
use stdClass;
use Throwable;

class ProviderResponseLogger
{
    /**
     * @param  array<string, int|string|null>  $context
     * @param  list<string>  $secrets
     */
    public static function response(string $service, Response $response, array $context = [], array $secrets = []): void
    {
        try {
            $body = $response->body();
            try {
                $body = json_encode(self::redact(json_decode($body, flags: JSON_THROW_ON_ERROR), $secrets), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $body = self::redactText($body, $secrets);
            }
            self::write($service, 'info', 'Provider response received.', [
                ...$context, 'http_status' => $response->status(), 'response' => $body,
            ]);
        } catch (Throwable) {
            error_log('Provider response logging failed.');
        }
    }

    /** @param array<string, int|string|null> $context */
    public static function failure(string $service, Throwable $exception, array $context = []): void
    {
        self::write($service, 'warning', 'Provider request could not complete.', [...$context, 'error_type' => $exception::class]);
    }

    /** @param array<string, int|string|null> $context */
    private static function write(string $service, string $level, string $message, array $context): void
    {
        try {
            $service = preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/', $service) ? $service : 'api';
            if ($service === 'instant-ipe') {
                Log::channel('instant_ipe')->log($level, $message, ['service' => $service, ...$context]);

                return;
            }
            Log::build([
                'driver' => 'daily',
                'path' => rtrim(config('logging.provider_responses.directory'), '/').'/'.$service.'.log',
                'level' => 'debug', 'days' => config('logging.provider_responses.days', 14),
                'permission' => 0600, 'locking' => true, 'formatter' => JsonFormatter::class,
            ])->log($level, $message, ['service' => $service, ...$context]);
        } catch (Throwable) {
            error_log('Provider response logging failed.');
        }
    }

    /** @param list<string> $secrets */
    private static function redact(mixed $value, array $secrets): mixed
    {
        if (is_string($value)) {
            return self::redactText($value, $secrets);
        }
        if (! is_array($value) && ! $value instanceof stdClass) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $normalizedKey = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string) $key));
            $redacted = preg_match('/token|password|secret|authorization|apikey|cookie/', $normalizedKey)
                ? '[REDACTED]' : self::redact($item, $secrets);
            if (is_array($value)) {
                $value[$key] = $redacted;
            } else {
                $value->{$key} = $redacted;
            }
        }

        return $value;
    }

    /** @param list<string> $secrets */
    private static function redactText(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $escaped = substr(json_encode($secret, JSON_INVALID_UTF8_SUBSTITUTE), 1, -1);
                $text = str_replace(array_unique([$escaped, rawurlencode($secret), urlencode($secret), $secret]), '[REDACTED]', $text);
            }
        }

        return preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/i', 'Bearer [REDACTED]', $text);
    }
}
