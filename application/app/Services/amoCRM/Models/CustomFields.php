<?php

namespace App\Services\amoCRM\Models;

use Illuminate\Support\Facades\Log;

class CustomFields
{
    public static function set($entity, string $fieldName, mixed $value): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        try {
            $customField = $entity->cf($fieldName);

            if (is_array($value) && method_exists($customField, 'setValues')) {
                $customField->setValues($value);
                return;
            }

            if (is_string($value) && method_exists($customField, 'setDate')) {
                $customField->setDate($value);
                return;
            }

            if (is_int($value) && method_exists($customField, 'setTimestamp')) {
                $customField->setTimestamp($value);
                return;
            }

            $customField->setValue($value);
        } catch (\Throwable $e) {
            Log::error(__METHOD__ . ' ' . $e->getMessage() . ' field_name: ' . $fieldName);
        }
    }
}
