<?php

namespace App\Services\Integrations;

use App\Models\Core\Account;
use App\Models\Integrations\Finder\Setting as FinderSetting;
use App\Services\Finder\MonitoringState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class AmoCrmDisconnectService
{
    /**
     * Call only after verifying the callback signature. Returns pre-disconnect
     * snapshots for notifications; repeated callbacks do not queue more emails.
     *
     * @return Collection<int, Account>
     */
    public function disconnect(string $clientId, int $amoAccountId, ?string $widget = null): Collection
    {
        $widget = $widget !== null ? Account::normalizeWidget($widget) : null;

        return DB::transaction(function () use ($clientId, $amoAccountId, $widget): Collection {
            $accounts = Account::query()
                ->when($widget !== null, fn (Builder $query) => $query->where('widget', $widget))
                ->where(function (Builder $query) use ($clientId, $widget): void {
                    $query->where('client_id', $clientId);

                    // Only YClients explicitly supports a legacy shared connector.
                    if ($widget === 'yclients'
                        && config('services.amocrm.widgets.yclients.use_shared_connector', true)
                        && filled(config('services.amocrm.client_id'))) {
                        $query->orWhere(fn (Builder $query) => $query
                            ->where('client_id', config('services.amocrm.client_id'))
                            ->where(fn (Builder $query) => $query->whereNull('oauth_connector')
                                ->orWhere('oauth_connector', Account::CONNECTOR_SHARED)));
                    }
                })
                ->where(fn (Builder $query) => $query->where('amo_account_id', $amoAccountId)->orWhereNull('amo_account_id'))
                ->where(fn (Builder $query) => $query->where('active', true)
                    ->orWhereNotNull('access_token')->orWhereNotNull('refresh_token')->orWhereNotNull('code'))
                ->with('user:id,name,email')
                ->lockForUpdate()
                ->get();
            $disconnected = new Collection;

            foreach ($accounts as $account) {
                $storedId = $account->amo_account_id ?? $this->legacyAccountId($account);
                if ($storedId !== $amoAccountId) {
                    continue;
                }

                $disconnected->push(clone $account);
                $account->forceFill([
                    'code' => null,
                    'access_token' => null,
                    'refresh_token' => null,
                    'subdomain' => null,
                    'active' => false,
                ])->save();

                if ($account->widget === 'finder') {
                    // Finder can use another connection, so stop its monitoring
                    // rather than revoking that other widget's authorization.
                    foreach (FinderSetting::query()->where('user_id', $account->user_id)->get() as $setting) {
                        if ($setting->app()->exists()) {
                            app(MonitoringState::class)->setEnabled($setting, false);
                        }
                    }
                }
            }

            return $disconnected;
        });
    }

    private function legacyAccountId(Account $account): ?int
    {
        // This is metadata from a token already stored by our OAuth exchange,
        // never a request token or proof of authorization. Expiry is irrelevant.
        $parts = explode('.', (string) $account->access_token);
        if (count($parts) !== 3 || $parts[2] === '') {
            return null;
        }

        $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $decoded !== false ? json_decode($decoded, true) : null;
        if (! is_array($claims) || ($claims['aud'] ?? null) !== $account->client_id) {
            return null;
        }

        $id = $claims['account_id'] ?? null;

        return (is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]{0,17}$/D', (string) $id) === 1
            ? (int) $id
            : null;
    }
}
