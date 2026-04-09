<?php

namespace App\Observers;

use App\Jobs\Core\InstallUserIntegrations;
use App\Mail\SignUp;
use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
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

        /* создание моделей интеграции */
        InstallUserIntegrations::dispatch($user->id);
    }

    /**
     * Handle the User "updated" event.
     *
     * @param User $user
     * @return void
     */
    public function updated(User $user): void
    {
        //
    }

    /**
     * Handle the User "deleted" event.
     *
     * @param User $user
     * @return void
     */
    public function deleted(User $user)
    {
        //
    }

    /**
     * Handle the User "restored" event.
     *
     * @param User $user
     * @return void
     */
    public function restored(User $user)
    {
        //
    }

    /**
     * Handle the User "force deleted" event.
     *
     * @param User $user
     * @return void
     */
    public function forceDeleted(User $user)
    {
    }
}
