<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\Billing\WidgetSubscriptionAccessService;
use App\Services\Workflows\WorkflowManualAmoCrmRunService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkflowManualAmoCrmController extends Controller
{
    public function index(Request $request, WidgetSubscriptionAccessService $access): JsonResponse
    {
        $account = $this->resolveAccount($request);

        if (!$account instanceof Account) {
            return response()->json([
                'ok' => false,
                'message' => 'Подключение amoCRM для сценариев не найдено.',
                'workflows' => [],
            ]);
        }

        if (!$access->canUse((int)$account->user_id, 'workflows')) {
            return response()->json([
                'ok' => false,
                'message' => 'Доступ к виджету сценариев не активен.',
                'workflows' => [],
            ]);
        }

        $workflows = $this->manualWorkflowQuery((int)$account->user_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn(Workflow $workflow): array => [
                'id' => (int)$workflow->id,
                'name' => (string)$workflow->name,
            ])
            ->values();

        return response()->json([
            'ok' => true,
            'workflows' => $workflows,
        ]);
    }

    private function resolveAccount(Request $request): ?Account
    {
        $subdomain = $this->normalizeSubdomain(
            (string)($request->input('subdomain') ?: $request->input('account_subdomain'))
        );

        if ($subdomain === '') {
            return null;
        }

        return Account::query()
            ->where('active', true)
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->where(function (Builder $query): void {
                $query
                    ->where('widget', 'workflows')
                    ->orWhere(function (Builder $query): void {
                        $query->where('user_id', 1)
                            ->whereNotNull('subdomain');
                    });
            })
            ->orderByRaw("case when widget = 'workflows' then 0 else 1 end")
            ->latest('id')
            ->first();
    }

    private function normalizeSubdomain(string $value): string
    {
        $value = Str::lower(trim($value));

        if ($value === '') {
            return '';
        }

        $value = preg_replace('#^https?://#', '', $value) ?? $value;
        $value = explode('/', $value)[0] ?? $value;

        foreach (['.amocrm.ru', '.amocrm.com', '.kommo.com'] as $suffix) {
            if (str_ends_with($value, $suffix)) {
                return substr($value, 0, -strlen($suffix));
            }
        }

        return $value;
    }

    private function manualWorkflowQuery(int $userId): Builder
    {
        return Workflow::query()
            ->where(config('filament-workflows.tenancy.column', 'user_id'), $userId)
            ->where('is_active', true)
            ->where('definition->trigger->type', 'manual');
    }

    public function run(
        Request $request,
        WidgetSubscriptionAccessService $access,
        WorkflowManualAmoCrmRunService $manualRuns,
    ): JsonResponse {
        $validated = $request->validate([
            'workflow_id' => ['required', 'integer'],
            'lead_id' => ['required', 'integer', 'min:1'],
            'lead_name' => ['nullable', 'string', 'max:255'],
            'subdomain' => ['nullable', 'string', 'max:255'],
            'account_subdomain' => ['nullable', 'string', 'max:255'],
        ]);

        $account = $this->resolveAccount($request);

        if (!$account instanceof Account) {
            return response()->json([
                'ok' => false,
                'message' => 'Подключение amoCRM для сценариев не найдено.',
            ], 404);
        }

        if (!$access->canUse((int)$account->user_id, 'workflows')) {
            return response()->json([
                'ok' => false,
                'message' => 'Доступ к виджету сценариев не активен.',
            ], 403);
        }

        $workflow = $this->manualWorkflowQuery((int)$account->user_id)
            ->whereKey((int)$validated['workflow_id'])
            ->first();

        if (!$workflow instanceof Workflow) {
            return response()->json([
                'ok' => false,
                'message' => 'Ручной сценарий не найден или выключен.',
            ], 404);
        }

        $run = $manualRuns->startForLead(
            workflow: $workflow,
            account: $account,
            leadId: (int)$validated['lead_id'],
            input: [
                'lead_name' => (string)($validated['lead_name'] ?? ''),
            ],
        );

        return response()->json([
            'ok' => true,
            'queued' => true,
            'run_id' => $run['run_id'],
            'run_ulid' => $run['run_ulid'],
        ], 202);
    }
}
