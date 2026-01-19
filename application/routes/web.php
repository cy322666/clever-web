<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

 Route::get('/', function (Request $request) {

     if ($request->uri)
         return redirect($request->uri);
     else
        return redirect(\route('filament.app.pages.dashboard'));
 });

Route::get('/clever/bayers/forms/pay', \App\Livewire\Clever\Bayers\FormOrder::class);

Route::get('/auto-login/{user}', function (Request $request, User $user) {

    Auth::login($user);

    return redirect($request->redirect ?? env('APP_URL'));

})->name('auto.login')->middleware('signed');

