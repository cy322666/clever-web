<?php

namespace App\Services\Ai;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use phpseclib3\Crypt\RSA;

class IamTokenService
{
    private const IAM_URL = 'https://iam.api.cloud.yandex.net/iam/v1/tokens';

    public function getToken(): string
    {
        $cacheKey = config('services.yandex_iam.cache_key', 'yc_iam_token');
        $ttl = (int)config('services.yandex_iam.ttl', 39600); // ~11 часов

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $keyData = $this->loadAuthorizedKey();
        $jwt = $this->makeJwtPs256($keyData);

        $response = Http::timeout(15)
            ->acceptJson()
            ->asJson()
            ->post(self::IAM_URL, [
                'jwt' => $jwt,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                'YC IAM token request failed: ' . $response->status() . ' ' . $response->body()
            );
        }

        $iamToken = $response->json('iamToken');
        if (!is_string($iamToken) || $iamToken === '') {
            throw new RuntimeException('YC IAM response does not contain iamToken');
        }

        Cache::put($cacheKey, $iamToken, $ttl);

        return $iamToken;
    }

    private function loadAuthorizedKey(): array
    {
        $path = '/var/www/html/storage/app/yc/authorized-key.json';

//        if (! is_string($path) || $path === '') {
//            throw new RuntimeException('YANDEX_IAM_KEY_FILE is not set');
//        }

        if (!file_exists($path)) {
            throw new RuntimeException("Authorized key file not found: {$path}");
        }

        $data = json_decode((string)file_get_contents($path), true);

        if (!is_array($data)) {
            throw new RuntimeException('Invalid authorized key JSON');
        }

        foreach (['id', 'service_account_id', 'private_key'] as $field) {
            if (empty($data[$field]) || !is_string($data[$field])) {
                throw new RuntimeException("Authorized key missing field: {$field}");
            }
        }

        return $data;
    }

    /**
     * JWT PS256 (RSASSA-PSS + SHA-256) using phpseclib
     */
    private function makeJwtPs256(array $key): string
    {
        $now = CarbonImmutable::now('UTC');

        $header = [
            'typ' => 'JWT',
            'alg' => 'PS256',
            'kid' => $key['id'],
        ];

        $payload = [
            'aud' => self::IAM_URL,
            'iss' => $key['service_account_id'],
            'iat' => $now->timestamp,
            'exp' => $now->addHour()->timestamp, // JWT короткоживущий
            'jti' => (string)Str::uuid(),
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $signingInput = $encodedHeader . '.' . $encodedPayload;

        $signature = $this->signPs256Phpseclib($signingInput, $key['private_key']);

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * PS256 signature: RSA-PSS (MGF1 SHA-256, saltLen 32)
     */
    private function signPs256Phpseclib(string $data, string $privateKeyPem): string
    {
        try {
            $rsa = RSA::loadPrivateKey($privateKeyPem)
                ->withPadding(RSA::SIGNATURE_PSS)
                ->withHash('sha256')
                ->withMGFHash('sha256')
                ->withSaltLength(32);

            return $rsa->sign($data); // raw binary signature
        } catch (\Throwable $e) {
            throw new RuntimeException('PS256 signing failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function forgetToken(): void
    {
        Cache::forget(config('services.yandex_iam.cache_key', 'yc_iam_token'));
    }
}
