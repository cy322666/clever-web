<?php

namespace App\Console\Commands\Integrations;

use App\Exceptions\AmoCrmOwnershipConflict;
use App\Jobs\Integrations\SendAmoCrmOwnershipAlert;
use App\Models\Core\Account;
use App\Services\Core\MonitoringCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class CheckAmoCrmOwnership extends Command
{
    protected $signature = 'app:check-amo-ownership {--resolve-ids : Read missing CRM IDs using existing access tokens} {--limit=25} {--no-alert}';

    protected $description = 'Check amoCRM ownership without merging or deleting customer data';

    public function handle(): int
    {
        $accounts = Account::query()->whereNotNull('subdomain')->where('subdomain', '<>', '')->get();
        $conflicts = [];
        foreach ([$accounts->groupBy(fn ($a) => strtolower(trim($a->subdomain)).':'.strtolower($a->zone ?: 'ru')),
            $accounts->whereNotNull('amo_account_id')->groupBy('amo_account_id')] as $groups) {
            foreach ($groups as $group) {
                $users = $group->pluck('user_id')->unique()->sort()->values()->all();
                if (count($users) < 2) {
                    continue;
                }
                $ids = $group->pluck('id')->sort()->values()->all();
                $key = implode(',', $ids);
                if (isset($conflicts[$key])) {
                    continue;
                }
                $domain = $group->first()->subdomain.'.amocrm.'.($group->first()->zone ?: 'ru');
                $conflicts[$key] = $ids;
                $this->warn($domain.': platform users '.implode(', ', $users));
                if (! $this->option('no-alert')) {
                    SendAmoCrmOwnershipAlert::dispatch('conflict', $domain, $users, $ids);
                }
            }
        }
        $conflictingIds = array_merge([], ...array_values($conflicts));
        $resolved = 0;
        if ($this->option('resolve-ids')) {
            foreach ($accounts->where('active', true)->whereNull('amo_account_id')->whereNotIn('id', $conflictingIds)
                ->take(max(1, min(250, (int) $this->option('limit')))) as $account) {
                if (! filled($account->access_token)) {
                    continue;
                }
                $zone = strtolower($account->zone ?: 'ru');
                if (! in_array($zone, ['ru', 'com'], true) || ! preg_match('/^[a-z0-9-]+$/D', $account->subdomain)) {
                    continue;
                }
                $host = $account->subdomain.($zone === 'ru' ? '.amocrm.ru' : '.kommo.com');
                try {
                    $response = Http::withToken($account->access_token)->acceptJson()->connectTimeout(3)->timeout(8)
                        ->withoutRedirecting()->get('https://'.$host.'/api/v4/account');
                    $id = $response->json('id');
                    if (! $response->successful() || ! is_int($id) || $id <= 0) {
                        continue;
                    }
                    $account->forceFill(['amo_account_id' => $id])->save();
                    $resolved++;
                } catch (AmoCrmOwnershipConflict) {
                    $this->warn('Connection '.$account->id.': owner conflict; identity was not changed.');
                } catch (\Throwable) {
                    $this->warn('Connection '.$account->id.': CRM identity could not be verified.');
                }
            }
        }
        $missing = Account::query()->where('active', true)->whereNull('amo_account_id')
            ->whereNotNull('subdomain')->where('subdomain', '<>', '')->get(['id', 'user_id']);
        $missingIds = $missing->pluck('id')->sort()->values()->all();
        $missingFingerprint = hash('sha256', json_encode($missingIds));
        // Legacy metadata gaps are not incidents; keep diagnostics without paging the administrator.
        if (MonitoringCache::get('monitoring:amo:ownership:missing_fingerprint') !== $missingFingerprint) {
            Log::info('amocrm.ownership.missing_ids', [
                'count' => $missing->count(), 'account_ids' => $missingIds,
                'user_ids' => $missing->pluck('user_id')->unique()->sort()->values()->all(),
            ]);
            MonitoringCache::forever('monitoring:amo:ownership:missing_fingerprint', $missingFingerprint);
        }
        MonitoringCache::forever('monitoring:amo:ownership:missing_count', $missing->count());
        $this->line('Conflicting groups: '.count($conflicts).'; missing CRM IDs: '.$missing->count().'; resolved: '.$resolved);

        return self::SUCCESS;
    }
}
