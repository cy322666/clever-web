<?php

namespace App\Services\Workflows;

use App\Jobs\Workflows\RunNodePreview;
use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Private, owner-scoped, non-retryable manual loop previews. No saved graph edits. */
final class WorkflowNodePreview
{
    private function key(int $userId, string $id): string
    {
        return 'workflow-loop-preview:'.$userId.':'.$id;
    }

    public function activeId(int $userId, string $componentId): ?string
    {
        return Cache::get($this->key($userId, 'editor:'.$componentId));
    }

    public function start(int $userId, string $componentId, array $step, array $context, array $input, ?int $workflowId, array $definition): string
    {
        abort_unless($userId > 0 && (int) Auth::id() === $userId, 403);
        app(WorkflowSubscriptionAccess::class)->assertCanExecute($userId);
        $connection = config('filament-workflows.queue.connection') ?: config('queue.default');
        if (config('queue.connections.'.$connection.'.driver') === 'sync') {
            throw new RuntimeException('Для запуска цикла нужна фоновая очередь. Синхронная очередь не поддерживается.');
        }
        $editorKey = $this->key($userId, 'editor:'.$componentId);
        return Cache::lock($editorKey.':lock', 10)->block(2, function () use ($editorKey, $userId, $step, $context, $input, $workflowId, $definition): string {
            if ($existing = Cache::get($editorKey)) return $existing;
            $id = (string) Str::uuid();
            $key = $this->key($userId, $id);
            Cache::put($key.':payload', compact('step', 'context', 'input', 'workflowId', 'definition'), now()->addHour());
            Cache::put($key, ['id' => $id, 'node_id' => $step['id'], 'name' => $step['name'] ?? 'Цикл',
                'status' => 'queued', 'sequence' => 0, 'created_at' => now()->timestamp, 'editor_key' => $editorKey], now()->addHour());
            Cache::put($editorKey, $id, now()->addHour());
            try {
                RunNodePreview::dispatch($userId, $id);
            } catch (Throwable $error) {
                $this->fail($userId, $id, 'Не удалось поставить пробный запуск в очередь. Действия не запускались.');
            }
            return $id;
        });
    }

    public function status(int $userId, string $id): ?array
    {
        $key = $this->key($userId, $id);
        $state = Cache::get($key);
        if (!$state) return null;
        if ($state['status'] === 'queued' && now()->timestamp - $state['created_at'] > 120) {
            $this->fail($userId, $id, 'Очередь не начала пробный запуск за 2 минуты. Действия не запускались.', 'queued');
            $state = Cache::get($key);
        } elseif ($state['status'] === 'running' && now()->timestamp - $state['started_at'] > 150) {
            $this->fail($userId, $id, 'Обработчик не ответил вовремя. Повторный запуск не выполнялся. Проверьте результат уже начатого действия.', 'running');
            $state = Cache::get($key);
        }
        if (!$state) return null;
        $state['stopping'] = (bool) Cache::get($key.':cancel', false);
        unset($state['editor_key']);
        return $state;
    }

    public function cancel(int $userId, string $id): void
    {
        $key = $this->key($userId, $id);
        Cache::lock($key.':state-lock', 10)->block(2, function () use ($key): void {
            $state = Cache::get($key);
            if (!$state || !in_array($state['status'], ['queued', 'running'], true)) return;
            Cache::put($key.':cancel', true, now()->addHour());
            if ($state['status'] === 'queued') $this->storeFinished($key, $state, 'cancelled', null, 'Пробный запуск отменён до начала обработки.');
        });
    }

