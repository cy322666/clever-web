<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use Illuminate\Http\Request;

final class WorkflowRequestAccess
{
    public const SETTING_PATHS = [
        'workflow_id', 'settings.workflow_id', 'widget.settings.workflow_id',
        'data.settings.workflow_id', 'entity.settings.workflow_id', 'action.settings.workflow_id',
        'action.settings.widget.settings.workflow_id', 'action.params.workflow_id', 'params.workflow_id',
    ];

    public function authenticate(Request $request, bool $digitalPipeline = false): Account
    {
        if ($digitalPipeline) {
            return $this->digitalPipelineAccount($request);
        }

        $token = $request->header('X-Auth-Token');
        abort_unless(is_string($token) && strlen($token) <= 16384, 403, 'amoCRM authorization is required.');
        $parts = explode('.', $token);
        abort_unless(count($parts) === 3, 403, 'Invalid amoCRM authorization.');
        [$header, $claims] = [$this->decode($parts[0]), $this->decode($parts[1])];
        $clientId = trim((string) config('services.amocrm.widgets.workflows.client_id'));
        $secret = trim((string) config('services.amocrm.widgets.workflows.client_secret'));
        abort_unless($clientId !== '' && $secret !== '' && ($header['alg'] ?? '') === 'HS256'
            && ! isset($header['crit']) && ($claims['client_uuid'] ?? '') === $clientId, 403, 'Invalid amoCRM authorization.');
        $signature = $this->base64Url(hash_hmac('sha256', $parts[0].'.'.$parts[1], $secret, true));
        abort_unless(hash_equals($signature, $parts[2]), 403, 'Invalid amoCRM signature.');

        $now = time();
        foreach (['exp', 'iat', 'nbf', 'account_id', 'user_id'] as $field) {
            abort_unless(isset($claims[$field]) && is_int($claims[$field]) && $claims[$field] > 0, 403, 'Invalid amoCRM identity.');
        }
        abort_unless($claims['exp'] > $now && $claims['nbf'] <= $now + 30 && $claims['iat'] <= $now + 30
            && $claims['exp'] > $claims['iat'] && $claims['exp'] - $claims['iat'] <= 3600,
            403, 'amoCRM authorization has expired.');

        $audience = $this->origin((string) config('services.amocrm.widgets.workflows.redirect_uri'));
        abort_unless($audience !== null && ($claims['aud'] ?? null) === $audience, 403, 'Invalid amoCRM audience.');
        $issuer = is_string($claims['iss'] ?? null) ? $claims['iss'] : '';
        abort_unless(preg_match('#^https://([a-z0-9-]+)\.(amocrm\.ru|amocrm\.com|kommo\.com)/?$#D', $issuer, $domain) === 1,
            403, 'Invalid amoCRM issuer.');
        $zone = $domain[2] === 'amocrm.ru' ? 'ru' : 'com';

        $accounts = $this->accounts()->where('amo_account_id', $claims['account_id'])
            ->whereRaw('LOWER(subdomain) = ?', [$domain[1]])
            ->whereRaw("LOWER(COALESCE(NULLIF(zone, ''), 'ru')) = ?", [$zone])->get();
        abort_unless($accounts->count() === 1, 403, 'Signed amoCRM connection was not found.');
        $account = $accounts->first();
        $this->assertPayloadIdentity($request, $account);

        return $account;
    }

    public function digitalPipelineToken(Account $account, Workflow $workflow): string
    {
        abort_unless((int) $workflow->user_id === (int) $account->user_id, 403);
        $prefix = $workflow->id.'.'.$account->id;
        $binding = implode('|', [$prefix, $account->user_id, $account->amo_account_id,
            $account->subdomain, $account->zone ?: 'ru', $account->client_id]);
        $key = (string) config('app.key');
        abort_unless($key !== '', 503, 'Workflow signing is not configured.');

        return $prefix.'.'.hash_hmac('sha256', 'workflow-digital-pipeline|'.$binding, $key);
    }

    public static function workflowSetting(array $payload): string
    {
        foreach (self::SETTING_PATHS as $path) {
            $value = data_get($payload, $path);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }

    private function digitalPipelineAccount(Request $request): Account
    {
        $token = self::workflowSetting($request->all());
        abort_unless(preg_match('/^([1-9][0-9]*)\.([1-9][0-9]*)\.[a-f0-9]{64}$/D', $token, $parts) === 1,
            403, 'Save the Digital Pipeline action again using the updated widget.');
        $account = $this->accounts()->find($parts[2]);
        abort_unless($account, 403, 'Invalid Digital Pipeline connection.');
        $workflow = Workflow::query()->where('user_id', $account->user_id)->find($parts[1]);
        abort_unless($workflow && hash_equals($this->digitalPipelineToken($account, $workflow), $token),
            403, 'Invalid Digital Pipeline signature.');
        $this->assertPayloadIdentity($request, $account);

        return $account;
    }

    private function accounts(): \Illuminate\Database\Eloquent\Builder
    {
        $id = trim((string) config('services.amocrm.widgets.workflows.client_id'));
        abort_unless($id !== '', 503, 'Workflow OAuth is not configured.');

        return Account::query()->where('widget', 'workflows')->where('client_id', $id)
            ->where('active', true)->whereNotNull('refresh_token')->where('refresh_token', '<>', '')
            ->whereHas('user', fn ($query) => $query->where('active', true));
    }

    private function assertPayloadIdentity(Request $request, Account $account): void
    {
        foreach (['subdomain', 'account_subdomain', 'account.subdomain', 'account.domain'] as $path) {
            $value = $request->input($path);
            if ($value === null || $value === '') {
                continue;
            }
            abort_unless(is_string($value), 403, 'Invalid account identity.');
            $host = strtolower(preg_replace('#^https?://#', '', rtrim($value, '/')));
            $zone = strtolower($account->zone ?: 'ru');
            $allowed = [$account->subdomain, $account->subdomain.'.amocrm.'.$zone];
            if ($zone === 'com') {
                $allowed[] = $account->subdomain.'.kommo.com';
            }
            abort_unless(in_array($host, $allowed, true), 403, 'Account does not match signed amoCRM identity.');
        }
        foreach (['account_id', 'account.id'] as $path) {
            if ($request->has($path)) {
                $id = $request->input($path);
                abort_unless((is_int($id) || is_string($id)) && (string) $id === (string) $account->amo_account_id,
                    403, 'Account ID mismatch.');
            }
        }
    }

    private function decode(string $part): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]+$/D', $part) === 1, 403, 'Invalid authorization encoding.');
        $decoded = base64_decode(strtr($part, '-_', '+/'), true);
        $value = $decoded === false ? null : json_decode($decoded, true);
        abort_unless(is_array($value) && ! array_is_list($value), 403, 'Invalid authorization payload.');

        return $value;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : null;
    }
}
