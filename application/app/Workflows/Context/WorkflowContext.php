<?php

namespace App\Workflows\Context;

use Illuminate\Support\Arr;
use Leek\FilamentWorkflows\Context\WorkflowContext as BaseWorkflowContext;

class WorkflowContext extends BaseWorkflowContext
{
    public function get(string $path): mixed
    {
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

        return $this->getPreviousStepEntityValue($path);
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
