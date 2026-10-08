<?php

namespace App\Services\Workflows;

/** Canonical field fingerprints, not raw CRM values or credentials. */
final class WorkflowMutationEvidence
{
    public static function fields(array $data): array
    {
        $out = [];
        $aliases = ['responsible_user_id' => 'responsible', 'responsible_user' => 'responsible',
            'last_modified' => 'revision', 'updated_at' => 'revision'];
        foreach (['name', 'price', 'status_id', 'pipeline_id', 'responsible_user_id', 'responsible_user',
            'first_name', 'last_name', 'is_completed', 'text', 'task_type_id', 'complete_till',
            'next_price', 'next_date', 'loss_reason_id', 'closed_at'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$aliases[$key] ?? $key] = self::hash($data[$key]);
            }
        }
        foreach ($data['custom_fields_values'] ?? $data['custom_fields'] ?? [] as $field) {
            $id = $field['field_id'] ?? $field['id'] ?? null;
            if (! $id) {
                continue;
            }
            // API enum IDs, webhook labels and normalized phones are not safely comparable.
            // Keep the fingerprint for diagnostics, never use it to permit a recursive run.
            $out['cf:'.$id] = self::hash($field['values'] ?? []);
        }

        return $out;
    }

    public static function revision(array $data): ?int
    {
        $value = $data['updated_at'] ?? $data['last_modified'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public static function hash(mixed $value): string
    {
        if (is_array($value)) {
            $value = array_map(self::hash(...), $value);
            if (array_is_list($value)) {
                sort($value);
            } else {
                ksort($value);
            }
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_numeric($value)) {
            $value = (string) (0 + $value);
        }

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
