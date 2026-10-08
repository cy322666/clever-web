<?php

namespace App\Services\amoCRM;

use App\Models\Core\Account;
use Illuminate\Support\Sleep;
use RuntimeException;
use Ufee\Amo\Base\Models\QueryModel;
use Ufee\Amo\Collections\QueryCollection;

final class ThrottledQueryCollection extends QueryCollection
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private Account $account)
    {
        parent::__construct();
    }

    public function getDelay()
    {
        // Preserve a caller's local delay before acquiring a shared request slot.
        if ($last = $this->last()) {
            $remaining = $this->delay - (microtime(true) - $last->start_time);

            if ($remaining > 0) {
                Sleep::usleep((int) ceil($remaining * 1_000_000));
            }
        }

        // Ufee calls this for every HTTP attempt, including its internal retries.
        app(AmoCrmRequestThrottle::class)->acquire($this->account);

        // The SDK calculated its timestamp before invoking this hook.
        return 0;
    }

    public function pushByCode($code, QueryModel $query)
    {
        if ((int) $code === 429) {
            // Ufee does not expose response headers; use the shared fallback cooldown.
            app(AmoCrmRequestThrottle::class)->cooldown($this->account, null, $query->getRetries());
        }

        parent::pushByCode($code, $query);

        if (in_array((int) $code, [502, 504], true)) {
            // A gateway failure does not prove that a write was rejected.
            $query->setRetry(false);
        }

        if ((int) $code === 429 && $query->getRetries() >= self::MAX_ATTEMPTS) {
            throw new RuntimeException('amoCRM API rate limit exceeded after 3 attempts.', 429);
        }

        return $this;
    }
}
