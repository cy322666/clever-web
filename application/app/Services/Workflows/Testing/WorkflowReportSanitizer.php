<?php

namespace App\Services\Workflows\Testing;

use JsonSerializable;
use stdClass;

/** Removes credentials from diagnostic exports without flattening execution data. */
final class WorkflowReportSanitizer
{
    private const REDACTED = '[REDACTED]';

    public static function sanitize(mixed $value): mixed
    {
        $secrets = [];
        self::collectSecrets($value, $secrets);
        // Replace longer values first when a credential is a prefix of another.
        usort($secrets, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return self::walk($value, array_values(array_unique($secrets)));
    }

    private static function walk(mixed $value, array $secrets): mixed
    {
        if (is_object($value)) {
            $data = $value instanceof JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
            $safe = self::walk($data, $secrets);

            return $value instanceof stdClass && is_array($safe) ? (object) $safe : $safe;
        }

        if (is_array($value)) {
            $sensitivePair = self::isSensitivePair($value);
            foreach ($value as $key => $item) {
                $value[$key] = self::isSecretKey((string) $key) || ($sensitivePair && in_array($key, ['value', 'values'], true))
                    ? self::mask($item)
                    : self::walk($item, $secrets);
            }

            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $decoded = self::decodeContainer($value);
        if ($decoded !== null) {
            return json_encode(self::walk($decoded, $secrets), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        // Exception messages often echo the same secret already present in request data.
        foreach ($secrets as $secret) {
            $value = str_replace(array_unique([$secret, rawurlencode($secret), urlencode($secret)]), self::REDACTED, $value);
        }

        // Both Telegram API endpoints and download URLs contain the bot token in the path.
        $value = preg_replace('~((?:https?://)?api\.telegram\.org/(?:file/)?bot)[^/\s?#"\'<>]+~i', '$1'.self::REDACTED, $value) ?? $value;
        $value = preg_replace('~\b\d{5,}:[A-Za-z0-9_-]{20,}\b~', self::REDACTED, $value) ?? $value;

        // These routes use a secret path segment rather than a query parameter.
        $value = preg_replace('~(/(?:api/)?(?:workflows/webhook|amocrm/workflows/hook)/[^/\s?#"\'<>]+/)[^/\s?#"\'<>]+~i', '$1'.self::REDACTED, $value) ?? $value;
        $value = preg_replace('~(https?://)[^/\s@]+@~i', '$1'.self::REDACTED.'@', $value) ?? $value;

        // Preserve useful query parameters (entity IDs, pages, etc.) in signed URLs.
        $value = preg_replace_callback('~([?&#;])([^=\s&?#;]+)=([^\s&?#;"\'<>]*)~', function (array $match): string {
            $key = rawurldecode($match[2]);
            return self::isSecretKey($key) || in_array(strtolower($key), ['key', 'code', 'state'], true)
                ? $match[1].$match[2].'='.self::REDACTED
                : $match[0];
        }, $value) ?? $value;

        $value = preg_replace('~\b(Bearer|Basic)\s+[^\s,"\'<>]+~i', '$1 '.self::REDACTED, $value) ?? $value;
        $value = preg_replace('~\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b~', self::REDACTED, $value) ?? $value;

        // Also handle raw headers and form-encoded bodies embedded in diagnostic text.
        return preg_replace_callback('~\b([A-Za-z][A-Za-z0-9_-]*)\s*([:=])\s*([^\r\n,&;]+)~', function (array $match): string {
            return self::isSecretKey($match[1])
                ? $match[1].$match[2].($match[2] === ':' ? ' ' : '').self::REDACTED
                : $match[0];
        }, $value) ?? $value;
    }

    private static function isSecretKey(string $key): bool
    {
        $key = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key;
        $key = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $key) ?? $key, '_'));

        if (in_array($key, ['auth', 'credentials', 'credential', 'secrets', 'refresh', 'sig', 'encrypted', 'ciphertext'], true)) {
            return true;
        }

        return (bool) preg_match('/(?:^|_)(?:tokens?|secrets?|password|passwd|passphrase|authorization|cookie|set_cookie|api_key|private_key|signing_key|encryption_key|secret_key|auth_key|signature|credential|credentials|authorization_code|auth_code|code_verifier)(?:_(?:encrypted|protected|ciphertext|hash))?$/', $key);
    }

    private static function isSensitivePair(array $value): bool
    {
        foreach (['name', 'key', 'header'] as $name) {
            if (is_string($value[$name] ?? null) && self::isSecretKey($value[$name])) {
                return true;
            }
        }

        return false;
    }

    private static function mask(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::mask(...), $value);
        }
        if (is_object($value)) {
            $result = new stdClass;
            foreach (get_object_vars($value) as $key => $item) {
                $result->{$key} = self::mask($item);
            }

            return $result;
        }

        return $value === null ? null : self::REDACTED;
    }

    private static function collectSecrets(mixed $value, array &$secrets, bool $sensitive = false): void
    {
        if (is_object($value)) {
            $value = $value instanceof JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
        }
        if (is_array($value)) {
            $sensitivePair = self::isSensitivePair($value);
            foreach ($value as $key => $item) {
                self::collectSecrets($item, $secrets, $sensitive || self::isSecretKey((string) $key)
                    || ($sensitivePair && in_array($key, ['value', 'values'], true)));
            }
        } elseif (is_string($value)) {
            if ($sensitive && strlen($value) >= 8) {
                $secrets[] = $value;
            }
            $decoded = self::decodeContainer($value);
            if ($decoded !== null) {
                self::collectSecrets($decoded, $secrets, $sensitive);
            }
        }
    }

    private static function decodeContainer(string $value): array|stdClass|null
    {
        $first = substr(ltrim($value), 0, 1);
        if ($first !== '{' && $first !== '[') {
            return null;
        }
        $decoded = json_decode($value);

        return json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || $decoded instanceof stdClass) ? $decoded : null;
    }
}
