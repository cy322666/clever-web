<?php

namespace App\Workflows\Actions;

class WorkflowConditionPreview
{
    /**
     * @param array<string, mixed> $config
     * @return array<int, array{left: string, operator: string, right: ?string, connector: ?string}>
     */
    public static function rows(array $config, int $limit = 5): array
    {
        $conditions = array_values(array_filter(
            (array)($config['conditions'] ?? []),
            static fn (mixed $condition): bool => is_array($condition),
        ));
        $fallbackLogic = (string)($config['logic'] ?? 'and');
        $pipelineId = static::pipelineIdFromConditions($conditions);
        $rows = [];

        foreach (array_slice($conditions, 0, $limit) as $index => $condition) {
            $operator = (string)($condition['operator'] ?? 'equals');

            $rows[] = [
                'left' => static::valueLabel($condition['left'] ?? '', $condition, 'left', $pipelineId),
                'operator' => static::operatorLabel($operator),
                'right' => in_array($operator, static::unaryOperators(), true)
                    ? null
                    : static::valueLabel($condition['right'] ?? '', $condition, 'right', $pipelineId),
                'connector' => $index > 0
                    ? static::logicLabel((string)($condition['join'] ?? $fallbackLogic))
                    : null,
            ];
        }

        return $rows;
    }

    public static function logicLabel(string $logic): string
    {
        return $logic === 'or' ? 'ИЛИ' : 'И';
    }

    public static function remainingCount(array $config, int $limit = 5): int
    {
        return max(0, count((array)($config['conditions'] ?? [])) - $limit);
    }

    private static function valueLabel(mixed $value, array $condition, string $side, ?string $pipelineId): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '-';
        }

        $sidePipelineId = filled($condition[$side . '_status_pipeline_id'] ?? null)
            ? (string)$condition[$side . '_status_pipeline_id']
            : $pipelineId;

        if ($sidePipelineId !== null) {
            $source = (string)($condition[$side . '_source'] ?? '');
            $isSelectedStatus = $source === 'amo_status';
            $isPlainStatusValue = !str_contains($value, '{{') && !str_contains($value, 'status_id');
            $oppositeSide = $side === 'left' ? 'right' : 'left';

            if ($isSelectedStatus || (static::isStatusSide($condition, $oppositeSide) && $isPlainStatusValue)) {
                return WorkflowTriggerConditionVariableCatalog::amoStatusName($value, $sidePipelineId)
                    ?? (WorkflowTriggerConditionVariableCatalog::label($value, true) ?? $value);
            }
        }

        return WorkflowTriggerConditionVariableCatalog::label($value, true) ?? $value;
    }

    private static function pipelineIdFromConditions(array $conditions): ?string
    {
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $operator = (string)($condition['operator'] ?? 'equals');

            if (!in_array($operator, ['equals', 'strict_equals'], true)) {
                continue;
            }

            foreach ([['left', 'right'], ['right', 'left']] as [$pipelineSide, $valueSide]) {
                if (!static::isPipelineSide($condition, $pipelineSide)) {
                    continue;
                }

                $pipelineId = trim((string)($condition[$valueSide] ?? ''));

                if ($pipelineId !== '' && !str_contains($pipelineId, '{{')) {
                    return $pipelineId;
                }
            }
        }

        return null;
    }

    private static function isPipelineSide(array $condition, string $side): bool
    {
        $source = (string)($condition[$side . '_source'] ?? '');
        $value = (string)($condition[$side] ?? '');

        return $source === 'amo_pipeline' || str_contains($value, 'pipeline_id');
    }

    private static function isStatusSide(array $condition, string $side): bool
    {
        $source = (string)($condition[$side . '_source'] ?? '');
        $value = (string)($condition[$side] ?? '');

        return $source === 'amo_status' || str_contains($value, 'status_id');
    }

    private static function operatorLabel(string $operator): string
    {
        return [
            'equals' => '=',
            'strict_equals' => '=',
            'not_equals' => '≠',
            'gt' => '>',
            'lt' => '<',
            'is_empty' => '∅',
            'is_not_empty' => '!∅',
        ][$operator] ?? $operator;
    }

    /**
     * @return array<int, string>
     */
    private static function unaryOperators(): array
    {
        return ['is_empty', 'is_not_empty', 'is_null', 'is_not_null', 'is_true', 'is_false'];
    }
}
