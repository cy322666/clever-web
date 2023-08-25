<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BizonController;
use App\Http\Controllers\Api\GetCourseController;
use App\Http\Middleware\CheckActiveUser;
use Illuminate\Support\Facades\Route;

Route::post('bizon/hook/{user:uuid}', [BizonController::class, 'hook'])
    ->middleware(CheckActiveUser::class)
    ->name('bizon.hook');

Route::group(['prefix' => 'getcourse'], function () {

    Route::get('pays/{user:uuid}', [GetCourseController::class, 'pay']);

    Route::get('orders/{user:uuid}', [GetCourseController::class, 'order']);

    Route::get('forms/{user:uuid}', [GetCourseController::class, 'form']);

})->middleware(CheckActiveUser::class);

Route::group(['prefix' => 'amocrm'], function () {

    Route::post('secrets', [AuthController::class, 'secrets']);

    Route::get('redirect', [AuthController::class, 'redirect']);
});
