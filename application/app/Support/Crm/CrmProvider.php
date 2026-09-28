<?php

namespace App\Support\Crm;

class CrmProvider
{
    public const AMOCRM = 'amocrm';

    public const KOMMO = 'kommo';

    public static function normalize(?string $provider): string
    {
        return $provider === self::KOMMO ? self::KOMMO : self::AMOCRM;
    }

    public static function authorizationUrl(?string $provider): string
    {
        return self::normalize($provider) === self::KOMMO
            ? 'https://www.kommo.com/oauth'
            : 'https://www.amocrm.ru/oauth/';
    }

    public static function label(?string $provider): string
    {
        return self::normalize($provider) === self::KOMMO ? 'Kommo' : 'amoCRM';
    }
}
