<?php

namespace App\Observers;

use App\Jobs\Sqns\DisconnectWebhook;
use App\Mail\SignUp;
use App\Models\Core\Account;
use App\Models\Integrations\Sqns\Setting as SqnsSetting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

class UserObserver
{
    public bool $afterCommit = true;

    public function created(User $user): void
    {
        $user->uuid = Uuid::uuid4();
        $user->save();

        $user->account()->create([
            'widget' => Account::DEFAULT_WIDGET,
        ]);

        Mail::to($user->email)->queue(new SignUp($user, null));
    }

    public function updated(User $user): void
    {
        if (! $user->wasChanged('active') || $user->active || ! Schema::hasTable('sqns_settings')) {
            return;
        }

        SqnsSetting::query()
            ->where('user_id', $user->id)
            ->whereNotNull('token')
            ->pluck('id')
            ->each(fn (int $settingId) => DisconnectWebhook::dispatch($settingId, 'user_inactive'));
    }
}
