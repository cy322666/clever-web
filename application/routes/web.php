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

Route::get('/widgets', function () {
    $widgets = [
        [
            'name' => 'amoCRM OAuth Button',
            'slug' => 'amocrm-oauth-button',
            'summary' => 'Виджет для быстрого подключения аккаунта amoCRM через OAuth.',
            'category' => 'CRM интеграции',
            'repo_path' => 'app/Filament/Widgets/amoCRMButton.php',
            'view_path' => 'resources/views/filament/app/widgets/amocrm-button.blade.php',
            'features' => [
                'Готовая кнопка авторизации amoCRM.',
                'Настраиваемые данные приложения через config/services.php.',
                'Подходит для встроенных панелей Filament.',
            ],
        ],
    ];

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => 'Каталог виджетов',
        'itemListElement' => collect($widgets)
            ->values()
            ->map(fn ($widget, $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $widget['name'],
                'url' => url()->current() . '#' . $widget['slug'],
            ])
            ->all(),
    ];

    return view('public.widgets.index', [
        'widgets' => $widgets,
        'updatedAt' => now()->toDateString(),
        'schema' => $schema,
    ]);
})->name('public.widgets.index');

Route::get('/auto-login/{user}', function (Request $request, User $user) {

    Auth::login($user);

    return redirect($request->redirect ?? env('APP_URL'));

})->name('auto.login')->middleware('signed');
