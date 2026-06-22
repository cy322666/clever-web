<?php

namespace App\Workflows\Context;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Arr;
use Stringable;
use Leek\FilamentWorkflows\Context\WorkflowContext as BaseWorkflowContext;

class WorkflowContext extends BaseWorkflowContext
{
    public function get(string $path): mixed
    {
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
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

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

        $entityData = Arr::get($this->getTriggerData(), $entity);

        if (!is_array($entityData)) {
            return ['found' => false, 'value' => null];
        }

        return $this->extractCustomFieldValue($entityData['custom_fields_values'] ?? null, $fieldId);
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
