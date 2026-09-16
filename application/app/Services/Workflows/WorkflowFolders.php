<?php

namespace App\Services\Workflows;

use App\Models\Workflows\Workflow;
use App\Models\Workflows\WorkflowFolder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Leek\FilamentWorkflows\Models\Workflow as BaseWorkflow;

final class WorkflowFolders
{
    public const WITHOUT_FOLDER = '__without_group__';

    /** Include legacy groups without rewriting any saved workflows. */
    public static function options(): array
    {
        if (! Auth::id()) {
            return [];
        }

        $names = Workflow::query()->where('user_id', Auth::id())
            ->whereNotNull('group_name')->where('group_name', '<>', '')
            ->distinct()->pluck('group_name');

        if (Schema::hasTable('workflow_folders')) {
            $names = $names->merge(WorkflowFolder::query()->where('user_id', Auth::id())->pluck('name'));
        }

        return $names->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->mapWithKeys(fn (string $name): array => [$name => $name])->all();
    }

    public static function create(string $name): string
    {
        $owner = self::owner();
        self::requireStorage();
        $name = self::validateName($name);
        WorkflowFolder::query()->create(['user_id' => $owner, 'name' => $name]);

        return $name;
    }

    public static function rename(string $oldName, string $name): string
    {
        $owner = self::owner();
        self::requireStorage();
        self::assertFolderExists($oldName);
        $name = self::validateName($name, $oldName);

        DB::transaction(function () use ($owner, $oldName, $name): void {
            WorkflowFolder::query()->firstOrCreate(['user_id' => $owner, 'name' => $oldName])->update(['name' => $name]);
            Workflow::withTrashed()->where('user_id', $owner)->where('group_name', $oldName)->update(['group_name' => $name]);
        });

        return $name;
    }

    /** Removing a folder never removes its workflows, including soft-deleted ones. */
    public static function remove(string $name): void
    {
        $owner = self::owner();
        self::requireStorage();
        self::assertFolderExists($name);

        DB::transaction(function () use ($owner, $name): void {
            Workflow::withTrashed()->where('user_id', $owner)->where('group_name', $name)->update(['group_name' => null]);
            WorkflowFolder::query()->where('user_id', $owner)->where('name', $name)->delete();
        });
    }

    public static function move(BaseWorkflow $workflow, ?string $name): void
    {
        abort_unless((int) $workflow->user_id === self::owner(), 403);
        $name = filled($name) ? $name : null;

        if ($name !== null) {
            self::assertFolderExists($name);
        }

        // Organisation must not alter activation or run action/trigger save hooks.
        $workflow->forceFill(['group_name' => $name])->saveQuietly();
    }

    public static function assertFolderExists(string $name): void
    {
        if (! array_key_exists($name, self::options())) {
            throw ValidationException::withMessages(['group_name' => 'Папка не найдена. Обновите список.']);
        }
    }

    private static function owner(): int
    {
        abort_unless(Auth::id(), 403);

        return (int) Auth::id();
    }

    private static function requireStorage(): void
    {
        if (! Schema::hasTable('workflow_folders')) {
            throw ValidationException::withMessages(['name' => 'Сохранение папок станет доступно после обновления базы.']);
        }
    }

    private static function validateName(string $name, ?string $except = null): string
    {
        $name = trim($name);
        Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:100', Rule::notIn([self::WITHOUT_FOLDER])]])->validate();

        foreach (self::options() as $existing) {
            if ($existing !== $except && mb_strtolower($existing) === mb_strtolower($name)) {
                throw ValidationException::withMessages(['name' => 'Папка с таким названием уже есть.']);
            }
        }

        return $name;
    }
}
