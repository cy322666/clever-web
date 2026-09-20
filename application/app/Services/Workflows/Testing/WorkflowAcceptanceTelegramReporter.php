<?php

declare(strict_types=1);

namespace App\Services\Workflows\Testing;

use App\Services\Workflows\WorkflowAmoReadCatalog;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Sends a small diagnostic summary, never the account's raw data or historical runs. */
final class WorkflowAcceptanceTelegramReporter
{
    private const MAX_LENGTH = 3900;

    /** @return array{ok: bool, message_id: int|null, error: string|null} */
    public function notify(array $report, string $reportPath): array
    {
        $token = trim((string) config('workflow_acceptance.telegram.token', ''));
        $chatId = trim((string) config('workflow_acceptance.telegram.chat_id', ''));
        if ($token === '' || $chatId === '') {
            return ['ok' => false, 'message_id' => null, 'error' => 'Не настроен Telegram для результатов автотестов.'];
        }

        $body = [
            'chat_id' => $chatId,
            'text' => $this->format($report, $reportPath),
            'disable_web_page_preview' => true,
        ];
        $threadId = trim((string) config('workflow_acceptance.telegram.message_thread_id', ''));
        if ($threadId !== '') {
            $body['message_thread_id'] = $threadId;
        }

        try {
            // No implicit retries: a timeout may mean Telegram already accepted this message.
            $response = Http::asForm()->connectTimeout(5)->timeout(15)
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', $body);
            $messageId = $response->json('result.message_id');
            if (! $response->successful() || $response->json('ok') !== true || ! is_numeric($messageId)) {
                return [
                    'ok' => false,
                    'message_id' => null,
                    'error' => 'Telegram не подтвердил доставку отчёта (HTTP '.$response->status().').',
                ];
            }

            return ['ok' => true, 'message_id' => (int) $messageId, 'error' => null];
        } catch (Throwable) {
            // Request exception messages contain the token-bearing URL. Do not log or return them.
            return ['ok' => false, 'message_id' => null, 'error' => 'Не удалось подтвердить доставку отчёта в Telegram: ошибка соединения.'];
        }
    }

    public function format(array $report, string $reportPath): string
    {
        $report['_notification_secrets'] = [(string) config('workflow_acceptance.telegram.token', '')];
        $report = WorkflowReportSanitizer::sanitize($report);
        unset($report['_notification_secrets']);
        $personalValues = [];
        $this->collectPersonalValues($report, $personalValues);
        $clean = fn (mixed $value, int $limit = 230): string => $this->cleanLine($value, $personalValues, $limit);

        $counts = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
        $failures = [];
        foreach ((array) ($report['cases'] ?? []) as $case) {
            if (! is_array($case)) {
                $counts['failed']++;
                $failures[] = 'Повреждён результат проверки.';
                continue;
            }
            $status = $case['status'] ?? 'failed';
            if (! is_string($status) || ! isset($counts[$status]) || ($case['verification']['passed'] ?? null) === false) {
                $status = 'failed';
            }
            $counts[$status]++;
            if ($status === 'failed') {
                $name = $clean($this->caseName($case), 140);
                $failures[] = $name.': '.$this->caseError($case, $clean);
            }
        }

        $critical = [];
        if (! empty($report['fatal_error'])) {
            $critical[] = 'Прогон остановлен: '.$clean($report['fatal_error']);
        }
        if (! empty($report['report_write_errors'])) {
            $critical[] = 'Не удалось полностью сохранить подробный отчёт.';
        }
        foreach ((array) ($report['cleanup'] ?? []) as $name => $result) {
            if ($result === false || (is_array($result) && ($result['ok'] ?? null) === false)) {
                $reason = is_array($result) ? ($result['error'] ?? 'восстановление не подтверждено') : 'восстановление не подтверждено';
                $critical[] = 'Не восстановлено: '.$clean($name, 80).' — '.$clean($reason, 180);
            }
        }
        if (empty($report['finished_at'])) {
            $critical[] = 'Завершение прогона не подтверждено.';
        }
        if ($counts['passed'] + $counts['failed'] === 0) {
            $critical[] = 'Ни одна проверка не выполнена.';
        }

        $hasErrors = $critical !== [] || $counts['failed'] > 0;
        $headline = $hasErrors ? '🔴 Автотесты amoCRM: есть ошибки' : ($counts['skipped'] > 0 ? '🟡 Автотесты amoCRM: есть пропуски' : '🟢 Автотесты amoCRM: проверки пройдены');
        $domain = $clean($report['account']['subdomain'] ?? 'не определён', 90);
        $lines = [
            $headline,
            'Аккаунт: '.$domain,
            'Запуск: '.$this->runTime($report),
            'Успешно: '.$counts['passed'].' · Ошибок: '.$counts['failed'].' · Пропущено: '.$counts['skipped'],
        ];
        if (($report['suite'] ?? '') === 'read_only_acceptance') {
            $lines[] = 'Режим: только чтение; изменения в amoCRM не проверялись.';
        }
        foreach ($critical as $problem) {
            $lines[] = '⚠️ '.$problem;
        }
        if ($failures !== []) {
            $lines[] = '';
            $lines[] = 'Где сломалось:';
            foreach (array_slice($failures, 0, 7) as $failure) {
                $lines[] = '• '.$failure;
            }
            if (count($failures) > 7) {
                $lines[] = 'Ещё ошибок: '.(count($failures) - 7).'. Подробности в отчёте.';
            }
        }
        if ($counts['skipped'] > 0) {
            $lines[] = 'Пропуски не подтверждают работоспособность этих нод.';
        }
        // Only the local basename, not a public link, full server path or full CRM export.
        $reference = "\nОтчёт на сервере: ".$clean(basename($reportPath), 120);
        $message = implode("\n", $lines);
        $room = self::MAX_LENGTH - mb_strlen($reference);
        if (mb_strlen($message) > $room) {
            $message = mb_substr($message, 0, $room - 35)."\n…Подробности в полном отчёте.";
        }

        return $message.$reference;
    }

