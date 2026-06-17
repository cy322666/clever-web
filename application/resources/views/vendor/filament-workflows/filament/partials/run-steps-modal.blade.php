@php
    $statusValue = $run->status?->value ?? (string) $run->status;
    $durationSeconds = $run->getDurationInSeconds();
    $duration = match (true) {
        $durationSeconds === null => '-',
        $durationSeconds < 60 => $durationSeconds . ' сек.',
        default => floor($durationSeconds / 60) . ' мин. ' . ($durationSeconds % 60) . ' сек.',
    };

    $actionLabel = static fn (?string $type): string => [
        'control-condition' => 'Условие',
        'run_workflow' => 'Запустить процесс',
        'amocrm_create_lead' => 'Создать сделку',
        'amocrm_create_contact' => 'Создать контакт',
        'amocrm_create_company' => 'Создать компанию',
        'amocrm_copy_lead' => 'Копировать сделку',
        'amocrm_update_fields' => 'Сменить значение поля',
        'amocrm_update_lead_fields' => 'Изменить сделку',
        'amocrm_update_contact_fields' => 'Изменить контакт',
        'amocrm_update_company_fields' => 'Изменить компанию',
        'amocrm_create_task' => 'Поставить задачу',
        'amocrm_add_note' => 'Добавить примечание',
        'amocrm_change_tags' => 'Сменить теги',
        'amocrm_change_lead_status' => 'Сменить статус сделки',
        'amocrm_find_entity' => 'Найти сущность',
        'amocrm_link_entity' => 'Прикрепить сущность',
        'amocrm_unlink_entity' => 'Открепить сущность',
    ][$type ?? ''] ?? ($type ?: 'Действие');
    $entityLinkService = app(\App\Services\Workflows\WorkflowRunEntityLinkService::class);
    $triggerDescription = \App\Filament\WorkflowBuilder\Resources\WorkflowRunResource::triggerDescription($run);
    $entityLabel = static fn (?string $entity): string => [
        'lead' => 'Сделка',
        'contact' => 'Контакт',
        'company' => 'Компания',
        'customer' => 'Покупатель',
        'task' => 'Задача',
    ][$entity ?? ''] ?? ($entity ?: 'Сущность');
    $entitySearchResultLabel = static fn (?string $entity, bool $found): string => [
        'lead' => $found ? 'Сделка найдена' : 'Сделка не найдена',
        'contact' => $found ? 'Контакт найден' : 'Контакт не найден',
        'company' => $found ? 'Компания найдена' : 'Компания не найдена',
        'customer' => $found ? 'Покупатель найден' : 'Покупатель не найден',
    ][$entity ?? ''] ?? ($found ? 'Сущность найдена' : 'Сущность не найдена');
    $operatorLabel = static fn (?string $operator): string => [
        'equals' => 'равно',
        'not_equals' => 'не равно',
        'strict_equals' => 'строго равно',
        'gt' => 'больше',
        'gte' => 'больше или равно',
        'lt' => 'меньше',
        'lte' => 'меньше или равно',
        'contains' => 'содержит',
        'not_contains' => 'не содержит',
        'starts_with' => 'начинается с',
        'ends_with' => 'заканчивается на',
        'in' => 'в списке',
        'not_in' => 'не в списке',
        'is_empty' => 'пусто',
        'is_not_empty' => 'не пусто',
        'is_null' => 'не заполнено',
        'is_not_null' => 'заполнено',
        'is_true' => 'истина',
        'is_false' => 'ложь',
        'matches' => 'соответствует шаблону',
    ][$operator ?? ''] ?? ($operator ?: '-');
    $conditionValueLabel = static function (mixed $value): string {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-';
        }

        $value = trim((string)$value);

        if ($value === '') {
            return '-';
        }

        return \App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog::label($value, true) ?? $value;
    };
    $humanVariableLabel = static function (mixed $value) use ($entityLabel, $conditionValueLabel): string {
        $value = trim((string)$value);

        if ($value === '') {
            return '-';
        }

        if (preg_match('/^\{\{found_(lead|contact|company|customer)_(\d+)\.(id|exists|type)\}\}$/', $value, $matches)) {
            $entity = mb_strtolower($entityLabel($matches[1]));

            return match ($matches[3]) {
                'id' => 'ID найденной сущности: ' . $entity,
                'exists' => 'Найдена сущность: ' . $entity,
                'type' => 'Тип найденной сущности: ' . $entity,
                default => $value,
            };
        }

        return $conditionValueLabel($value);
    };
    $conditionRowLabel = static function (array $condition) use ($entityLabel, $operatorLabel, $humanVariableLabel): string {
        $left = trim((string)($condition['left'] ?? ''));
        $operator = (string)($condition['operator'] ?? '');
        $right = $condition['right'] ?? null;

        if (preg_match('/^\{\{found_(lead|contact|company|customer)_(\d+)\.(id|exists)\}\}$/', $left, $matches)) {
            $entity = $entityLabel($matches[1]);

            if (in_array($operator, ['is_not_empty', 'is_not_null', 'is_true'], true)) {
                return $entity . ' найден';
            }

            if (in_array($operator, ['is_empty', 'is_null', 'is_false'], true)) {
                return $entity . ' не найден';
            }
        }

        $text = $humanVariableLabel($left) . ' ' . $operatorLabel($operator);

        if (!in_array($operator, ['is_empty', 'is_not_empty', 'is_null', 'is_not_null', 'is_true', 'is_false'], true)) {
            $text .= ' ' . $humanVariableLabel($right);
        }

        return $text;
    };
    $conditionResultLabel = static function (array $condition, mixed $result) use ($entityLabel): ?string {
        if (!is_bool($result)) {
            return null;
        }

        $left = trim((string)($condition['left'] ?? ''));
        $operator = (string)($condition['operator'] ?? '');

        if (!preg_match('/^\{\{found_(lead|contact|company|customer)_(\d+)\.(id|exists)\}\}$/', $left, $matches)) {
            return null;
        }

        if (!in_array($operator, ['is_empty', 'is_not_empty', 'is_null', 'is_not_null', 'is_true', 'is_false'], true)) {
            return null;
        }

        $entity = mb_strtolower($entityLabel($matches[1]));
        $expected = in_array($operator, ['is_not_empty', 'is_not_null', 'is_true'], true)
            ? 'найден'
            : 'не найден';

        return 'Проверка: ' . $entity . ' ' . $expected . ' — ' . ($result ? 'да' : 'нет');
    };
