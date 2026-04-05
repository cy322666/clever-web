<?php

use App\Http\Controllers\System\HealthController;
use App\Http\Controllers\System\IntegrationOpenController;
use App\Http\Controllers\System\MetricsController;
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
    $uri = (string)$request->query('uri', '');

    if ($uri !== '' && str_starts_with($uri, '/') && !str_starts_with($uri, '//')) {
        return redirect($uri);
    }

    return redirect(route('filament.app.pages.dashboard'));
});

Route::get('/clever/bayers/forms/pay', \App\Livewire\Clever\Bayers\FormOrder::class);

Route::get('/up', HealthController::class)
    ->name('up')
    ->middleware('auth');
Route::get('/metrics', MetricsController::class)->name('metrics');
Route::get('/panel/integrations/open/{app}', IntegrationOpenController::class)
    ->name('integrations.open')
    ->middleware('auth');

Route::get('/auto-login/{user}', function (Request $request, User $user) {

    Auth::login($user);
    $request->session()->regenerate();

    return redirect($request->redirect ?? env('APP_URL'));

})->name('auto.login')->middleware('signed');
