<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class HookController extends Controller
{
    //clever
    public function companies()
    {
        Artisan::call('app:companies-sync');
    }
}
