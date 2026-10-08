<?php

namespace App\Services\Integrations;

use Illuminate\Http\Request;

final class AmoCrmOffSignature
{
    /** @return array{client_id: string, account_id: int} */
    public function verify(Request $request, ?string $widget = null): array
    {
        $clientId = $request->input('client_uuid', $request->input('client_id'));
        $accountId = $request->input('account_id');
        $signature = $request->input('signature');
        abort_unless(is_string($clientId) && is_scalar($accountId)
            && preg_match('/^[1-9][0-9]{0,17}$/D', (string) $accountId) === 1
            && is_string($signature) && preg_match('/^[a-f0-9]{64}$/D', $signature) === 1,
            403, 'Invalid amoCRM disconnect signature.');

        $credentials = $widget !== null
            ? [config('services.amocrm.widgets.'.$widget, [])]
            : [config('services.amocrm', []), ...array_values(config('services.amocrm.widgets', []))];
        foreach ($credentials as $oauth) {
            $secret = trim((string) ($oauth['client_secret'] ?? ''));
            if (($oauth['client_id'] ?? null) !== $clientId || $secret === '') {
                continue;
            }
            if (hash_equals(hash_hmac('sha256', $clientId.'|'.$accountId, $secret), $signature)) {
                return ['client_id' => $clientId, 'account_id' => (int) $accountId];
            }
        }
        abort(403, 'Invalid amoCRM disconnect signature.');
    }
}
