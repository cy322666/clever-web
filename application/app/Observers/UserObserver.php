<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Ramsey\Uuid\Uuid;

class UserObserver
{
    public bool $afterCommit = true;

    public function created(User $user): void
    {
        $user->uuid = Uuid::uuid4();
        $user->save();

        $user->account()->create();

        /* создание моделей интеграции */
        Artisan::call('install:alfa', ['user_id' => $user->id]);
        Artisan::call('install:bizon', ['user_id' => $user->id]);
        Artisan::call('install:getcourse', ['user_id' => $user->id]);
        Artisan::call('install:tilda', ['user_id' => $user->id]);
        Artisan::call('install:active-lead', ['user_id' => $user->id]);
        Artisan::call('install:data-info', ['user_id' => $user->id]);
        Artisan::call('install:doc', ['user_id' => $user->id]);
        Artisan::call('install:distribution', ['user_id' => $user->id]);
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
