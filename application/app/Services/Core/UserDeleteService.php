<?php

namespace App\Services\Core;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class UserDeleteService
{
    public function delete(User $user, ?User $actor = null): array
    {
        if ((bool)$user->is_root) {
            throw new DomainException('Нельзя удалить root-пользователя');
        }

        if ($actor && (int)$actor->id === (int)$user->id) {
            throw new DomainException('Нельзя удалить текущего пользователя');
        }

        $userId = (int)$user->id;
        $userUuid = (string)($user->uuid ?? '');
        $accountIds = $user->accounts()->pluck('id')->map(fn($id) => (int)$id)->all();

        return DB::transaction(function () use ($user, $userId, $userUuid, $accountIds): array {
            $stats = [];

            $authLogTable = (string)config('authentication-log.table_name', 'authentication_log');
            $stats['authentication_log'] = $this->deleteRows($authLogTable, [
                'authenticatable_type' => User::class,
                'authenticatable_id' => $userId,
            ]);

            $stats['notifications'] = $this->deleteRows('notifications', [
                'notifiable_type' => User::class,
                'notifiable_id' => $userId,
            ]);

            $stats['personal_access_tokens'] = $this->deleteRows('personal_access_tokens', [
                'tokenable_type' => User::class,
                'tokenable_id' => $userId,
            ]);

            if ($userUuid !== '') {
                $stats['ai_threads'] = $this->deleteRows('ai_threads', [
                    'user_uuid' => $userUuid,
                ]);
            } else {
                $stats['ai_threads'] = 0;
            }

            $stats['feedback'] = $this->deleteByUserIdVariants('feedback', $userId);
            $stats['call_transactions'] = $this->deleteCallTransactions($userId, $accountIds);
            $stats['trigger_events'] = $this->deleteRows('trigger_events', [
                'user_id' => $userId,
            ]);

            if (!$user->delete()) {
                throw new RuntimeException('Не удалось удалить пользователя');
            }

            $stats['users'] = 1;

            return $stats;
        });
    }

    private function deleteRows(string $table, array $where): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);

        foreach ($where as $column => $value) {
            if (!Schema::hasColumn($table, (string)$column)) {
                return 0;
            }

            $query->where((string)$column, $value);
        }

        return $query->delete();
    }

    private function deleteByUserIdVariants(string $table, int $userId): int
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'user_id')) {
            return 0;
        }

        return DB::table($table)
            ->where('user_id', $userId)
            ->orWhere('user_id', (string)$userId)
            ->delete();
    }

    private function deleteCallTransactions(int $userId, array $accountIds): int
    {
        if (!Schema::hasTable('call_transactions')) {
            return 0;
        }

        $query = DB::table('call_transactions');
        $hasUserId = Schema::hasColumn('call_transactions', 'user_id');
        $hasAccountId = Schema::hasColumn('call_transactions', 'account_id');

        if (!$hasUserId && !$hasAccountId) {
            return 0;
        }

        if ($hasUserId) {
            $query->where('user_id', $userId);
        }

        if ($hasAccountId && $accountIds !== []) {
            if ($hasUserId) {
                $query->orWhereIn('account_id', $accountIds);
            } else {
                $query->whereIn('account_id', $accountIds);
            }
        }

        return $query->delete();
    }
}
