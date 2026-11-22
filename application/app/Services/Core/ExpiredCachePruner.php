<?php

namespace App\Services\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ExpiredCachePruner
{
    public function prune(bool $dryRun = false, int $chunk = 500): int
    {
        $total = 0;
        foreach (['cache', 'monitoring_cache'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if ($dryRun) {
                $total += DB::table($table)->where('expiration', '<=', now()->timestamp)->count();
                continue;
            }
            while (true) {
                $keys = DB::table($table)->where('expiration', '<=', now()->timestamp)
                    ->orderBy('key')->limit(max(1, $chunk))->pluck('key');
                if ($keys->isEmpty()) {
                    break;
                }
                $total += DB::table($table)->whereIn('key', $keys->all())
                    ->where('expiration', '<=', now()->timestamp)->delete();
            }
        }

        return $total;
    }
}
