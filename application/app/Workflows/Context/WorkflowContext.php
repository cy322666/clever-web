<?php

namespace App\Workflows\Context;

use BackedEnum;
use App\Services\Workflows\WorkflowOutputView;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Arr;
use Stringable;
use Leek\FilamentWorkflows\Context\WorkflowContext as BaseWorkflowContext;

class WorkflowContext extends BaseWorkflowContext
{
    private ?array $graphInputs = null;
    private ?array $upstreamStepIds = null;
    private ?array $customFieldScope = null;

    /** Scope implicit input to connected predecessors; explicit node references stay available. */
    public function scopeToNode(array $definition, string $nodeId): void
    {
        $graph = \App\Services\Workflows\WorkflowGraph::class;
        $nodes = $graph::nodes($definition['actions'] ?? []);
        $edges = $graph::connections($definition);
        $start = \App\Services\Workflows\WorkflowStartNodes::selected($definition, $this->getTriggerData());
        $this->customFieldScope = compact('nodes', 'edges', 'start', 'nodeId');
        $this->upstreamStepIds = array_fill_keys(array_map(fn ($id) => substr($id, 7), $graph::ancestors($definition, $nodeId)), true);
        $resolve = function (string $target, array $visited = []) use (&$resolve, $nodes, $edges, $start, $graph): array {
            if (isset($visited[$target])) return [];
            $visited[$target] = true;
            $inputs = [];
            foreach ($edges as $edge) {
                if ($edge['targetId'] !== $target) continue;
                $source = $edge['sourceId'];
                if ($source === $start) {
                    $inputs[$source] = $this->getTriggerData();
                    continue;
                }
                if (!isset($nodes[$source])) continue;
                $step = $nodes[$source]['step'];
                if ($step['disabled'] ?? false) {
                    if (!$graph::condition($step) || $edge['sourcePort'] === 'yes') {
                        $inputs += $resolve($source, $visited);
                    }
                    continue;
                }
                if (!$this->hasStepOutput($step['id'])) continue;
                $output = $this->getStepOutput($step['id']);
                if ($graph::condition($step) && $edge['sourcePort'] !== (($output['passed'] ?? false) ? 'yes' : 'no')) continue;
                $inputs[$source] = $graph::loop($step) ? WorkflowOutputView::value($output) : $output;
            }
            return $inputs;
        };
        $this->graphInputs = $resolve($nodeId);
    }

    public function clearNodeScope(): void
    {
        $this->graphInputs = $this->upstreamStepIds = null;
        $this->customFieldScope = null;
    }

