<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Ogp\UiApi\Http\Controllers\GenericApiController;
use Ogp\UiApi\Services\ComponentConfigService;

Route::prefix(config('uiapi.route_prefix', 'api'))
    ->group(function () {
        // Component Config Service endpoint
        Route::get('ccs/{model}', function (Request $request, string $model) {
            return app(ComponentConfigService::class)->index($request, $model);
        });

        // Generic API endpoints
        Route::get('gapi/{model}', [GenericApiController::class, 'index']);
        Route::get('gapi/{model}/{id}', [GenericApiController::class, 'show']);
        Route::post('gapi/{model}', [GenericApiController::class, 'store']);
        Route::put('gapi/{model}/{id}', [GenericApiController::class, 'update']);
        Route::delete('gapi/{model}/{id}', [GenericApiController::class, 'destroy']);
    });
