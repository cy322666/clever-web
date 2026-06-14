<?php

use App\Http\Controllers\Api\AlfaCRMController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BizonController;
use App\Http\Controllers\Api\CallTranscriptionController;
use App\Http\Controllers\Api\DistributionController;
use App\Http\Controllers\Api\GetCourseController;
use App\Http\Controllers\Api\TildaController;
use App\Http\Controllers\Api\WorkflowManualAmoCrmController;
use App\Http\Controllers\Api\WorkflowWebhookController;
use App\Http\Controllers\Api\YClientsController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['user.active', 'user.inputs']], function () {

    Route::group(['prefix' => 'bizon', 'middleware' => ['integration.active:bizon']], function () {
        Route::post('hook/{user:uuid}', [BizonController::class, 'hook'])->name('bizon.hook');

        Route::post('form/{user:uuid}', [BizonController::class, 'form'])->name('bizon.form');
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

        Route::post('archive/{user:uuid}', [AlfaCRMController::class, 'archive'])->name('alfacrm.archive');

        Route::post('pay/{user:uuid}', [AlfaCRMController::class, 'pay'])->name('alfacrm.pay');

        Route::post('repeated/{user:uuid}', [AlfaCRMController::class, 'repeated'])->name('alfacrm.repeated');
    });

    Route::post('yclients/hook/{user:uuid}', [YClientsController::class, 'hook'])
        ->middleware(['integration.active:yclients'])
        ->name('yclients.hook');

    Route::post('amocrm/call-transcription/{user:uuid}/{setting}', [CallTranscriptionController::class, 'hook'])
        ->middleware(['integration.active:call-transcription'])
        ->name('amocrm.call-transcription');

});

//amoCRM

Route::group(['prefix' => 'amocrm'], function () {

    //TODO тут проверить что работает а что не юзается
//    Route::post('secrets', [AuthController::class, 'secrets']);

    Route::get('redirect', [AuthController::class, 'redirect']);
    Route::match(['get', 'post'], 'off', [AuthController::class, 'off'])
        ->name('amocrm.off');

    //хук с фронта об установке, не приходит, че за установка?
    Route::post('install', [AuthController::class, 'install']);

    Route::get('edtechindustry/redirect', [AuthController::class, 'redirect']);

    Route::post('edtechindustry/form', [AuthController::class, 'form']);

    //переход по кнопке с виджета в амо
    Route::get('widget', [AuthController::class, 'widget'])
        ->middleware('throttle:10,1');

    Route::post('workflows/hook/{account}/{signature}', [WorkflowWebhookController::class, 'amoCrm'])
        ->middleware('throttle:120,1')
        ->name('amocrm.workflows.hook');

    Route::match(['get', 'post'], 'workflows/manual-buttons', [WorkflowManualAmoCrmController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('amocrm.workflows.manual-buttons.index');

    Route::post('workflows/manual-buttons/run', [WorkflowManualAmoCrmController::class, 'run'])
        ->middleware('throttle:30,1')
        ->name('amocrm.workflows.manual-buttons.run');

    Route::post('workflows/manual-buttons/digital-pipeline', [WorkflowManualAmoCrmController::class, 'digitalPipeline'])
        ->middleware('throttle:120,1')
        ->name('amocrm.workflows.manual-buttons.digital-pipeline');
});

Route::match(['get', 'post', 'put', 'patch', 'delete'], 'workflows/webhook/{workflow}/{signature}', [WorkflowWebhookController::class, 'generic'])
    ->middleware('throttle:120,1')
    ->name('workflows.webhook');
