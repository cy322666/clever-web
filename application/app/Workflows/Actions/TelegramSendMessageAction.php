<?php

namespace App\Workflows\Actions;

use App\Forms\Components\WorkflowValueInput;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use App\Services\Workflows\WorkflowCredentials;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Leek\FilamentWorkflows\Concerns\WorkflowAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;

class TelegramSendMessageAction
{
    use WorkflowAction;

    public static function workflowType(): string
    {
        return 'telegram_send_message';
    }

    public static function workflowName(): string
    {
        return 'Отправить сообщение';
    }

    public static function workflowDescription(): string
    {
        return 'Telegram · сообщение от бота';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-paper-airplane';
    }

    public static function workflowCategory(): string
    {
        return 'Сервисы';
    }

    public static function workflowConfigSchema(?string $modelClass = null): array
    {
        return [
            Select::make('credential_id')->label('Подключение Telegram')->options(fn () => WorkflowCredentials::options('telegram'))
                ->placeholder('Выберите бота')->native()
                ->helperText('Ваши сохранённые боты. Добавить новое подключение — кнопка +.')
                ->createOptionForm([Group::make(WorkflowCredentials::schema('telegram'))->statePath('credentials')])
                ->createOptionModalHeading('Подключить Telegram')
                ->createOptionUsing(fn (array $data, Schema $schema): int => WorkflowCredentials::saveFromForm(['provider' => 'telegram'] + $data, $schema)),
            WorkflowValueInput::make('chat_id')->label('Чат ID')->placeholder('-100… или @channel')->required(),
            WorkflowValueInput::make('text')->label('Сообщение')->multiline(6)->required()->maxLength(4096),
            WorkflowValueInput::make('parse_mode')->label('Формат')->options(['plain' => 'Обычный текст', 'HTML' => 'HTML', 'MarkdownV2' => 'Markdown V2'])->default('plain')
                ->afterStateHydrated(fn (WorkflowValueInput $component, $state) => $component->state($state ?: 'plain')),
        ];
    }

    public static function protectConfig(array $config, array $previous = []): array
    {
        if (filled($config['credential_id'] ?? null)) {
            unset($config['bot_token'], $config['bot_token_encrypted']);
            return $config;
        }
        if (filled($config['bot_token'] ?? null)) {
            $config['bot_token_encrypted'] = Crypt::encryptString(trim($config['bot_token']));
        } elseif (isset($previous['bot_token_encrypted'])) {
            $config['bot_token_encrypted'] = $previous['bot_token_encrypted'];
        }
        unset($config['bot_token']);

        return $config;
    }

    public function handle(array $config, ?WorkflowContext $context = null): array
    {
        try {
            $credentialId = $config['credential_id'] ?? null;
            if (filled($credentialId) && (!is_scalar($credentialId) || !preg_match('/^[1-9]\d*$/D', (string) $credentialId))) {
                throw new \RuntimeException('Выберите подключение Telegram из списка.');
            }
            $token = filled($credentialId)
                ? WorkflowCredentials::token((int) $credentialId, $context)
                : (isset($config['bot_token_encrypted']) ? Crypt::decryptString($config['bot_token_encrypted']) : '');
            $config = $context ? $context->resolve($config) : $config;
            $body = ['chat_id' => $config['chat_id'] ?? '', 'text' => $config['text'] ?? ''];
            if (! is_scalar($body['chat_id']) || ! preg_match('/^(?:-?\d+|@[a-zA-Z0-9_]+)$/D', (string) $body['chat_id'])) {
                throw new \RuntimeException('Укажите ID чата или @имя канала.');
            }
            if (! is_string($body['text']) || $body['text'] === '' || mb_strlen($body['text']) > 4096) {
                throw new \RuntimeException('Сообщение должно содержать от 1 до 4096 символов.');
            }
            $format = $config['parse_mode'] ?? 'plain';
            if (! in_array($format, ['plain', '', null, 'HTML', 'MarkdownV2'], true)) {
                throw new \RuntimeException('Неизвестный формат сообщения.');
            }
            if (in_array($format, ['HTML', 'MarkdownV2'], true)) {
                $body['parse_mode'] = $format;
            }
            if ($context?->getVariable('_dry_run') || $context?->getVariable('_test_mode')) {
                return ['success' => true, 'output' => ['dry_run' => true] + $body];
            }
            if (! preg_match('/^\d+:[a-zA-Z0-9_-]+$/D', $token)) {
                throw new \RuntimeException('Добавьте токен Telegram-бота.');
            }
            // Never retry a send automatically: an ambiguous timeout can already have delivered it.
            try {
                $response = Http::withoutRedirecting()->timeout(15)->post('https://api.telegram.org/bot'.$token.'/sendMessage', $body);
            } catch (\Throwable) {
                throw new \RuntimeException('Telegram не подтвердил отправку. Проверьте чат перед повтором.');
            }
            if (! $response->successful() || ! $response->json('ok')) {
                throw new \RuntimeException('Telegram отклонил сообщение (код '.$response->status().'). Проверьте токен, чат и права бота.');
            }

            return ['success' => true, 'output' => $response->json('result') ?? []];
        } catch (\Throwable $error) {
            return ['success' => false, 'error' => $error instanceof \Illuminate\Contracts\Encryption\DecryptException ? 'Не удалось прочитать токен. Сохраните его заново.' : $error->getMessage()];
        }
    }
}
