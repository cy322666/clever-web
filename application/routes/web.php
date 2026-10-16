<?php

use App\Http\Controllers\System\IntegrationOpenController;
use App\Http\Controllers\System\MetricsController;
//use App\Http\Controllers\WorkflowDocumentationController;
use App\Http\Controllers\YClientsMarketplaceController;
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

Route::get('/auto-login/{user}', function (Request $request, User $user) {

    Auth::login($user);
    $request->session()->regenerate();

    return redirect($request->redirect ?? env('APP_URL'));

})->name('auto.login')->middleware('signed');

//че за общий инсталл?
Route::get('/amocrm/installation/{token}', \App\Http\Controllers\System\AmoCrmInstallationController::class)
    ->whereUuid('token')->middleware('throttle:60,1')->name('amocrm.installation.status');

//Установки виджетов

Route::get('/amocrm/finder/installation/{token}', \App\Http\Controllers\System\AmoCrmInstallationController::class)
    ->whereUuid('token')->middleware('throttle:60,1')->name('finder.installation.status');
Route::get('/amocrm/workflows/installation/{token}', \App\Http\Controllers\System\AmoCrmInstallationController::class)
    ->whereUuid('token')->middleware('throttle:60,1')->name('workflows.installation.status');

Route::get('/yclients/marketplace/register', [YClientsMarketplaceController::class, 'register'])
    ->middleware('throttle:30,1')
    ->name('yclients.marketplace.register');

//Установки виджетов


//это че?
Route::get('/panel/integrations/open/{app}', IntegrationOpenController::class)
    ->name('integrations.open')
    ->middleware('auth');

Route::get('/up', fn() => response('OK', 200))
    ->name('up');

// Browser editor: graph/form changes stay local; only explicit save/runtime requests cross this boundary.
Route::middleware(['auth', 'throttle:120,1'])->prefix('workflow-editor')->group(function (): void {
    Route::post('/document', [\App\Http\Controllers\WorkflowBrowserController::class, 'save']);
    Route::put('/{workflow}/document', [\App\Http\Controllers\WorkflowBrowserController::class, 'save'])->whereNumber('workflow');
    Route::delete('/{workflow}/document', [\App\Http\Controllers\WorkflowBrowserController::class, 'delete'])->whereNumber('workflow');
    Route::post('/{workflow}/duplicate', [\App\Http\Controllers\WorkflowBrowserController::class, 'duplicate'])->whereNumber('workflow');
    Route::post('/{workflow}/references', [\App\Http\Controllers\WorkflowBrowserController::class, 'references'])->whereNumber('workflow');
    Route::post('/{workflow}/debug', [\App\Http\Controllers\WorkflowBrowserController::class, 'debug'])->whereNumber('workflow');
});

//архив? не смог зайти
Route::get('/metrics', MetricsController::class)->name('metrics');

//это че за форма?
Route::get('/clever/bayers/forms/pay', \App\Livewire\Clever\Bayers\FormOrder::class);

//че за документация? сносим?
//Route::middleware(
//    'auth')->group(function (): void {
//    Route::get('/workflow-builder/documentation.pdf', [WorkflowDocumentationController::class, 'account'])
//        ->name('workflow-builder.documentation.account');
//    Route::get('/workflow-builder/workflows/{workflow}/documentation.pdf', [WorkflowDocumentationController::class, 'workflow'])
//        ->name('workflow-builder.documentation.workflow');
//});
