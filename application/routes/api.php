<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BizonController;
use App\Http\Controllers\Api\GetCourseController;
use App\Http\Middleware\CheckActiveUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('bizon/hook/{user:uuid}', [BizonController::class, 'hook'])->middleware(CheckActiveUser::class);;

Route::group(['prefix' => 'getcourse'], function () {

    Route::get('pays/{user:uuid}', [GetCourseController::class, 'pay']);

    Route::get('orders/{user:uuid}', [GetCourseController::class, 'order']);

    Route::get('forms/{user:uuid}', [GetCourseController::class, 'form']);

})->middleware(CheckActiveUser::class);

Route::group(['prefix' => 'amocrm'], function () {

    Route::post('secrets', [AuthController::class, 'secrets']);

    Route::get('redirect', [AuthController::class, 'redirect']);
});
