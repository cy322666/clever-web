<?php

namespace App\Services\Integrations;

use App\Exceptions\AmoCrmOwnershipConflict;
use App\Jobs\Integrations\SendAmoCrmOwnershipAlert;
use App\Models\Core\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class AmoCrmOwnershipGuard
{
    public function transaction(string $subdomain, string $zone, ?int $amoId, callable $operation): mixed
    {
        try {
            return DB::transaction(function () use ($subdomain, $zone, $amoId, $operation) {
                $keys = ['domain:'.strtolower(trim($subdomain)).':'.strtolower($zone ?: 'ru')];
                if ($amoId) {
                    $keys[] = 'account:'.$amoId;
                }
                sort($keys);
                if (DB::getDriverName() === 'pgsql') {
                    foreach ($keys as $key) {
                        $hash = crc32($key);
                        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [190731, $hash > 2147483647 ? $hash - 4294967296 : $hash]);
                    }
                } elseif (DB::getDriverName() !== 'sqlite') {
                    throw new \LogicException('amoCRM ownership locking is not configured for this database.');
                }

                return $operation();
            }, 3);
        } catch (AmoCrmOwnershipConflict $conflict) {
            if (DB::transactionLevel() === 0) {
                Log::warning('amocrm.ownership.conflict', ['domain' => $conflict->domain, 'user_ids' => $conflict->userIds]);
                try {
                    SendAmoCrmOwnershipAlert::dispatch('conflict', $conflict->domain, $conflict->userIds, $conflict->accountIds);
                } catch (\Throwable $error) {
                    Log::error('amocrm.ownership.alert_dispatch_failed', ['exception_class' => $error::class]);
                }
            }
            throw $conflict;
        }
    }

    public function save(Account $account, callable $save): bool
    {
        return $this->transaction((string) $account->subdomain, (string) ($account->zone ?: 'ru'),
            $account->amo_account_id ? (int) $account->amo_account_id : null, function () use ($account, $save) {
                DB::table('users')->where('id', $account->user_id)->lockForUpdate()->first();
                $owners = Account::query()->where('user_id', '<>', $account->user_id)
                    ->where(function ($query) use ($account) {
                        $query->where(function ($query) use ($account) {
                            $query->whereRaw('LOWER(subdomain) = ?', [strtolower(trim((string) $account->subdomain))]);
                            if (Schema::hasColumn('accounts', 'zone')) {
                                $query->whereRaw("LOWER(COALESCE(NULLIF(zone, ''), 'ru')) = ?", [strtolower($account->zone ?: 'ru')]);
                            }
                        });
                        if ($account->amo_account_id) {
                            $query->orWhere('amo_account_id', $account->amo_account_id);
                        }
                    })->get(['id', 'user_id']);
                if ($owners->isNotEmpty()) {
                    throw new AmoCrmOwnershipConflict(
                        $account->subdomain.'.amocrm.'.($account->zone ?: 'ru'),
                        $owners->pluck('user_id')->push((int) $account->user_id)->unique()->sort()->values()->all(),
                        $owners->pluck('id')->all(),
                    );
                }

                return $save();
            });
    }
}
