<?php

use App\Http\Controllers\Api\ActiveLeadController;
use App\Http\Controllers\Api\AlfaCRMController;
use App\Http\Controllers\Api\AssistantAnalyticsController;
use App\Http\Controllers\Api\AssistantLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BizonController;
use App\Http\Controllers\Api\CallTranscriptionController;
use App\Http\Controllers\Api\DadataController;
use App\Http\Controllers\Api\DistributionController;
use App\Http\Controllers\Api\DocsController;
use App\Http\Controllers\Api\GetCourseController;
use App\Http\Controllers\Api\TildaController;
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

    Route::post('amocrm/call-transcription/{user:uuid}/{setting}', [CallTranscriptionController::class, 'hook'])
        ->middleware(['integration.active:call-transcription'])
        ->name('amocrm.call-transcription');


    //AI

    Route::group([
        'prefix' => 'assistant/{user:uuid}',
//        'middleware' => ['integration.active:assistant', 'assistant.auth'],
    ], function () {
        Route::get('department-summary', [AssistantAnalyticsController::class, 'departmentSummary'])
            ->name('assistant.department-summary');

        Route::get('manager-summary', [AssistantAnalyticsController::class, 'managerSummary'])
            ->name('assistant.manager-summary');

        Route::get('risky-deals', [AssistantAnalyticsController::class, 'riskyDeals'])
            ->name('assistant.risky-deals');

        Route::get('deal-context/{deal}', [AssistantAnalyticsController::class, 'dealContext'])
            ->name('assistant.deal-context');

        Route::get('unprocessed-leads', [AssistantAnalyticsController::class, 'unprocessedLeads'])
            ->name('assistant.unprocessed-leads');

        Route::get('overdue-tasks', [AssistantAnalyticsController::class, 'overdueTasks'])
            ->name('assistant.overdue-tasks');

        Route::get('deals-without-next-task', [AssistantAnalyticsController::class, 'dealsWithoutNextTask'])
            ->name('assistant.deals-without-next-task');

        Route::get('conversion-delta', [AssistantAnalyticsController::class, 'conversionDelta'])
            ->name('assistant.conversion-delta');

        Route::get('daily-summary', [AssistantAnalyticsController::class, 'dailySummary'])
            ->name('assistant.daily-summary');

        Route::get('weekly-summary', [AssistantAnalyticsController::class, 'weeklySummary'])
            ->name('assistant.weekly-summary');

        Route::post('logs', [AssistantLogController::class, 'store'])
            ->name('assistant.logs.store');
    });
});

//amoCRM

Route::group(['prefix' => 'amocrm'], function () {

    //TODO тут проверить что работает а что не юзается
//    Route::post('secrets', [AuthController::class, 'secrets']);

    Route::get('redirect', [AuthController::class, 'redirect']);

    //хук с фронта об установке, не приходит, че за установка?
    Route::post('install', [AuthController::class, 'install']);

    Route::get('edtechindustry/redirect', [AuthController::class, 'redirect']);

    Route::post('edtechindustry/form', [AuthController::class, 'form']);

    //переход по кнопке с виджета в амо
    Route::get('widget', [AuthController::class, 'widget']);
});

//Route::get('docs/yandex/redirect', [DocsController::class, 'redirect'])->name('doc.redirect');
