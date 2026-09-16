<?php

namespace App\Services\Workflows;

use Illuminate\Support\Facades\DB;

final class WorkflowAnalytics
{
    public static function summarize(int $userId, int $days = 14): array
    {
        abort_unless($userId > 0, 403);
        $days = in_array($days, [7, 14, 30, 90], true) ? $days : 14;
        $from = now()->startOfDay()->subDays($days - 1);
        $query = DB::table('workflow_runs')->where('user_id', $userId)->where('created_at', '>=', $from)->where('created_at', '<=', now());
        $duration = match (DB::connection()->getDriverName()) {
            'pgsql' => 'EXTRACT(EPOCH FROM (completed_at - started_at))',
            'sqlite' => '(julianday(completed_at) - julianday(started_at)) * 86400',
            default => 'TIMESTAMPDIFF(SECOND, started_at, completed_at)',
        };
        $stats = (clone $query)->selectRaw("COUNT(*) AS total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed, AVG(CASE WHEN completed_at >= started_at THEN $duration ELSE NULL END) AS duration")->first();
        $finished = (int)$stats->completed + (int)$stats->failed;
        $daily = (clone $query)->selectRaw("DATE(created_at) AS day, COUNT(*) AS total, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->groupByRaw('DATE(created_at)')->get()->keyBy('day');
        $series = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $from->copy()->addDays($offset);
            $entry = $daily->get($date->toDateString());
            $series[] = ['date' => $date->format('d.m'), 'total' => (int)($entry->total ?? 0), 'failed' => (int)($entry->failed ?? 0)];
        }
        $top = DB::table('workflow_runs as r')->leftJoin('workflows as w', fn($join) => $join->on('w.id', '=', 'r.workflow_id')->where('w.user_id', $userId))
            ->where('r.user_id', $userId)->where('r.created_at', '>=', $from)->where('r.created_at', '<=', now())
            ->selectRaw("r.workflow_id, w.name, COUNT(*) AS total, SUM(CASE WHEN r.status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->groupBy('r.workflow_id', 'w.name')->orderByDesc('total')->limit(8)->get();
        return ['days' => $days, 'total' => (int)$stats->total, 'failed' => (int)$stats->failed,
            'success_rate' => $finished ? round((int)$stats->completed / $finished * 100, 1) : null,
            'duration' => $stats->duration === null ? null : round((float)$stats->duration, 1),
            'series' => $series, 'top' => $top, 'peak' => max(1, ...array_column($series, 'total'))];
    }
}
