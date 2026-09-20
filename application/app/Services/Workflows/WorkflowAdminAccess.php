<?php

namespace App\Services\Workflows;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Leek\FilamentWorkflows\Models\Workflow;

final class WorkflowAdminAccess
{
    public static function isRoot(): bool
    {
        return Auth::check() && (bool) Auth::user()?->is_root;
    }

    public static function tenantId(): ?int
    {
        if (! Auth::id() || self::isRoot()) {
            return null;
        }

        return (int) Auth::id();
    }

    public static function useWorkflowOwner(Workflow $workflow): User
    {
        abort_unless(self::isRoot(), 403);

        $owner = User::query()->findOrFail((int) $workflow->user_id);

        // Request-only identity switch. The root session remains unchanged.
        Auth::guard()->setUser($owner);

        return $owner;
    }
}
