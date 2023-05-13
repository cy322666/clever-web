<?php

use App\Http\Controllers\Api\BizonController;
use App\Http\Controllers\Api\GetCourseController;
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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('bizon/hook/{user:uuid}', [BizonController::class, 'hook']);

Route::group(['prefix' => 'getcourse'], function () {

    Route::post('pays/{user:uuid}', [GetCourseController::class, 'pay']);

    Route::post('orders/{user:uuid}', [GetCourseController::class, 'order']);

    Route::post('registrations/{user:uuid}', [GetCourseController::class, 'registration']);
});


