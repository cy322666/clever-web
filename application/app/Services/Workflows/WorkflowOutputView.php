<?php

namespace App\Services\Workflows;

/** A presentation/short-expression projection. Persisted execution data is never changed. */
final class WorkflowOutputView
{
    public static function isCondition(mixed $output): bool
    {
        return is_array($output) && is_bool($output['passed'] ?? null)
            && isset($output['branch'], $output['condition_results']);
    }

    public static function trigger(mixed $input): mixed
    {
        if (!is_array($input)) return $input;
        if (array_key_exists('body', $input) && (isset($input['headers']) || isset($input['method']) || isset($input['_workflow_start_node_id']) || count($input) === 1)) return $input['body'];
        unset($input['_workflow_start_node_id']);
        return $input;
    }

    public static function value(mixed $output): mixed
    {
        if (!is_array($output)) return $output;
        if (self::isCondition($output)) return $output['passed'];
        // Read results can contain all pages, while the HTTP journal is truncated.
        // Never replace the canonical collection with the last HTTP response.
        if (array_key_exists('data', $output) && isset($output['items']) && array_key_exists('has_more', $output)) {
            return self::entity($output['data'] ?: $output['items']);
        }
        if (isset($output['items']) && (array_key_exists('has_more', $output) || array_key_exists('has_matches', $output))) {
            return self::entity($output['items']);
        }
        if (isset($output['contact'], $output['entity_type']) && $output['entity_type'] === 'contact') return self::entity($output['contact']);
        if (array_key_exists('body', $output) && array_key_exists('status', $output) && array_key_exists('success', $output)) return $output['body'];
        $exchanges = is_array($output['amo_exchange'] ?? null) ? $output['amo_exchange'] : [];
        $last = array_key_last($exchanges);
        if ($last !== null && array_key_exists('body', $exchanges[$last]['response'] ?? [])) return self::entity($exchanges[$last]['response']['body']);
        unset($output['amo_exchange'], $output['amo_exchange_truncated']);
        return $output;
    }

    public static function entity(mixed $body): mixed
    {
        if (!is_array($body)) return $body;
        unset($body['_links'], $body['_page'], $body['_total_items']);
        if (isset($body['_embedded']) && is_array($body['_embedded'])) {
            $embedded = $body['_embedded'];
            unset($body['_embedded']);
            if ($body === [] && count($embedded) === 1) return self::entity(reset($embedded));
            $body += $embedded;
        }
        foreach ($body as $key => $value) $body[$key] = self::entity($value);
        return $body;
    }
}
