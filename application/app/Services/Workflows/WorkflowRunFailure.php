<?php

namespace App\Services\Workflows;

use App\Services\Workflows\Testing\WorkflowReportSanitizer;
use Leek\FilamentWorkflows\Models\WorkflowRun;

final class WorkflowRunFailure
{
    public static function message(WorkflowRun $run): ?string
    {
        if (($run->status?->value ?? (string) $run->status) !== 'failed') return null;

        $message = trim((string) $run->error_message);
        if ($message === '') {
            $message = trim((string) $run->steps->sortByDesc('id')->first(
                fn ($step) => ($step->status?->value ?? (string) $step->status) === 'failed' && filled($step->error_message),
            )?->error_message);
        }
        if ($message === '') return 'Причина ошибки не сохранилась в этом запуске. Укажите номер запуска при обращении в поддержку.';

        // Exception text may contain a credential also recorded in the request/configuration.
        $safe = WorkflowReportSanitizer::sanitize([
            'context' => $run->context_data ?? [],
            'steps' => $run->steps->map(fn ($step) => ['input' => $step->input_data, 'output' => $step->output_data])->all(),
            'message' => $message,
        ]);
        return $safe['message'];
    }
}
