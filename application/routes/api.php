<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BizonController;
use App\Http\Controllers\Api\GetCourseController;
use App\Http\Controllers\Api\TildaController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['user.active', 'input']], function () {

    Route::post('bizon/hook/{user:uuid}', [BizonController::class, 'hook'])
        ->middleware(['bizon'])
        ->name('bizon.hook');

    Route::group(['prefix' => 'getcourse'], function () {

        Route::get('pays/{user:uuid}', [GetCourseController::class, 'pay']);

        Route::get('orders/{user:uuid}', [GetCourseController::class, 'order']);

        Route::get('forms/{user:uuid}', [GetCourseController::class, 'form']);

    })->middleware('user.active');
});

Route::group(['prefix' => 'tilda'], function () {

    Route::post('hook/{user:uuid}/{site}', [TildaController::class, 'hook'])->name('tilda.hook');

})->middleware('user.active');


Route::group(['prefix' => 'amocrm'], function () {

    Route::post('secrets', [AuthController::class, 'secrets']);

    Route::get('redirect', [AuthController::class, 'redirect']);
});


