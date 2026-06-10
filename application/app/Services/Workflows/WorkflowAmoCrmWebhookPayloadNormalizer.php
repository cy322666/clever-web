<?php

namespace App\Services\Workflows;

use Illuminate\Support\Arr;

class WorkflowAmoCrmWebhookPayloadNormalizer
{
    /**
     * @param array<string, mixed> $payload
     * @return array{payload: array<string, mixed>, events: array<string, array<string, mixed>>}
     */
    public function normalize(array $payload): array
    {
        $payload = $this->normalizeCompanyPayload($payload);
        $events = [];

        foreach ($this->entityMap() as $payloadKey => $entity) {
            $actions = Arr::get($payload, $payloadKey);

            if (!is_array($actions)) {
                continue;
            }

            foreach ($actions as $action => $items) {
                if (!is_array($items) || $items === []) {
                    continue;
                }

                foreach ($this->resolveEntities((string)$payloadKey, (string)$entity, $items) as $resolvedEntity) {
                    $event = $this->eventCode((string)$action, $resolvedEntity);

                    $events[$event] = [
                        'event' => $event,
                        'entity' => $resolvedEntity,
                        'action' => (string)$action,
                        'payload_key' => (string)$payloadKey,
                        'action_key' => (string)$action,
                        'item' => $this->firstItem($items, $resolvedEntity),
                    ];
                }
            }
        }

        return [
            'payload' => $payload,
            'events' => $events,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function entityMap(): array
    {
        return [
            'leads' => 'lead',
            'contacts' => 'contact',
            'customers' => 'customer',
            'tasks' => 'task',
            'talks' => 'talk',
            'chat_template_reviews' => 'chat_template_review',
        ];
    }

    /**
     * amoCRM sometimes sends companies separately, while existing workflow
     * triggers expect contact-like payloads with type=company.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeCompanyPayload(array $payload): array
    {
        $companies = Arr::get($payload, 'companies');

        if (!is_array($companies)) {
            return $payload;
        }

        foreach ($companies as $action => $items) {
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $index => $item) {
                if (is_array($item)) {
                    $items[$index] = array_replace(['type' => 'company'], $item);
                }
            }

            $existing = Arr::get($payload, 'contacts.' . $action, []);
            Arr::set($payload, 'contacts.' . $action, array_merge(is_array($existing) ? $existing : [], $items));
        }

        return $payload;
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int, string>
     */
    private function resolveEntities(string $payloadKey, string $entity, array $items): array
    {
        if ($payloadKey !== 'contacts') {
            return [$entity];
        }

        $entities = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = Arr::get($item, 'type');

            if ($type === 'company') {
                $entities[] = 'company';
                continue;
            }

            if ($type === 'contact') {
                $entities[] = 'contact';
                continue;
            }

            $entities[] = 'contact';
            $entities[] = 'company';
        }

        return array_values(array_unique($entities ?: ['contact']));
    }

    private function eventCode(string $action, string $entity): string
    {
        return $action . '_' . $entity;
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<string, mixed>|null
     */
    private function firstItem(array $items, string $entity): ?array
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = Arr::get($item, 'type');

            if ($entity === 'company' && $type !== null && $type !== 'company') {
                continue;
            }

            if ($entity === 'contact' && $type !== null && $type !== 'contact') {
                continue;
            }

            return $item;
        }

        return null;
    }
}
