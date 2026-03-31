<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Assistant\AnalyticsRangeRequest;
use App\Http\Requests\Api\Assistant\ManagerSummaryRequest;
use App\Models\Integrations\Assistant\Setting;
use App\Models\User;
use App\Services\Assistant\AssistantAnalyticsService;
use App\Services\Assistant\AssistantLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AssistantAnalyticsController extends Controller
{
    public function __construct(private readonly AssistantLogService $logService)
    {
    }

    public function departmentSummary(User $user, AnalyticsRangeRequest $request): JsonResponse
    {
        return $this->handle($user, $request, 'assistant.department-summary', function (
            AssistantAnalyticsService $service,
            Setting $setting
        ) use ($user, $request) {
            return $service->departmentSummary($user, $setting, (int)$request->integer('limit', 10));
        });
    }

    private function handle(User $user, Request $request, string $endpoint, callable $callback): JsonResponse
    {
        $setting = $this->resolveSetting($user, $request);
        $startedAt = microtime(true);

        if (!$setting) {
            return response()->json([
                'message' => 'Assistant setting not found',
            ], 403);
        }

        try {
            $service = AssistantAnalyticsService::forUser($user);
            $data = $callback($service, $setting);

            $response = [
                'data' => $data,
                'meta' => $this->meta($user),
            ];

            $this->logService->logEndpoint(
                $setting,
                $user,
                $endpoint,
                $request->all(),
                $response,
                ['tool' => $endpoint],
                $startedAt
            );

            return response()->json($response);
        } catch (Throwable $exception) {
            $this->logService->logError(
                $setting,
                $user,
                $endpoint,
                $request->all(),
                $exception,
                ['tool' => $endpoint],
                $startedAt
            );

            return response()->json([
                'message' => 'Assistant endpoint failed',
            ], 500);
        }
    }

    private function resolveSetting(User $user, Request $request): ?Setting
    {
        $setting = $request->attributes->get('assistant_setting');

        if ($setting instanceof Setting) {
            return $setting;
        }

        return Setting::query()
            ->where('user_id', $user->id)
            ->first();
    }

    private function meta(User $user): array
    {
        return [
            'generated_at' => now()->toDateTimeString(),
            'user_id' => $user->id,
            'account_id' => $user->account?->id,
            'contract' => 'assistant.v1',
        ];
    }

    public function managerSummary(User $user, ManagerSummaryRequest $request): JsonResponse
    {
        return $this->handle($user, $request, 'assistant.manager-summary', function (
            AssistantAnalyticsService $service,
            Setting $setting
        ) use ($user, $request) {
            return $service->managerSummary(
                $user,
                $setting,
                (int)$request->integer('manager_id'),
                (int)$request->integer('limit', 10)
            );
        });
    }

    public function riskyDeals(User $user, AnalyticsRangeRequest $request): JsonResponse
    {
        return $this->handle($user, $request, 'assistant.risky-deals', function (
            AssistantAnalyticsService $service,
            Setting $setting
        ) use ($user, $request) {
            return $service->riskyDeals($user, $setting, (int)$request->integer('limit', 20));
        });
    }

    public function dealContext(User $user, Request $request, int $deal): JsonResponse
    {
        return $this->handle($user, $request, 'assistant.deal-context', function (
            AssistantAnalyticsService $service,
            Setting $setting
        ) use ($user, $deal) {
            return $service->dealContext($user, $setting, $deal);
        });
    }

    public function unprocessedLeads(User $user, AnalyticsRangeRequest $request): JsonResponse
    {
        return $this->handle($user, $request, 'assistant.unprocessed-leads', function (
            AssistantAnalyticsService $service,
            Setting $setting
        ) use ($user, $request) {
            return $service->unprocessedLeads($user, $setting, (int)$request->integer('limit', 20));
        });
    }

    public function overdueTasks(User $user, AnalyticsRangeRequest $request): JsonResponse
    {
        return $this->handle($user, $request, 'assistant.overdue-tasks', function (
            AssistantAnalyticsService $service,
            Setting $setting
        ) use ($user, $request) {
            return $service->overdueTasks($user, $setting, (int)$request->integer('limit', 20));
        });
    }

    public function dealsWithoutNextTask(User $user, AnalyticsRangeRequest $request): JsonResponse
    {
        return $this->handle($user, $request, 'assistant.deals-without-next-task', function (
            AssistantAnalyticsService $service,
            Setting $setting
        ) use ($user, $request) {
            return $service->dealsWithoutNextTask($user, $setting, (int)$request->integer('limit', 20));
        });
    }

    public function conversionDelta(User $user, AnalyticsRangeRequest $request): JsonResponse
    {
        return $this->handle($user, $request, 'assistant.conversion-delta', function (
            AssistantAnalyticsService $service,
            Setting $setting
        ) use ($user, $request) {
            return $service->conversionDelta($user, $setting, (int)$request->integer('days', 7));
        });
    }

    public function dailySummary(User $user, AnalyticsRangeRequest $request): JsonResponse
    {
        return $this->handle($user, $request, 'assistant.daily-summary', function (
            AssistantAnalyticsService $service,
            Setting $setting
        ) use ($user, $request) {
            return $service->dailySummary($user, $setting, (int)$request->integer('limit', 10));
        });
    }

    public function weeklySummary(User $user, AnalyticsRangeRequest $request): JsonResponse
    {
        return $this->handle($user, $request, 'assistant.weekly-summary', function (
            AssistantAnalyticsService $service,
            Setting $setting
        ) use ($user, $request) {
            return $service->weeklySummary($user, $setting, (int)$request->integer('limit', 10));
        });
    }
}