    public function execute(int $userId, string $id): void
    {
        $key = $this->key($userId, $id);
        // Claim survives process death. A duplicate delivery must never repeat writes.
        if (!Cache::add($key.':claimed', true, now()->addHour())) return;
        $previousUser = Auth::user();
        try {
            $state = $this->status($userId, $id);
            if (($state['status'] ?? null) !== 'queued') return;
            $payload = Cache::get($key.':payload');
            if (!$payload) throw new RuntimeException('Данные пробного запуска истекли.');
            $user = User::query()->find($userId);
            if (!$user || !$user->active) throw new RuntimeException('Аккаунт недоступен для запуска.');
            Auth::guard()->setUser($user);
            if ($payload['workflowId'] && !Workflow::query()->whereKey($payload['workflowId'])->where('user_id', $userId)->exists()) {
                throw new RuntimeException('Поток недоступен для этого аккаунта.');
            }
            $started = Cache::lock($key.':state-lock', 10)->block(2, function () use ($key): bool {
                $state = Cache::get($key);
                // Cancellation can arrive while loading the actor/graph.
                if (($state['status'] ?? null) !== 'queued' || Cache::get($key.':cancel')) return false;
                $state['status'] = 'running';
                $state['started_at'] = now()->timestamp;
                Cache::put($key, $state, now()->addHour());
                return true;
            });
            if (!$started) return;
            $session = app(WorkflowDebugger::class)->executeNode($payload['step'], $payload['context'], $payload['input'],
                $payload['workflowId'], $userId, true, $payload['definition'], function (array $step, int $sequence, array $iteration) use ($key): void {
                    Cache::lock($key.':state-lock', 10)->block(2, function () use ($key, $step, $sequence, $iteration): void {
                        $state = Cache::get($key);
                        if (Cache::get($key.':cancel') || ($state['status'] ?? null) !== 'running') {
                            throw new RuntimeException('Пробный запуск остановлен. Уже выполненные действия не отменены.');
                        }
                        $state['current_node'] = $step['name'] ?? $step['id'];
                        $state['sequence'] = $sequence;
                        $state['iteration'] = $iteration;
                        Cache::put($key, $state, now()->addHour());
                    });
                });
            $cancelled = Cache::get($key.':cancel', false);
            $this->finish($userId, $id, $cancelled ? 'cancelled' : $session['status'], $session,
                $cancelled ? 'Пробный запуск остановлен. Уже выполненные действия не отменены.' : ($session['error'] ?? null));
        } catch (Throwable $error) {
            $this->fail($userId, $id, $error->getMessage());
        } finally {
            if ($previousUser) Auth::guard()->setUser($previousUser);
            else Auth::forgetGuards();
        }
    }

    public function result(int $userId, string $id): ?array
    {
        return Cache::get($this->key($userId, $id).':result');
    }

    public function acknowledge(int $userId, string $id): void
    {
        $state = Cache::get($this->key($userId, $id));
        if (!$state || in_array($state['status'], ['queued', 'running'], true)) return;
        $editorKey = $state['editor_key'];
        Cache::lock($editorKey.':lock', 10)->get(function () use ($editorKey, $id): void {
            if (Cache::get($editorKey) === $id) Cache::forget($editorKey);
        });
    }

    public function fail(int $userId, string $id, string $error, ?string $expectedStatus = null): void
    {
        $key = $this->key($userId, $id);
        Cache::lock($key.':state-lock', 10)->block(2, function () use ($key, $error, $expectedStatus): void {
            $state = Cache::get($key);
            if (!$state || !in_array($state['status'], ['queued', 'running'], true)) return;
            if ($expectedStatus && $state['status'] !== $expectedStatus) return;
            Cache::put($key.':cancel', true, now()->addHour());
            $this->storeFinished($key, $state, 'failed', null, $error);
        });
    }

    private function finish(int $userId, string $id, string $status, ?array $session, ?string $error): void
    {
        $key = $this->key($userId, $id);
        Cache::lock($key.':state-lock', 10)->block(2, function () use ($key, $status, $session, $error): void {
            $state = Cache::get($key);
            if (!$state || !in_array($state['status'], ['queued', 'running'], true)) return;
            $this->storeFinished($key, $state, $status, $session, $error);
        });
    }

    private function storeFinished(string $key, array $state, string $status, ?array $session, ?string $error): void
    {
        if ($session) Cache::put($key.':result', $session, now()->addHour());
        $state['status'] = $status;
        $state['error'] = $error;
        Cache::put($key, $state, now()->addHour());
        Cache::forget($key.':payload');
    }
}