    public function forgetStepOutputs(array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->stepOutputs[$id]);
            $this->forgetVariable('_resolved_inputs.'.$id);
        }
    }

    /** Preserve execution identity and trigger; isolate mutable data between iterations. */
    public function restoreLoopState(array $snapshot): void
    {
        $this->stepOutputs = $snapshot['step_outputs'] ?? [];
        $this->variables = $snapshot['variables'] ?? [];
        $this->clearNodeScope();
    }

    public function getStepOutputs(): array
    {
        $outputs = parent::getStepOutputs();
        return $this->upstreamStepIds === null ? $outputs : array_intersect_key($outputs, $this->upstreamStepIds);
    }

    public function getNodeInput(): mixed
    {
        if ($this->graphInputs !== null) {
            if (count($this->graphInputs) > 1) {
                throw new \InvalidArgumentException('У ноды несколько входов. Вместо $json выберите данные конкретной ноды.');
            }
            return $this->graphInputs === [] ? null : reset($this->graphInputs);
        }
        $outputs = $this->getStepOutputs();
        return $outputs === [] ? $this->getTriggerData() : end($outputs);
    }

    public function get(string $path): mixed
    {
        // Resolve the node first, then find the field by ID, never by its array position.
        if (preg_match('/^(?<source>\$\(.+\).*)\.cf\(\s*(?<field>\d+)\s*\)$/u', $path, $field)) {
            return $this->customFieldFromEntity($this->get($field['source']), $field['field']);
        }
        if ($path === '$json' || str_starts_with($path, '$json.')) {
            $value = $this->getNodeInput();
            return $path === '$json' ? $value : Arr::get($value, $this->expressionPath(substr($path, 6)));
        }
        $quoted = '("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')';
        $shortReference = false;
        if (preg_match('/^\$node\['.$quoted.'\]\.json(.*)$/u', $path, $match)
            || preg_match('/^\$\('.$quoted.'\)\.(?:item|first\(\))\.json(.*)$/u', $path, $match)
            || ($shortReference = (bool) preg_match('/^\$\('.$quoted.'\)((?:[.\[].*)?)$/u', $path, $match))) {
            $key = $match[1][0] === '"' ? json_decode($match[1], true) : str_replace(["\\'", '\\\\'], ["'", '\\'], substr($match[1], 1, -1));
            if (!is_string($key)) return null;
            $legacyTrigger = $key === 'trigger';
            if (!$this->hasStepOutput($key)) {
                $ids = $this->getVariable('_node_names', [])[$key] ?? [];
                $key = count($ids) === 1 ? $ids[0] : $key;
            }
            if ($key === 'trigger' || str_starts_with($key, 'trigger:')) {
                if (!$legacyTrigger && $key !== ($this->getTriggerData()['_workflow_start_node_id'] ?? 'trigger')) return null;
                $nested = $this->expressionPath($match[2]);
                if ($shortReference) return \App\Services\Workflows\WorkflowOutputView::triggerPath($this->getTriggerData(), $nested);
                $value = $this->getTriggerData();
                return $nested === '' ? $value : (is_array($value) ? Arr::get($value, $nested) : null);
            }
            $value = $this->getStepOutput($key);
            $nested = $this->expressionPath($match[2]);
            if ($shortReference) return WorkflowOutputView::actionPath($value, $nested);
            return $nested === '' ? $value : (is_array($value) ? Arr::get($value, $nested) : null);
        }
        if (preg_match('/^step\.([^.]+)$/', $path, $match)) return $this->getStepOutput($match[1]);
        if (str_starts_with($path, 'current.')) {
            return $this->getCurrentValue(substr($path, strlen('current.')));
        }

        $customFieldValue = $this->getCustomFieldValue($path);

        if ($customFieldValue['found']) {
            return $customFieldValue['value'];
        }

        if (preg_match('/^[a-z_]+\.custom_fields_values$/', $path)) {
            return null;
        }

        $type = explode('.', $path, 2)[0] ?? '';

        if (in_array($type, ['step', 'var', 'context', 'now'], true)) {
            return parent::get($path);
        }

        $triggerValue = Arr::get($this->getTriggerData(), $path);

        if ($triggerValue !== null && $triggerValue !== '') {
            return $triggerValue;
        }

        $missing = new \stdClass();
        $variableValue = $this->getVariable($path, $missing);

        if ($variableValue !== $missing) {
            return $variableValue;
        }

        $computedEntityValue = $this->getComputedEntityValue($path);

        if ($computedEntityValue['found']) {
            return $computedEntityValue['value'];
        }

        return $this->getPreviousStepEntityValue($path);
    }

    public function resolve(mixed $value): mixed
    {
        // New expression references retain JSON types; legacy text masks retain their existing behavior.
        if (is_string($value) && preg_match('/^\s*\{\{\s*(\$[^{}]+?)\s*\}\}\s*$/u', $value, $match)) {
            return $this->get(trim($match[1]));
        }
        if (is_array($value)) {
            // HTTP JSON is parsed before interpolation, so quotes in variable values
            // stay data and whole placeholders keep numbers, arrays and booleans.
            if (isset($value['url'], $value['method'])) {
                // Preserve both formats in the editor, but only resolve the active body at runtime.
                if (($value['body_format'] ?? 'json') === 'form') unset($value['body']);
                else unset($value['form_fields']);
                foreach (['headers','body'] as $field) {
                    $json = $value[$field] ?? null;
                    if (is_string($json) && str_contains($json, '{{') && !preg_match('/^\s*\{\{.*\}\}\s*$/s', $json)) {
                        try { $value[$field] = json_decode($json, true, 64, JSON_THROW_ON_ERROR); }
                        catch (\JsonException) { throw new \InvalidArgumentException('Некорректный JSON HTTP-запроса. Подстановки внутри JSON укажите в двойных кавычках.'); }
                    }
                }
            }
            // Parse before substituting, so quotes and typed values cannot corrupt JSON.
            if (($value['body_mode'] ?? null) === 'json' && is_string($value['json_body'] ?? null)
                && !preg_match('/^\s*\{\{.*\}\}\s*$/s', $value['json_body'])) {
                $value['json_body'] = \App\Services\Workflows\WorkflowJsonBody::parse($value['json_body']);
            }
            $resolved = [];
            foreach ($value as $key => $item) {
                $resolved[$key] = $key === 'javascript_code' ? $item : $this->resolve($item);
            }
            return $resolved;
        }
        return parent::resolve($value);
    }

    private function expressionPath(string $path): string
    {
        return trim(preg_replace('/\[(?:[\'\"]([^\'\"]+)[\'\"]|(\d+))\]/', '.$1$2', $path) ?? $path, '.');
    }

    private function getCurrentValue(string $path): mixed
    {
        return match ($path) {
            'date' => now()->toDateString(),
            'time' => now()->format('H:i:s'),
            'datetime' => now()->format('Y-m-d H:i:s'),
            'weekday' => now()->locale('ru')->isoFormat('dddd'),
            'weekday_number' => now()->dayOfWeekIso,
            default => null,
        };
    }

    protected function resolvePlaceholder(string $path): ?string
    {
        [$variablePath, $modifiers] = $this->parseVariableExpression($path);
        $value = $this->get($variablePath);

        foreach ($modifiers as $modifier) {
            $value = $this->applyModifier($value, $modifier['name'], $modifier['argument']);
        }

        return $this->stringifyResolvedValue($value);
    }

    /**
     * @return array{0: string, 1: array<int, array{name: string, argument: string|null}>}
     */
    private function parseVariableExpression(string $expression): array
    {
        $parts = $this->splitExpressionParts($expression);
        $path = trim((string)array_shift($parts));

        $modifiers = collect($parts)
            ->map(function (string $part): ?array {
                $part = trim($part);

                if ($part === '') {
                    return null;
                }

                if (preg_match('/^(?<name>[a-zA-Z_][a-zA-Z0-9_-]*)(?:\((?<argument>.*)\))?$/u', $part, $matches) !== 1) {
                    return null;
                }

                return [
                    'name' => mb_strtolower((string)$matches['name']),
                    'argument' => array_key_exists('argument', $matches) ? (string)$matches['argument'] : null,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [$path, $modifiers];
    }

    /**
     * @return array<int, string>
     */
    private function splitExpressionParts(string $expression): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $i + 1 < $length) $buffer .= $expression[++$i];
                elseif ($char === $quote) $quote = null;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')' && $depth > 0) {
                $depth--;
            }

            if ($char === ':' && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return $parts;
    }

    private function applyModifier(mixed $value, string $modifier, ?string $argument): mixed
    {
        return match ($modifier) {
            'date' => $this->dateValue($value)?->format($this->modifierArgument($argument, 'd.m.Y')) ?? null,
            'datetime' => $this->dateValue($value)?->format($this->modifierArgument($argument, 'd.m.Y H:i')) ?? null,
            'date_text' => $this->dateValue($value)?->locale('ru')->isoFormat('D MMMM YYYY') ?? null,
            'timestamp' => $this->dateValue($value)?->timestamp ?? null,
            'add' => $this->shiftDateValue($value, $argument, true),
            'sub' => $this->shiftDateValue($value, $argument, false),
            'default' => $this->valueIsEmpty($value) ? $this->modifierArgument($argument, '') : $value,
            'upper' => mb_strtoupper((string)$value),
            'lower' => mb_strtolower((string)$value),
            'trim' => trim((string)$value),
            'digits' => preg_replace('/\D+/', '', (string)$value) ?? '',
            'phone_ru' => $this->formatRussianPhone($value),
            'number' => $this->formatNumber($value, $argument),
            'join' => is_array($value) ? implode($this->modifierArgument($argument, ', '), $value) : $value,
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
            default => $value,
        };
    }

    private function modifierArgument(?string $argument, string $default): string
    {
        $argument = $argument ?? '';

        return trim($argument) === '' ? $default : stripcslashes($argument);
    }

    private function dateValue(mixed $value): ?Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int)$value);
        }

        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function shiftDateValue(mixed $value, ?string $argument, bool $add): mixed
    {
        $date = $this->dateValue($value);
        $argument = $this->modifierArgument($argument, '');

        if ($date === null || $argument === '') {
            return $value;
        }

        try {
            return $date->modify(($add ? '+' : '-') . $argument);
        } catch (\Throwable) {
            return $value;
        }
    }

    private function valueIsEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function formatRussianPhone(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string)$value) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            return '7' . substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '7' . $digits;
        }

        return $digits;
    }

    private function formatNumber(mixed $value, ?string $argument): mixed
    {
        if (!is_numeric($value)) {
            return $value;
        }

        $decimals = is_numeric($argument) ? max(0, (int)$argument) : 0;

        return number_format((float)$value, $decimals, ',', ' ');
    }

    private function stringifyResolvedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof BackedEnum) {
            return (string)$value->value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string)$value;
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        return null;
    }

    /**
     * @return array{found: bool, value: mixed}
     */
    private function getComputedEntityValue(string $path): array
    {
        if (!preg_match('/^(?<entity>lead|contact|company|customer|item)\.(?<key>[a-z_]+_count)$/', $path, $matches)) {
            return ['found' => false, 'value' => null];
        }

        $entity = (string)$matches['entity'];
        $key = (string)$matches['key'];
        $entityData = Arr::get($this->getTriggerData(), $entity);

        if (is_array($entityData) && array_key_exists($key, $entityData)) {
            return ['found' => true, 'value' => $entityData[$key]];
        }

        if ($entity === 'item') {
            $entityData = Arr::get($this->getTriggerData(), 'item');

            if (is_array($entityData) && array_key_exists($key, $entityData)) {
                return ['found' => true, 'value' => $entityData[$key]];
            }
        }

        return match ($key) {
            'tags_count' => ['found' => true, 'value' => $this->countTags($entityData)],
            'contacts_count' => ['found' => true, 'value' => $this->countEmbedded($entityData, 'contacts')],
            'leads_count' => ['found' => true, 'value' => $this->countEmbedded($entityData, 'leads')],
            'open_leads_count' => ['found' => true, 'value' => $this->countOpenLeads($entityData)],
            'notes_count' => ['found' => true, 'value' => $this->countEmbedded($entityData, 'notes')],
            'tasks_count' => ['found' => true, 'value' => $this->countEmbedded($entityData, 'tasks')],
            default => ['found' => false, 'value' => null],
        };
    }

    private function countTags(mixed $entityData): int
    {
        if (!is_array($entityData)) {
            return 0;
        }

        $tags = Arr::get($entityData, '_embedded.tags', $entityData['tags'] ?? []);

        return is_array($tags) ? count($tags) : 0;
    }

    private function countEmbedded(mixed $entityData, string $key): int
    {
        if (!is_array($entityData)) {
            return 0;
        }

        $items = Arr::get($entityData, '_embedded.' . $key, $entityData[$key] ?? []);

        return is_array($items) ? count($items) : 0;
    }

    private function countOpenLeads(mixed $entityData): int
    {
        if (! is_array($entityData)) {
            return 0;
        }

        $leads = Arr::get($entityData, '_embedded.leads', $entityData['leads'] ?? []);

        if (! is_array($leads)) {
            return 0;
        }

        return count(array_filter($leads, static function (mixed $lead): bool {
            if (! is_array($lead)) {
                return true;
            }

            $statusId = (int) ($lead['status_id'] ?? 0);

            return ! in_array($statusId, [142, 143], true)
                && ! (bool) ($lead['is_closed'] ?? false)
                && empty($lead['closed_at']);
        }));
    }

    private function getPreviousStepEntityValue(string $path): mixed
    {
        if (!preg_match('/^(?<entity>lead|contact|company|customer|task|note)\.id$/', $path, $matches)) {
            return null;
        }

        $entity = (string)$matches['entity'];

        foreach (array_reverse($this->getStepOutputs(), true) as $output) {
            $entityId = $this->entityIdFromStepOutput($entity, $output);

            if ($entityId !== null) {
                return $entityId;
            }
        }

        return null;
    }

    private function entityIdFromStepOutput(string $entity, mixed $output): ?int
    {
        if (!is_array($output)) {
            return null;
        }

        $outputEntity = (string)($output['entity_type'] ?? $output['entity'] ?? '');

        if ($outputEntity === $entity) {
            return $this->numericId($output['entity_id'] ?? $output['id'] ?? null);
        }

        foreach ((array)($output['affected_entities'] ?? []) as $affectedEntity) {
            if (!is_array($affectedEntity)) {
                continue;
            }

            $affectedType = (string)($affectedEntity['entity_type'] ?? $affectedEntity['type'] ?? '');

            if ($affectedType === $entity) {
                return $this->numericId($affectedEntity['entity_id'] ?? $affectedEntity['id'] ?? null);
            }
        }

        return null;
    }

    private function numericId(mixed $value): ?int
    {
        if (is_numeric($value) && (int)$value > 0) {
            return (int)$value;
        }

        return null;
    }

    /**
     * @return array{found: bool, value: mixed}
     */
    private function getCustomFieldValue(string $path): array
    {
        if (!preg_match('/^(?<entity>[a-z_]+)\.cf\((?<field>[^)]+)\)$/', $path, $matches)) {
            return ['found' => false, 'value' => null];
        }

        $entity = (string)$matches['entity'];
        $fieldId = trim((string)$matches['field']);

        if ($fieldId === '') {
            return ['found' => false, 'value' => null];
        }

        return ['found' => true, 'value' => $this->customFieldFromEntity($this->customFieldEntity($entity), $fieldId)];
    }

    private function customFieldFromEntity(mixed $data, string $fieldId): mixed
    {
        if (!is_array($data)) return null;
        if (array_is_list($data)) {
            if (count($data) > 1) {
                throw new \InvalidArgumentException('Для поля '.$fieldId.' найдено несколько записей. Выберите конкретную запись в результате ноды.');
            }
            $data = $data[0] ?? null;
        }
        return is_array($data) ? $this->extractCustomFieldValue($data['custom_fields_values'] ?? null, $fieldId)['value'] : null;
    }

    /** The closest entity on each connected, active input path wins, including empty results. */
    private function customFieldEntity(string $entity): mixed
    {
        if ($this->customFieldScope !== null) {
            $scope = $this->customFieldScope;
            $cache = [];
            $visit = function (string $target, array $visited = []) use (&$visit, &$cache, $scope, $entity): array {
                if (array_key_exists($target, $cache)) return $cache[$target];
                if (isset($visited[$target])) return [];
                $visited[$target] = true;
                $candidates = [];
                foreach ($scope['edges'] as $edge) {
                    if ($edge['targetId'] !== $target) continue;
                    $source = $edge['sourceId'];
                    if ($source === $scope['start']) {
                        $trigger = $this->customFieldTriggerEntity($entity);
                        if ($trigger !== null) $candidates[$source] = $trigger;
                        continue;
                    }
                    $step = $scope['nodes'][$source]['step'] ?? null;
                    if ($step === null) continue;
                    $disabled = (bool) ($step['disabled'] ?? false);
                    $output = $this->getStepOutput($step['id']);
                    if (\App\Services\Workflows\WorkflowGraph::condition($step)
                        && $edge['sourcePort'] !== ($disabled ? 'yes' : (isset($output['passed']) ? ($output['passed'] ? 'yes' : 'no') : null))) continue;
                    $candidate = $disabled ? null : $this->customFieldOutputEntity($entity, $output, $step);
                    if ($candidate !== null) {
                        $candidates[$source] = $candidate['data'];
                    } else {
                        $candidates += $visit($source, $visited);
                    }
                }
                return $cache[$target] = $candidates;
            };
            $candidates = $visit($scope['nodeId']);
            if (count($candidates) > 1) {
                throw new \InvalidArgumentException('Для переменной '.$entity.'.cf(...) есть несколько источников. Укажите ноду через $("Название ноды").cf(ID поля).');
            }
            return $candidates === [] ? null : reset($candidates);
        }

        // Legacy sequential runs have no node scope. Only typed outputs can replace trigger data.
        $definition = $this->getVariable('_definition_snapshot', []);
        $nodes = \App\Services\Workflows\WorkflowGraph::nodes($definition['actions'] ?? []);
        foreach (array_reverse($this->getStepOutputs(), true) as $id => $output) {
            $candidate = $this->customFieldOutputEntity($entity, $output, $nodes['action:'.$id]['step'] ?? []);
            if ($candidate !== null) return $candidate['data'];
        }
        return $this->customFieldTriggerEntity($entity);
    }

    /** Null means unrelated output; ['data' => null] means this entity was requested but not returned. */
    private function customFieldOutputEntity(string $entity, mixed $output, array $step): ?array
    {
        $output = is_array($output) ? $output : [];
        $type = $output['entity_type'] ?? $output['entity'] ?? null;
        $plural = ['lead' => 'leads', 'contact' => 'contacts', 'company' => 'companies', 'customer' => 'customers'];
        $operation = ($step['config'] ?? $step['properties'] ?? [])['operation'] ?? '';
        $stepType = $step['type'] ?? '';
        if ($stepType === 'amocrm_get_contact') $type = 'contact';
        if (in_array($stepType, ['amocrm_query_leads', 'amocrm_contact_leads'], true)) $type = 'lead';
        if ($stepType === 'amocrm_read' && preg_match('/^(leads|contacts|companies|customers)\.(one|list)$/', $operation, $match)) {
            $type = array_search($match[1], $plural, true);
        }
        // Raw collection responses retain reliable entity metadata even in older runs.
        $embedded = $output['data']['_embedded'] ?? [];
        if ($type === null && isset($plural[$entity]) && is_array($embedded) && array_key_exists($plural[$entity], $embedded)
            && !isset($output['data']['id'])) $type = $entity;
        if ($type !== $entity && $type !== ($plural[$entity] ?? $entity)) return null;
        $data = WorkflowOutputView::value($output);
        if (array_key_exists($entity, $output)) $data = WorkflowOutputView::entity($output[$entity]);
        return ['data' => $data];
    }

    private function customFieldTriggerEntity(string $entity): mixed
    {
        $input = $this->getTriggerData();
        if (array_key_exists($entity, $input) && is_array($input[$entity])) return $input[$entity];
        if (($input['entity'] ?? null) === $entity) return WorkflowOutputView::trigger($input);
        $plural = ['lead' => 'leads', 'contact' => 'contacts', 'company' => 'companies', 'customer' => 'customers'][$entity] ?? null;
        $body = $input['body'] ?? $input;
        if ($plural !== null && is_array($body) && array_key_exists($plural, $body)) return WorkflowOutputView::trigger($input);
        return null;
    }

    /**
     * @return array{found: bool, value: mixed}
     */
    private function extractCustomFieldValue(mixed $fields, string $fieldId): array
    {
        if (!is_array($fields)) {
            return ['found' => false, 'value' => null];
        }

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $currentFieldId = (string)($field['field_id'] ?? $field['id'] ?? '');

            if ($currentFieldId !== $fieldId) {
                continue;
            }

            return [
                'found' => true,
                'value' => $this->extractCustomFieldValues($field['values'] ?? $field['value'] ?? null),
            ];
        }

        return ['found' => false, 'value' => null];
    }

    private function extractCustomFieldValues(mixed $values): mixed
    {
        if (!is_array($values)) {
            return $values;
        }

        $result = [];

        foreach ($values as $value) {
            if (is_array($value)) {
                $result[] = $value['value'] ?? $value['enum'] ?? $value['enum_id'] ?? null;
                continue;
            }

            $result[] = $value;
        }

        $result = array_values(array_filter($result, static fn(mixed $value): bool => $value !== null && $value !== '')
        );

        if (count($result) === 1) {
            return $result[0];
        }

        return $result;
    }
}
