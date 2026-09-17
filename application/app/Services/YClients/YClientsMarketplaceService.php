<?php

namespace App\Services\YClients;

use App\Models\App;
use App\Models\Integrations\YClients\MarketplaceInstallation;
use App\Models\Integrations\YClients\Setting;
use App\Models\User;
use App\Services\Billing\WidgetSubscriptionAccessService;
use App\Services\Integrations\IntegrationProvisioningService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class YClientsMarketplaceService
{
    public const SESSION_KEY = 'yclients_marketplace_install';

    private const ACTIVATION_TTL_SECONDS = 3600;

    public function registrationRedirect(Request $request): RedirectResponse
    {
        $context = $this->registrationContext($request);
        $request->session()->put(self::SESSION_KEY, $context);

        if ($request->user() instanceof User) {
            $this->activateFromContext($request, $request->user(), $context);

            return redirect()->to($this->dashboardUrl());
        }

        $email = Str::lower(trim((string)data_get($context, 'user_data.email', '')));
        $destination = $email !== '' && User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists()
            ? url('/panel/login')
            : url('/panel/register');

        return redirect()->to($destination);
    }

    public function activatePendingForAuthenticatedUser(Request $request): bool
    {
        $context = $request->session()->get(self::SESSION_KEY);

        if (!is_array($context) || !$request->user() instanceof User) {
            return false;
        }

        if (!$this->contextIsFresh($context)) {
            $request->session()->forget(self::SESSION_KEY);
            Log::warning('yclients.marketplace.activation_expired', [
                'user_id' => $request->user()->id,
                'salon_id' => data_get($context, 'salon_id'),
            ]);

            return false;
        }

        return $this->activateFromContext($request, $request->user(), $context);
    }

    public function handleCallback(Request $request): JsonResponse
    {
        $payload = $request->all();
        $configuredPartnerToken = $this->configuredPartnerToken();

        if ($configuredPartnerToken === '') {
            Log::critical('yclients.marketplace.callback_not_configured');

            return response()->json(['ok' => false, 'message' => 'Marketplace callback is not configured.'], 503);
        }

        $incomingPartnerToken = trim((string)data_get($payload, 'partner_token', ''));
        if ($incomingPartnerToken === '' || !hash_equals($configuredPartnerToken, $incomingPartnerToken)) {
            Log::warning('yclients.marketplace.callback_invalid_token', [
                'salon_id' => data_get($payload, 'salon_id'),
                'application_id' => data_get($payload, 'application_id'),
            ]);

            return response()->json(['ok' => false, 'message' => 'Invalid partner token.'], 401);
        }

        $salonId = $this->positiveInteger(data_get($payload, 'salon_id'));
        $applicationId = $this->positiveInteger(data_get($payload, 'application_id'));
        $event = Str::lower(trim((string)data_get($payload, 'event', '')));
        $configuredApplicationId = $this->configuredApplicationId();

        if (!$salonId || !$applicationId || $event === '') {
            return response()->json(['ok' => false, 'message' => 'Invalid marketplace callback payload.'], 422);
        }

        if ($configuredApplicationId && $configuredApplicationId !== $applicationId) {
            Log::warning('yclients.marketplace.callback_wrong_application', [
                'application_id' => $applicationId,
                'expected_application_id' => $configuredApplicationId,
                'salon_id' => $salonId,
            ]);

            return response()->json(['ok' => false, 'message' => 'Unknown application.'], 403);
        }

        Log::info('yclients.marketplace.callback_received', [
            'salon_id' => $salonId,
            'application_id' => $applicationId,
            'event' => $event,
        ]);

        $installation = MarketplaceInstallation::query()
            ->where('salon_id', $salonId)
            ->where('application_id', $applicationId)
            ->first();

        if (!$installation) {
            Log::notice('yclients.marketplace.callback_installation_not_found', [
                'salon_id' => $salonId,
                'application_id' => $applicationId,
                'event' => $event,
            ]);

            return response()->json(['ok' => true, 'status' => 'ignored']);
        }

        DB::transaction(function () use ($event, $installation, $payload): void {
            $installation->last_payload = $this->redactPayload($payload);
            $installation->last_error = null;

            if ($event === 'uninstall') {
                $installation->status = MarketplaceInstallation::STATUS_UNINSTALLED;
                $installation->disconnected_at = now();
            } elseif ($event === 'freeze') {
                $installation->status = MarketplaceInstallation::STATUS_FROZEN;
            } elseif ($event === 'payment') {
                $installation->status = MarketplaceInstallation::STATUS_ACTIVE;
                $installation->disconnected_at = null;
            }

            $installation->save();

            if ($event === 'uninstall' && $installation->user_id) {
                $hasActiveInstallation = MarketplaceInstallation::query()
                    ->where('user_id', $installation->user_id)
                    ->where('application_id', $installation->application_id)
                    ->where('status', MarketplaceInstallation::STATUS_ACTIVE)
                    ->exists();

                if (!$hasActiveInstallation) {
                    $this->deactivateLocalIntegration($installation);
                }
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{salon_id:int, application_id:int|null, user_data:array, received_at:int}
     */
    private function registrationContext(Request $request): array
    {
        $salonId = $this->positiveInteger($request->query('salon_id'));

        if (!$salonId) {
            abort(422, 'YClients salon_id is required.');
        }

        $queryApplicationId = $this->positiveInteger($request->query('application_id'));
        $configuredApplicationId = $this->configuredApplicationId();

        if ($queryApplicationId && $configuredApplicationId && $queryApplicationId !== $configuredApplicationId) {
            abort(422, 'Unknown YClients application.');
        }

        return [
            'salon_id' => $salonId,
            'application_id' => $queryApplicationId,
            'user_data' => $this->decodeUserData($request),
            'received_at' => now()->timestamp,
        ];
    }

    private function decodeUserData(Request $request): array
    {
        $encoded = trim((string)$request->query('user_data', ''));
        $signature = trim((string)$request->query('user_data_sign', ''));

        if ($encoded === '' && $signature === '') {
            return [];
        }

        $partnerToken = $this->configuredPartnerToken();
        $decoded = base64_decode($encoded, true);

        if ($partnerToken === '' || $decoded === false || !hash_equals(
            hash_hmac('sha256', $decoded, $partnerToken),
            $signature
        )) {
            abort(422, 'Invalid YClients user data signature.');
        }

        try {
            $data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(422, 'Invalid YClients user data.');
        }

        return is_array($data) ? $data : [];
    }

    private function activateFromContext(Request $request, User $user, array $context): bool
    {
        try {
            $this->activateForUser($user, $context);
            $request->session()->forget(self::SESSION_KEY);

            return true;
        } catch (Throwable $exception) {
            Log::error('yclients.marketplace.activation_failed', [
                'user_id' => $user->id,
                'salon_id' => data_get($context, 'salon_id'),
                'application_id' => data_get($context, 'application_id'),
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * @param array{salon_id:int, application_id?:int|null, received_at?:int} $context
     */
    public function activateForUser(User $user, array $context): MarketplaceInstallation
    {
        $salonId = $this->positiveInteger($context['salon_id'] ?? null);
        $applicationId = $this->configuredApplicationId();
        $partnerToken = $this->configuredPartnerToken();
        $userToken = trim((string)config('services.yclients.marketplace_user_token', ''));

        if (!$salonId || !$applicationId || $partnerToken === '' || $userToken === '') {
            throw new RuntimeException(
                'YClients Marketplace requires application ID, partner token and system user token.'
            );
        }

        $contextApplicationId = $this->positiveInteger($context['application_id'] ?? null);
        if ($contextApplicationId && $contextApplicationId !== $applicationId) {
            throw new RuntimeException('YClients Marketplace application ID does not match.');
        }

        $installation = MarketplaceInstallation::query()
            ->where('salon_id', $salonId)
            ->where('application_id', $applicationId)
            ->first();

        if ($installation?->user_id && (int)$installation->user_id !== (int)$user->id) {
            if ($installation->status !== MarketplaceInstallation::STATUS_UNINSTALLED) {
                throw new RuntimeException('YClients salon is already linked to another platform user.');
            }
        }

        $alreadyActive = $installation
            && (int)$installation->user_id === (int)$user->id
            && $installation->status === MarketplaceInstallation::STATUS_ACTIVE;

        if (!$alreadyActive) {
            $this->sendActivationRequest($salonId, $applicationId, $partnerToken);
        }

        return $this->provisionLocalIntegration(
            $user,
            $salonId,
            $applicationId,
            $partnerToken,
            $userToken,
        );
    }

    private function sendActivationRequest(int $salonId, int $applicationId, string $partnerToken): void
    {
        $payload = [
            'salon_id' => $salonId,
            'application_id' => $applicationId,
        ];

        $response = $this->marketplaceRequest($partnerToken)
            ->post((string)config('services.yclients.marketplace_activation_url'), $payload);

        if (!$response->successful()) {
            throw new RuntimeException(
                'YClients Marketplace activation failed with HTTP '.$response->status().'.'
            );
        }
    }

    private function provisionLocalIntegration(
        User $user,
        int $salonId,
        int $applicationId,
        string $partnerToken,
        string $userToken
    ): MarketplaceInstallation {
        $provisioning = app(IntegrationProvisioningService::class);
        $provisioning->syncCatalogForUser($user);

        $app = App::query()
            ->where('user_id', $user->id)
            ->where('name', 'yclients')
            ->first();

        if (!$app) {
            throw new RuntimeException('YClients application record was not provisioned.');
        }

        $app = $provisioning->ensureSettingForApp($app);
        $setting = Setting::query()->find($app->setting_id);

        if (!$setting) {
            throw new RuntimeException('YClients setting was not provisioned.');
        }

        $setting->forceFill([
            'partner_token' => $partnerToken,
            'user_token' => $userToken,
            'active' => app(WidgetSubscriptionAccessService::class)->canUse($user, 'yclients'),
        ])->save();

        app(WidgetSubscriptionAccessService::class)->ensureTrialForWidget(
            $user,
            'yclients',
            (int)config('integrations.definitions.yclients.trial_days', 7),
        );

        $setting->active = app(WidgetSubscriptionAccessService::class)->canUse($user, 'yclients');
        $setting->save();

        return DB::transaction(function () use ($app, $applicationId, $salonId, $setting, $user): MarketplaceInstallation {
            $installation = MarketplaceInstallation::query()
                ->where('salon_id', $salonId)
                ->where('application_id', $applicationId)
                ->lockForUpdate()
                ->first();

            $installation ??= new MarketplaceInstallation([
                'salon_id' => $salonId,
                'application_id' => $applicationId,
            ]);

            $installation->fill([
                'user_id' => $user->id,
                'setting_id' => $setting->id,
                'status' => MarketplaceInstallation::STATUS_ACTIVE,
                'disconnected_at' => null,
                'last_error' => null,
            ]);

            if (!$installation->connected_at || $installation->isDirty('status')) {
                $installation->connected_at = now();
            }

            $installation->save();

            return $installation;
        });
    }

    private function deactivateLocalIntegration(MarketplaceInstallation $installation): void
    {
        if (!$installation->user_id) {
            return;
        }

        $setting = $installation->setting_id
            ? Setting::query()->find($installation->setting_id)
            : Setting::query()->where('user_id', $installation->user_id)->first();

        if ($setting) {
            $setting->active = false;
            $setting->save();
        }

        $app = App::query()
            ->where('user_id', $installation->user_id)
            ->where('name', 'yclients')
            ->first();

        if ($app && (int)$app->status === App::STATE_ACTIVE) {
            $app->status = App::STATE_INACTIVE;
            $app->save();
        }
    }

    private function contextIsFresh(array $context): bool
    {
        $receivedAt = (int)($context['received_at'] ?? 0);

        return $receivedAt > 0 && $receivedAt >= now()->timestamp - self::ACTIVATION_TTL_SECONDS;
    }

    private function configuredApplicationId(): ?int
    {
        return $this->positiveInteger(config('services.yclients.marketplace_application_id'));
    }

    private function configuredPartnerToken(): string
    {
        return trim((string)config('services.yclients.marketplace_partner_token', ''));
    }

    private function marketplaceRequest(string $partnerToken): PendingRequest
    {
        return Http::accept('application/vnd.api.v2+json')
            ->asJson()
            ->withToken($partnerToken)
            ->connectTimeout(10)
            ->timeout(20);
    }

    private function dashboardUrl(): string
    {
        return route('filament.app.pages.dashboard');
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string)$value);
        if (!preg_match('/^[1-9][0-9]*$/', $value)) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    private function redactPayload(array $payload): array
    {
        foreach (['partner_token', 'sign'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[redacted]';
            }
        }

        return $payload;
    }
}
