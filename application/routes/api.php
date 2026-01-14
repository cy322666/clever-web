<?php

use App\Http\Controllers\Api\ActiveLeadController;
use App\Http\Controllers\Api\AlfaCRMController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BizonController;
use App\Http\Controllers\Api\DadataController;
use App\Http\Controllers\Api\DistributionController;
use App\Http\Controllers\Api\DocsController;
use App\Http\Controllers\Api\GetCourseController;
use App\Http\Controllers\Api\TildaController;
use App\Http\Controllers\Api\YClientsController;
use Illuminate\Support\Facades\Route;

//TODO мидлвару на активность юзера и интеграции сделать

Route::group(['middleware' => ['user.active', 'user.inputs']], function () {

    Route::group(['prefix' => 'bizon', 'middleware' => ['integration.active:bizon']], function () {

        Route::post('hook/{user:uuid}', [BizonController::class, 'hook'])->middleware(['bizon'])->name('bizon.hook');

        Route::post('form/{user:uuid}', [BizonController::class, 'form'])->middleware(['bizon'])->name('bizon.form');
    });

    Route::group(['prefix' => 'getcourse', 'middleware' => ['integration.active:getcourse']], function () {

        Route::get('orders/{user:uuid}/{template}', [GetCourseController::class, 'order'])->name('getcourse.order');

        Route::get('forms/{user:uuid}/{form}', [GetCourseController::class, 'form'])->name('getcourse.form');
    });

    Route::group(['prefix' => 'tilda', 'middleware' => ['integration.active:tilda']], function () {

        Route::post('hook/{user:uuid}/{site}', [TildaController::class, 'hook'])->name('tilda.hook');
    });

    Route::group(['prefix' => 'distribution', 'middleware' => ['integration.active:distribution']], function () {

        Route::post('hook/{user:uuid}/{template}', [DistributionController::class, 'hook'])->name('distribution.hook');
    });

    Route::group(['prefix' => 'alfacrm', 'middleware' => ['integration.active:alfacrm']], function () {

        Route::post('record/{user:uuid}', [AlfaCRMController::class, 'record'])->name('alfacrm.record');

        Route::post('came/{user:uuid}', [AlfaCRMController::class, 'came'])->name('alfacrm.came');

        Route::post('omission/{user:uuid}', [AlfaCRMController::class, 'omission'])->name('alfacrm.omission');
    });

    Route::post('active-leads/{user:uuid}', [ActiveLeadController::class, 'hook'])
        ->middleware(['integration.active:active-lead'])
        ->name('active-leads.hook');

    Route::post('data/{user:uuid}', [DadataController::class, 'hook'])
        ->middleware(['integration.active:data-info'])
        ->name('data.hook');

    Route::post('docs/{user:uuid}/{doc}', [DocsController::class, 'hook'])
        ->middleware(['integration.active:docs'])
        ->name('doc.hook');

    Route::post('yclients/hook/{user:uuid}', [YClientsController::class, 'hook'])
        ->middleware(['integration.active:yclients'])
        ->name('yclients.hook');
});

//amoCRM

Route::group(['prefix' => 'amocrm'], function () {

    Route::post('secrets', [AuthController::class, 'secrets']);

    Route::get('redirect', [AuthController::class, 'redirect']);

    //хук с фронта об установке
    Route::post('install', [AuthController::class, 'install']);

    Route::get('edtechindustry/redirect', [AuthController::class, 'redirect']);

    Route::post('edtechindustry/form', [AuthController::class, 'form']);
});

//Route::get('docs/yandex/redirect', [DocsController::class, 'redirect'])->name('doc.redirect');


