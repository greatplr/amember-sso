<?php

use Greatplr\AmemberSso\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('amember-sso.webhook.route_prefix', 'amember/webhook'))
    ->name('amember.')
    ->group(function () {
        Route::post('/', [WebhookController::class, 'handle'])
            ->name('webhook.handle');
    });
