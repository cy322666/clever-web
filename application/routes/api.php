<?php

use App\Http\Controllers\Api\ActiveLeadController;
use App\Http\Controllers\Api\AlfaCRMController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BizonController;
use App\Http\Controllers\Api\DadataController;
use App\Http\Controllers\Api\DocsController;
use App\Http\Controllers\Api\GetCourseController;
use App\Http\Controllers\Api\TildaController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['user.active', 'input']], function () {

    Route::group(['prefix' => 'bizon'], function () {

        Route::post('hook/{user:uuid}', [BizonController::class, 'hook'])
            ->middleware(['bizon'])
            ->name('bizon.hook');

        Route::post('form/{user:uuid}', [BizonController::class, 'form'])
            ->middleware(['bizon'])
            ->name('bizon.form');
    });

    Route::group(['prefix' => 'getcourse'], function () {

        Route::get('pays/{user:uuid}', [GetCourseController::class, 'pay'])->name('getcourse.pay');

        Route::get('orders/{user:uuid}', [GetCourseController::class, 'order'])->name('getcourse.order');

        Route::get('forms/{user:uuid}', [GetCourseController::class, 'form'])->name('getcourse.form');

    });

    Route::group(['prefix' => 'tilda'], function () {

        Route::post('hook/{user:uuid}/{site}', [TildaController::class, 'hook'])->name('tilda.hook');
    });

    Route::group(['prefix' => 'alfacrm'], function () {

        Route::post('record/{user:uuid}', [AlfaCRMController::class, 'record'])->name('alfacrm.record');

        Route::post('came/{user:uuid}', [AlfaCRMController::class, 'came'])->name('alfacrm.came');

        Route::post('omission/{user:uuid}', [AlfaCRMController::class, 'omission'])->name('alfacrm.omission');
    });

    Route::post('active-leads/{user:uuid}', [ActiveLeadController::class, 'hook'])->name('active-leads.hook');

    Route::post('data/{user:uuid}', [DadataController::class, 'hook'])->name('data.hook');

    Route::post('docs/{user:uuid}/{doc}', [DocsController::class, 'hook'])->name('doc.hook');

});

Route::group(['prefix' => 'amocrm'], function () {

    Route::post('secrets', [AuthController::class, 'secrets']);

    Route::get('redirect', [AuthController::class, 'redirect']);
});

Route::get('docs/yandex/redirect', [DocsController::class, 'redirect'])->name('doc.redirect');

