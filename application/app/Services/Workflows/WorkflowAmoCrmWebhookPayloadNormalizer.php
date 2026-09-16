<?php

namespace App\Services\Workflows;

use Illuminate\Support\Arr;
use App\Workflows\Triggers\AmoCrmWebhookTriggerCatalog;

class WorkflowAmoCrmWebhookPayloadNormalizer
{
    /**
     * @param array<string, mixed> $payload
     * @return array{payload: array<string, mixed>, events: array<string, array<string, mixed>>}
     */
    public function normalize(array $payload): array
    {
        $payload = $this->normalizeCompanyPayload($this->normalizeEnvelopes($payload));
        $events = [];
        $supported = array_flip(AmoCrmWebhookTriggerCatalog::eventCodes());

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
                    if (!isset($supported[$event])) continue;
                    $matchingItems = $this->matchingItems($items, $resolvedEntity);
                    if ($matchingItems === []) continue;

                    $events[$event] = [
                        'event' => $event,
                        'entity' => $resolvedEntity,
                        'action' => (string)$action,
                        'payload_key' => (string)$payloadKey,
                        'action_key' => (string)$action,
                        'item' => $matchingItems[0],
                        'items' => $matchingItems,
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
            'message' => 'message',
            'outgoing_message' => 'outgoing_message',
            'unsorted' => 'unsorted',
            'chat_template_reviews' => 'chat_template_review',
        ];
    }

    /** Canonicalize the documented aliases without dropping any item fields. */
    private function normalizeEnvelopes(array $payload): array
    {
        foreach (['task'=>'tasks', 'talk'=>'talks'] as $alias => $canonical) {
            if (!is_array($payload[$alias] ?? null)) continue;
            foreach ($payload[$alias] as $action => $items) {
                $payload[$canonical][$action] = array_merge(
                    $this->normalizeItems($payload[$canonical][$action] ?? [], (string)$action),
                    $this->normalizeItems($items, (string)$action),
                );
            }
            unset($payload[$alias]);
        }
        // WhatsApp template review notifications have a bare top-level "add" envelope.
        foreach ($this->normalizeItems($payload['add'] ?? [], 'add') as $item) {
            if (($item['type'] ?? '') === 'waba' && (isset($item['reviews']) || isset($item['is_on_review']))) {
                $existing = $this->normalizeItems($payload['chat_template_reviews']['add'] ?? [], 'add');
                if (!in_array($item, $existing, true)) $existing[] = $item;
                $payload['chat_template_reviews']['add'] = $existing;
            }
        }
        foreach (array_merge(array_keys($this->entityMap()), ['companies']) as $key) {
            if (!is_array($payload[$key] ?? null)) continue;
            foreach ($payload[$key] as $action => $items) {
                $payload[$key][$action] = $this->normalizeItems($items, (string)$action, $key === 'unsorted' ? 'uid' : 'id');
            }
        }
        return $payload;
    }

    private function normalizeItems(mixed $items, string $action, string $idKey = 'id', int $depth = 0): array
    {
        if ($depth > 4) return [];
        if (!is_array($items)) {
            if ($action !== 'delete' || (!is_string($items) && !is_int($items))) return [];
            return preg_match($idKey === 'uid' ? '/^[a-zA-Z0-9_-]+$/D' : '/^[1-9][0-9]*$/D', (string)$items) ? [[$idKey=>$items]] : [];
        }
        if ($items === []) return [];
        if (isset($items['id']) || isset($items['uid'])) return [$items];
        // Numeric wrappers may be nested (task.update[0][0] in the official example).
        $result = [];
        foreach ($items as $key => $item) {
            if (!ctype_digit((string)$key)) return [];
            array_push($result, ...$this->normalizeItems($item, $action, $idKey, $depth + 1));
        }
        return $result;
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
            $existing = is_array($existing) ? $existing : [];
            foreach ($items as $item) if (!in_array($item, $existing, true)) $existing[] = $item;
            Arr::set($payload, 'contacts.' . $action, $existing);
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
     * @return array<int, array<string, mixed>>
     */
    private function matchingItems(array $items, string $entity): array
    {
        $matches = [];
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

            $matches[] = $item;
        }

        return $matches;
    }
}
