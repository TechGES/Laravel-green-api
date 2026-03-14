<?php

use Ges\LaravelGreenApi\Http\Controllers\GreenApiWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/green-api/webhook', GreenApiWebhookController::class)
    ->name('green-api.webhook');
