<?php

namespace App\Services\amoCRM;

use App\Models\Core\Account;
use Illuminate\Http\Client\Response;

class AmoCrmHttpTransport
{
    public function __construct(private readonly AmoCrmRequestThrottle $throttle) {}

    /** @param callable(): Response $send */
    public function send(Account $account, callable $send): Response
    {
        for ($attempt = 1; ; $attempt++) {
            $this->throttle->acquire($account);
            $response = $send();

            if ($response->status() !== 429) {
                return $response;
            }

            // A confirmed rejection may be retried. Ambiguous transport failures may not.
            try {
                $this->throttle->cooldown($account, $response->header('Retry-After'), $attempt);
            } catch (\Throwable $error) {
                if (!\App\Services\YClients\RecordSyncContext::active()) {
                    throw $error;
                }
                $response->throw();
            }

            if (\App\Services\YClients\RecordSyncContext::active()) {
                $response->throw();
            }

            if ($attempt >= 3) {
                return $response;
            }
        }
    }
}
