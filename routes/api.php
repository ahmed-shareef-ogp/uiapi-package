<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Ogp\UiApi\Http\Controllers\GenericApiController;
use Ogp\UiApi\Services\ComponentConfigService;

Route::prefix(config('uiapi.route_prefix', 'api'))
    ->group(function () {
        // Component Config Service endpoint - query parameter format
        Route::get('ccs/{model}', function (Request $request, string $model) {
            return app(ComponentConfigService::class)->index($request, $model);
        });

        // Component Config Service endpoint - path parameter format
        Route::get('ccs/{model}/{component}', function (Request $request, string $model, string $component) {
            // Inject component as query parameter (keep original case for flexibility)
            $request->merge(['component' => $component]);
            return app(ComponentConfigService::class)->index($request, $model);
        })->where('component', '.*');

        // Generic API endpoints
        Route::get('gapi/{model}', [GenericApiController::class, 'index']);
        Route::get('gapi/{model}/{id}', [GenericApiController::class, 'show']);
        Route::post('gapi/{model}', [GenericApiController::class, 'store']);
        Route::put('gapi/{model}/{id}', [GenericApiController::class, 'update']);
        Route::delete('gapi/{model}/{id}', [GenericApiController::class, 'destroy']);
    });
