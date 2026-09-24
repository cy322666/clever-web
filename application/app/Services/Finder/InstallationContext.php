<?php

namespace App\Services\Finder;

use App\Models\Integrations\Finder\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

class InstallationContext
{
    public function encode(Setting $setting): string
    {
        return 'finder.'.Crypt::encryptString(json_encode([
            'setting_id' => (int) $setting->id,
            'user_id' => (int) $setting->user_id,
            'expires_at' => now()->addMinutes(30)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function userId(string $state): ?int
    {
        // Marketplace installations and old unsigned states retain the normal
        // account-owner lookup. Only server-issued states can select an owner.
        if (! str_starts_with($state, 'finder.')) {
            return null;
        }

        try {
            $context = json_decode(Crypt::decryptString(substr($state, 7)), true, flags: JSON_THROW_ON_ERROR);
            if ((int) ($context['expires_at'] ?? 0) > now()->timestamp
                && Setting::whereKey($context['setting_id'] ?? 0)->where('user_id', $context['user_id'] ?? 0)->exists()) {
                return (int) $context['user_id'];
            }
        } catch (Throwable) {
            // Do not expose the authorization context or encryption errors.
        }

        throw ValidationException::withMessages(['connection' => 'Ссылка подключения устарела. Откройте подключение amoCRM из настроек ещё раз.']);
    }
}
