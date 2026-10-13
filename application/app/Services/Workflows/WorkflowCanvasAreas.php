<?php

namespace App\Services\Workflows;

/** Presentation metadata only. It must never participate in graph execution. */
final class WorkflowCanvasAreas
{
    public static function normalize(mixed $areas): array
    {
        if (!is_array($areas) || !array_is_list($areas) || count($areas) > 100) {
            throw new \InvalidArgumentException('Некорректный список областей этапов (не более 100).');
        }
        $result = [];
        $ids = [];
        foreach ($areas as $area) {
            if (!is_array($area) || !is_string($area['id'] ?? null)
                || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $area['id']) || isset($ids[$area['id']])
                || !is_string($area['title'] ?? null) || mb_strlen($area['title']) > 120) {
                throw new \InvalidArgumentException('Некорректное название или ID области этапа.');
            }
            $entry = ['id' => $area['id'], 'title' => trim($area['title']) ?: 'Новый этап'];
            foreach (['x' => [-100000, 100000], 'y' => [-100000, 100000], 'width' => [240, 20000], 'height' => [160, 20000]] as $field => [$min, $max]) {
                $value = $area[$field] ?? null;
                if (!is_numeric($value) || !is_finite((float) $value) || $value < $min || $value > $max) {
                    throw new \InvalidArgumentException('Некорректный размер или положение области этапа.');
                }
                $entry[$field] = (float) $value;
            }
            $ids[$area['id']] = true;
            $result[] = $entry;
        }

        return $result;
    }
}
