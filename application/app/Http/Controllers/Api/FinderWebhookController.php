<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Integrations\Finder\Setting;
use App\Services\Finder\Monitor;
use App\Services\Finder\WebhookConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinderWebhookController extends Controller
{
    public function __invoke(Request $request, Setting $setting, string $signature, WebhookConnection $connection, Monitor $monitor): JsonResponse
    {
        abort_unless(hash_equals($connection->signature($setting), $signature), 403);
        $account = $setting->account;
        abort_unless($account && (int) $account->user_id === (int) $setting->user_id, 403);
        $domain = $request->input('account.subdomain');
        $accountId = $request->input('account.id');
        abort_if($domain && strcasecmp((string) $domain, (string) $account->subdomain) !== 0, 403);
        abort_if($accountId && $account->amo_account_id && (int) $accountId !== (int) $account->amo_account_id, 403);
        $request->validate(['message.add' => 'sometimes|array|max:100', 'outgoing_message.add' => 'sometimes|array|max:100']);

        return response()->json(['accepted' => $monitor->ingest($setting, $request->all())]);
    }
}