    private function caseError(array $case, callable $clean): string
    {
        foreach ([$case['error'] ?? null, $case['result']['error'] ?? null, $case['verification']['actual_error'] ?? null, $case['verification']['reason'] ?? null] as $error) {
            if (is_string($error) && trim($error) !== '') {
                return $clean($error);
            }
        }
        $differences = $case['verification']['differences'] ?? [];
        if (is_array($differences) && $differences !== []) {
            $details = [];
            foreach (array_slice($differences, 0, 3, true) as $field => $difference) {
                $label = $clean($field, 60);
                if (is_array($difference)) {
                    $expected = $this->safeComparisonValue($difference['expected'] ?? null);
                    $actual = $this->safeComparisonValue($difference['actual'] ?? null);
                    $details[] = $label.' — ожидалось '.$expected.', получено '.$actual;
                } else {
                    $details[] = $label.' — значение не совпало';
                }
            }

            return $clean('После запроса данные не совпали: '.implode('; ', $details));
        }

        return 'Результат не прошёл проверку. Ожидаемые и полученные данные — в отчёте.';
    }

    private function caseName(array $case): string
    {
        $id = is_string($case['id'] ?? null) ? $case['id'] : (string) ($case['type'] ?? 'Проверка');
        if (is_string($case['label'] ?? null) && trim($case['label']) !== '') {
            return $case['label'].' · '.$id;
        }
        if (str_starts_with($id, 'read:')) {
            $operation = WorkflowAmoReadCatalog::operations()[substr($id, 5)] ?? null;
            if ($operation !== null) {
                return $operation['group'].' / '.$operation['name'].' · '.$id;
            }
        }
        $label = match ($id) {
            'create_lead' => 'Создание сделки',
            'update_lead' => 'Обновление сделки',
            'query_leads' => 'Получение сделок',
            'find_lead' => 'Поиск сделки',
            'lead_note' => 'Примечание к сделке',
            'lead_tags' => 'Теги сделки',
            'copy_lead' => 'Копирование сделки',
            'get_contact' => 'Получение контакта',
            'update_contact' => 'Обновление контакта',
            'contact_note' => 'Примечание к контакту',
            'link_contact' => 'Привязка контакта',
            'contact_leads' => 'Сделки контакта',
            'unlink_contact' => 'Отвязка контакта',
            'create_task' => 'Создание задачи',
            'filter_real_leads', 'filter_no_matches' => 'Фильтрация сделок',
            'condition_true', 'condition_false' => 'Условие',
            'javascript' => 'JavaScript',
            'delay' => 'Пауза',
            'http_request' => 'HTTP-запрос',
            'missing_note_text' => 'Проверка пустого примечания',
            'invalid_salesbot_config' => 'Проверка настроек Salesbot',
            'invalid_contact_id' => 'Проверка ID контакта',
            'invalid_update_json' => 'Проверка JSON-тела',
            default => null,
        };

        return $label === null ? $id : $label.' · '.$id;
    }

    private function safeComparisonValue(mixed $value): string
    {
        // Names, message text, email addresses and arbitrary result objects stay in the private report.
        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'пусто';
        }

        return is_string($value) ? 'текст (скрыт)' : 'данные (скрыты)';
    }

    private function runTime(array $report): string
    {
        try {
            if (empty($report['started_at'])) {
                return 'время не записано';
            }
            $started = new DateTimeImmutable((string) $report['started_at']);
            $label = $started->setTimezone(new DateTimeZone('Europe/Kaliningrad'))->format('d.m.Y H:i').' (Калининград)';
            if (! empty($report['finished_at'])) {
                $seconds = max(0, (new DateTimeImmutable((string) $report['finished_at']))->getTimestamp() - $started->getTimestamp());
                $label .= ' · '.($seconds < 60 ? $seconds.' с' : intdiv($seconds, 60).' мин '.$seconds % 60 .' с');
            }

            return $label;
        } catch (Throwable) {
            return 'время не распознано';
        }
    }

    private function cleanLine(mixed $value, array $personalValues, int $limit): string
    {
        if (! is_scalar($value)) {
            return 'Подробности в отчёте';
        }
        $text = (string) $value;
        foreach ($personalValues as $personalValue) {
            $text = str_replace($personalValue, '[личные данные скрыты]', $text);
        }
        $text = preg_replace('~https?://[^\s<>"\']+~iu', '[URL скрыт]', $text) ?? $text;
        $text = preg_replace('~[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}~iu', '[email скрыт]', $text) ?? $text;
        $text = preg_replace('~(?<!\w)\+\d[\d ()\-]{8,}\d~u', '[телефон скрыт]', $text) ?? $text;
        // A transport exception may append the entire JSON response; do not forward it.
        $text = preg_replace('~\s*[\{\[].*[\}\]]~us', ' [подробности скрыты]', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? 'Подробности в отчёте');

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }

    private function collectPersonalValues(mixed $data, array &$values): void
    {
        if (! is_array($data)) {
            return;
        }
        foreach ($data as $key => $value) {
            if (is_string($value) && mb_strlen($value) >= 3 && in_array((string) $key, ['name', 'first_name', 'last_name', 'email', 'phone', 'address', 'text'], true)) {
                $values[] = $value;
            } elseif (is_array($value)) {
                $this->collectPersonalValues($value, $values);
            }
        }
        $values = array_values(array_unique($values));
    }
}
