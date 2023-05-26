<?php

namespace App\Services\Core;

use App\Models\User;

class UserCreateService
{
    public function __construct(private User $user) {}

    public function setServices()
    {
        $this->user->account()->create();

        $this->user->bizon_settings()->create();

        $this->user->getcourse_settings()->create();

        $this->user->apps()->create(['name' => 'bizon']);
        $this->user->apps()->create(['name' => 'getcourse']);
    }

    public function dropServices()
    {
        $this->user->account()->delete();

        $this->user->bizon_settings()->delete();

        $this->user->getcourse_settings()->delete();
    }
}
