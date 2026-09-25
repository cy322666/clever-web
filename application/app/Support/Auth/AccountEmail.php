<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Email;

final class AccountEmail
{
    public static function normalize(mixed $value): string
    {
        return is_string($value) ? Str::lower(trim($value)) : '';
    }

    public static function rule(): Email
    {
        return Rule::email()
            ->rfcCompliant()
            ->withNativeValidation();
    }

    public static function isValid(mixed $value): bool
    {
        return Validator::make(
            ['email' => self::normalize($value)],
            ['email' => ['required', 'string', 'max:255', self::rule()]],
        )->passes();
    }
}
