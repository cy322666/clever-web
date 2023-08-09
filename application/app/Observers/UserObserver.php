<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Core\UserCreateService;
use Ramsey\Uuid\Uuid;

class UserObserver
{
    public bool $afterCommit = true;

    public function created(User $user)
    {
        (new UserCreateService($user))->setServices();

        $user->uuid = Uuid::uuid4();
        $user->save();
    }

    /**
     * Handle the User "updated" event.
     *
     * @param User $user
     * @return void
     */
    public function updated(User $user)
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
        (new UserCreateService($user))->dropServices();
    }
}
