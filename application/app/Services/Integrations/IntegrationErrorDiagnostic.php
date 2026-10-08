<?php

namespace App\Services\Integrations;

use Throwable;

final class IntegrationErrorDiagnostic
{
    public static function describe(string $error, ?Throwable $exception = null): array
    {
        $summary = self::summary($error);
        $type = $exception ? class_basename($exception) : null;
        $location = $exception ? self::location($exception) : null;
        $endpoint = null;
        if (preg_match('~\b(GET|POST|PATCH|PUT|DELETE) (/api/v[24]/[a-z0-9_/-]{1,160})(?=[?\s:]|$)~i', $error, $match)) {
            $endpoint = $match[1].' '.$match[2];
        }

        return [
            'summary' => $summary,
            'type' => $type,
            'location' => $location,
            'endpoint' => $endpoint,
            // Preserve grouping across record IDs without merging unrelated programming errors.
            'signature' => hash('sha256', $summary ?? (string) preg_replace('/\b\d+\b/', '#', $error)),
        ];
    }

    private static function summary(string $error): ?string
    {
        // Extract known technical facts, never forward arbitrary exception text or response bodies.
        if (preg_match('/(?:parse error|syntax error)/i', $error)) {
            $summary = 'Ошибка синтаксиса PHP';
            if (preg_match('/unexpected\s+(?:token\s+)?[\x27\x22]([=;:,{}()\[\]])[\x27\x22]/i', $error, $match)) {
                $summary .= ': неожиданный символ «'.$match[1].'»';
            }
            if (preg_match('/(?:on|at) line\s+(\d+)/i', $error, $match)) {
                $summary .= ', строка '.$match[1];
            }

            return $summary.'.';
        }

        if (preg_match('/"path"\s*:\s*"([a-z_][a-z0-9_.\[\]]{0,100})"\s*,\s*"detail"\s*:\s*"This value should be of type (int|string|bool|array|float)\./i', $error, $match)) {
            $type = match (strtolower($match[2])) {
                'int' => 'целое число', 'string' => 'строку', 'bool' => 'логическое значение',
                'array' => 'массив', 'float' => 'число',
            };

            return 'API отклонил поле '.$match[1].': ожидает '.$type.'.';
        }

        if (preg_match('/Call to undefined method ([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]{0,160})::([a-zA-Z_][a-zA-Z0-9_]{0,80})\(/', $error, $match)) {
            return 'Вызван несуществующий метод '.$match[1].'::'.$match[2].'().';
        }
        if (str_contains($error, 'Undefined array key') || str_contains($error, 'Undefined index')) {
            return 'Обращение к отсутствующему ключу массива.';
        }
        if (preg_match('/must be of type (int|string|array|bool|float), (int|string|array|bool|float|null) given/i', $error, $match)) {
            return 'Неверный тип аргумента: ожидается '.$match[1].', передан '.$match[2].'.';
        }
        if (preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/', $error, $match)) {
            return 'Ошибка базы данных, SQLSTATE '.$match[1].'.';
        }
        if (stripos($error, 'Permission denied') !== false) {
            return 'Операция отклонена: недостаточно прав доступа.';
        }
        if (preg_match('/^Command failed \(exit (-?\d+)\)$/D', $error, $match)) {
            return 'Команда завершилась с кодом '.$match[1].'.';
        }

        return null;
    }

    private static function location(Throwable $exception): ?string
    {
        $file = str_replace('\\', '/', $exception->getFile());
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $file = str_starts_with($file, $base) ? substr($file, strlen($base)) : basename($file);

        return preg_match('~^[a-zA-Z0-9_./-]{1,220}$~D', $file)
            ? $file.':'.$exception->getLine() : null;
    }
}