@endphp

<div class="space-y-5">
    <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4 dark:border-gray-700 dark:bg-gray-950/40">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Статус</div>
                <div class="mt-1">
                    <span @class([
                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset',
                        'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20' => in_array($statusValue, ['pending', 'cancelled'], true),
                        'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/20' => $statusValue === 'running',
                        'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-400/10 dark:text-yellow-400 dark:ring-yellow-400/20' => $statusValue === 'paused',
                        'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/20' => $statusValue === 'completed',
                        'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-400/10 dark:text-red-400 dark:ring-red-400/20' => $statusValue === 'failed',
                    ])>
                        @if($run->status?->getIcon())
                            <x-filament::icon :icon="$run->status->getIcon()" class="h-4 w-4"/>
                        @endif
                        {{ $run->status?->getLabel() ?? $statusValue }}
                    </span>
                </div>
            </div>

            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Источник</div>
                <div class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ $triggerDescription }}
                </div>
            </div>

            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Старт</div>
                <div class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ $run->started_at?->timezone('Europe/Moscow')->format('Y-m-d H:i:s') ?? 'не запущен' }}
                </div>
            </div>

            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Длительность
                </div>
                <div class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ $duration }}
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="mb-3 flex items-center justify-between gap-3">
            <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                Timeline запуска
            </h4>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ $run->steps->count() }} шаг(ов)
            </span>
        </div>

        <div class="space-y-3">
            @forelse($run->steps as $index => $step)
                @php
                    $stepStatus = $step->status?->value ?? (string) $step->status;
                    $type = $step->action_type ?? $step->step_type;
                    $entityLinks = $entityLinkService->forStep($run, $step);
                    $inputData = (array)($step->input_data ?? []);
                    $outputData = (array)($step->output_data ?? []);
                    $isFindEntityStep = $type === 'amocrm_find_entity';
                    $isConditionStep = $type === 'control-condition';
                    $foundEntityType = (string)($outputData['entity_type'] ?? $inputData['target_entity'] ?? '');
                    $foundEntityId = $outputData['entity_id'] ?? null;
                    $foundEntityState = array_key_exists('found', $outputData) ? (bool)$outputData['found'] : null;
                    $conditionPassed = array_key_exists('passed', $outputData) ? (bool)$outputData['passed'] : null;
                    $conditionBranch = ($outputData['branch'] ?? null) === 'false' ? 'Нет' : 'Да';
                    $conditionLogic = ($inputData['logic'] ?? 'and') === 'or' ? 'ИЛИ' : 'И';
                    $conditionResults = (array)($outputData['condition_results'] ?? []);
                    $conditionRows = array_values((array)($inputData['conditions'] ?? []));
                @endphp

                <div @class([
                    'rounded-xl border bg-white p-4 shadow-sm dark:bg-gray-900',
                    'border-gray-200 dark:border-gray-700' => in_array($stepStatus, ['pending', 'skipped'], true),
                    'border-blue-200 bg-blue-50/50 dark:border-blue-900/50 dark:bg-blue-950/20' => $stepStatus === 'running',
                    'border-green-200 bg-green-50/50 dark:border-green-900/50 dark:bg-green-950/20' => $stepStatus === 'completed',
                    'border-red-200 bg-red-50/50 dark:border-red-900/50 dark:bg-red-950/20' => $stepStatus === 'failed',
                ])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-600 dark:bg-gray-800 dark:text-gray-300">
                                {{ $index + 1 }}
                            </span>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h5 class="text-sm font-semibold text-gray-950 dark:text-white">
                                        {{ $actionLabel($type) }}
                                    </h5>
                                </div>

                                @if($entityLinks !== [])
                                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                        @foreach($entityLinks as $entityLink)
                                            <a
                                                href="{{ $entityLink['url'] }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="inline-flex items-center gap-1.5 font-medium text-primary-600 outline-none transition hover:text-primary-500 hover:underline focus-visible:ring-0 dark:text-primary-400 dark:hover:text-primary-300"
                                            >
                                                <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="h-4 w-4"/>
                                                {{ $entityLink['label'] }} #{{ $entityLink['id'] }}
                                            </a>
                                        @endforeach
                                    </div>
                                @endif

                                @if($isFindEntityStep)
                                    <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-950/40 dark:text-gray-200">
                                        @if($foundEntityState === true)
                                            <span class="font-semibold text-green-700 dark:text-green-300">{{ $entitySearchResultLabel($foundEntityType, true) }}</span>
                                        @elseif($foundEntityState === false)
                                            <span class="font-semibold text-red-700 dark:text-red-300">{{ $entitySearchResultLabel($foundEntityType, false) }}</span>
                                        @else
                                            <span class="font-semibold text-gray-700 dark:text-gray-200">Результат поиска неизвестен</span>
                                        @endif
                                        @if($foundEntityId)
                                            <span class="ml-2 font-mono text-xs text-gray-500 dark:text-gray-400">#{{ $foundEntityId }}</span>
                                        @endif
                                    </div>
                                @endif

                                @if($isConditionStep)
                                    <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-950/40 dark:text-gray-200">
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <span class="text-gray-500 dark:text-gray-400">Результат:</span>
                                            @if($conditionPassed === true)
                                                <span class="font-semibold text-green-700 dark:text-green-300">Да</span>
                                            @elseif($conditionPassed === false)
                                                <span class="font-semibold text-red-700 dark:text-red-300">Нет</span>
                                            @else
                                                <span class="font-semibold text-gray-700 dark:text-gray-200">нет данных</span>
                                            @endif
                                            @if($conditionPassed !== null)
                                                <span class="text-gray-500 dark:text-gray-400">ветка:</span>
                                                <span class="font-semibold">{{ $conditionBranch }}</span>
                                            @endif
                                            <span class="text-gray-500 dark:text-gray-400">логика:</span>
                                            <span class="font-semibold">{{ $conditionLogic }}</span>
                                        </div>

                                        @if($conditionRows !== [])
                                            <div class="mt-2 space-y-1">
                                                @foreach($conditionRows as $conditionIndex => $condition)
                                                    @php
                                                        $conditionResult = $conditionResults[$conditionIndex] ?? null;
                                                        $conditionHasResult = is_bool($conditionResult);
                                                        $conditionResultText = $conditionResult ? 'Да' : 'Нет';
                                                        $conditionText = $conditionRowLabel((array)$condition);
                                                        $conditionReadableText = $conditionResultLabel((array)$condition, $conditionResult);
                                                    @endphp
                                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                                        @if($conditionReadableText !== null)
                                                            <span @class([
                                                                'font-semibold',
                                                                'text-green-700 dark:text-green-300' => $conditionResult,
                                                                'text-red-700 dark:text-red-300' => !$conditionResult,
                                                            ])>{{ $conditionReadableText }}</span>
                                                        @elseif($conditionHasResult)
                                                            <span @class([
                                                                'font-semibold',
                                                                'text-green-700 dark:text-green-300' => $conditionResult,
                                                                'text-red-700 dark:text-red-300' => !$conditionResult,
                                                            ])>{{ $conditionResultText }}</span>
                                                            <span class="font-medium">{{ $conditionText }}</span>
                                                        @else
                                                            <span class="font-medium">{{ $conditionText }}</span>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endif

                                @if($step->error_message)
                                    <div
                                        class="mt-3 inline-flex max-w-full rounded-lg bg-red-100 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
                                        {{ $step->error_message }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <span @class([
                                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => in_array($stepStatus, ['pending', 'skipped'], true),
                                'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300' => $stepStatus === 'running',
                                'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300' => $stepStatus === 'completed',
                                'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $stepStatus === 'failed',
                            ])>
                                {{ $step->status?->getLabel() ?? $stepStatus }}
                            </span>
                        </div>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-gray-300 p-6 text-center dark:border-gray-700">
                    <x-filament::icon icon="heroicon-o-list-bullet" class="mx-auto h-8 w-8 text-gray-400"/>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">По этому запуску ещё нет шагов.</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
