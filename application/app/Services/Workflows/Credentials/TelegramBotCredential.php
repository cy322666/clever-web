<?php

namespace App\Services\Workflows\Credentials;

use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TelegramBotCredential implements CredentialProvider
{
    public static function label(): string
    {
        return 'Telegram';
    }

    public static function schema(bool $editing = false): array
    {
        return [TextInput::make('token')->label('Токен бота')->password()->autocomplete('new-password')
            ->required(!$editing)->maxLength(300)
            ->helperText($editing
                ? 'Оставьте пустым, чтобы сохранить текущий токен.'
                : 'Токен из BotFather. Имя бота определится автоматически при подключении.')];
    }

    public static function prepare(array $data, bool $editing = false): ?array
    {
        Validator::make($data, ['token' => 'nullable|string|max:300'])->validate();
        $token = trim((string) ($data['token'] ?? ''));
        if ($token === '' && $editing) return null;
        if (!preg_match('/^\d+:[a-zA-Z0-9_-]+$/D', $token)) {
            throw ValidationException::withMessages(['token' => 'Укажите токен бота из BotFather.']);
        }

        // getMe only checks authorization and reads bot identity; it never sends a message.
        try {
            $response = Http::withoutRedirecting()->connectTimeout(5)->timeout(10)
                ->get('https://api.telegram.org/bot'.$token.'/getMe');
        } catch (\Throwable) {
            // Transport exceptions contain the token in their URL. Never return or log them.
            throw ValidationException::withMessages(['token' => 'Не удалось связаться с Telegram. Подключение не изменено; попробуйте ещё раз.']);
        }
        $bot = $response->json('result');
        if (!$response->successful() || $response->json('ok') !== true || !is_array($bot)
            || ($bot['is_bot'] ?? null) !== true || !is_numeric($bot['id'] ?? null) || $bot['id'] <= 0) {
            throw ValidationException::withMessages(['token' => 'Telegram не подтвердил токен. Проверьте его в BotFather.']);
        }
        $username = $bot['username'] ?? null;
        $name = is_string($username) && preg_match('/^[a-zA-Z0-9_]+$/D', $username)
            ? '@'.$username
            : ((is_string($bot['first_name'] ?? null) ? trim($bot['first_name']) : '') ?: 'Бот #'.$bot['id']);

        return ['name' => mb_substr($name, 0, 100), 'secret' => $token];
    }
}
