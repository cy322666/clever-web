<?php

namespace App\Workflows\Actions;

use App\Services\Telegram;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Leek\FilamentWorkflows\Actions\Communication\SendNotificationAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Throwable;

class MultiChannelNotificationAction extends SendNotificationAction
{
    public static function workflowDescription(): string
    {
        return static::workflowTrans('description');
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    public function handle(array $config, ?WorkflowContext $context = null): array
    {
        $result = parent::handle($config, $context);

        if (($result['success'] ?? false) !== true) {
            return $result;
        }

        $recipients = $this->resolveRecipients($config, $context);
        $emailRecipients = $this->sendEmailCopies($config, $recipients);
        $telegramSent = $this->sendTelegramReport($config, $recipients);

        $output = $result['output'] ?? [];
        $output['email_sent_to'] = $emailRecipients;
        $output['email_recipient_count'] = count($emailRecipients);
        $output['telegram_sent'] = $telegramSent;

        return [
            'success' => true,
            'output' => $output,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, Model> $recipients
     * @return array<int, string>
     */
    protected function sendEmailCopies(array $config, array $recipients): array
    {
        $sentTo = [];
        $subject = (string)($config['title'] ?? 'Уведомление');
        $body = (string)($config['body'] ?? $subject);

        foreach ($recipients as $recipient) {
            $email = trim((string)($recipient->getAttribute('email') ?? ''));

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            try {
                Mail::raw($body, function ($message) use ($email, $subject): void {
                    $message->to($email)->subject($subject);
                });

                $sentTo[] = $email;
            } catch (Throwable $throwable) {
                Log::warning('Workflow notification email copy failed.', [
                    'recipient_id' => $recipient->getKey(),
                    'email' => $email,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }

        return $sentTo;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, Model> $recipients
     */
    protected function sendTelegramReport(array $config, array $recipients): bool
    {
        $token = (string)config('services.telegram.token', '');
        $chatId = (string)config('services.telegram.chat_id', '');

        if ($token === '' || $chatId === '') {
            return false;
        }

        $recipientLabels = collect($recipients)
            ->map(fn(Model $recipient): string => (string)(
            $recipient->getAttribute('email')
                ?: $recipient->getAttribute('name')
                ?: $recipient->getKey()
            ))
            ->filter()
            ->values()
            ->all();

        $text = implode("\n", array_filter([
            'Уведомление из процесса',
            'Заголовок: ' . (string)($config['title'] ?? '-'),
            'Получатели: ' . ($recipientLabels !== [] ? implode(', ', $recipientLabels) : '-'),
            '',
            (string)($config['body'] ?? ''),
        ]));

        try {
            Telegram::send($text, $chatId, $token, [], false);

            return true;
        } catch (Throwable $throwable) {
            Log::warning('Workflow notification Telegram report failed.', [
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }
}
