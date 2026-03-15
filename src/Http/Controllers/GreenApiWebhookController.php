<?php

namespace Ges\LaravelGreenApi\Http\Controllers;

use Ges\LaravelGreenApi\Events\GreenApiWebhookReceived;
use Ges\LaravelGreenApi\Http\Requests\GreenApiWebhookRequest;
use Ges\LaravelGreenApi\Services\GreenApiInboxService;
use Ges\LaravelGreenApi\Services\GreenApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class GreenApiWebhookController
{
    public function __construct(
        protected GreenApiService $greenApiService,
        protected GreenApiInboxService $greenApiInboxService
    ) {}

    public function __invoke(GreenApiWebhookRequest $request): JsonResponse
    {
        if (! $this->greenApiService->hasValidWebhookAuthorizationHeader($request->header('Authorization'))) {
            return response()->json(['message' => trans('green-api::messages.http.unauthorized')], 401);
        }

        $request->validated();
        $payload = $request->all();
        $summary = $this->greenApiService->summarizeWebhook($payload);
        $this->greenApiInboxService->ingestWebhook($payload);

        GreenApiWebhookReceived::dispatch($payload, $summary);

        Log::info('Green API webhook received', array_filter([
            ...$summary,
            'idMessage' => is_string($payload['idMessage'] ?? null) ? $payload['idMessage'] : null,
            'chatId' => is_string($payload['chatId'] ?? null) ? $payload['chatId'] : null,
            'status' => is_string($payload['status'] ?? null) ? $payload['status'] : null,
            'statusMessage' => is_string($payload['statusMessage'] ?? null) ? $payload['statusMessage'] : null,
        ], fn (mixed $value): bool => $value !== null));

        return response()->json([
            'message' => trans('green-api::messages.http.webhook_received'),
            'summary' => $summary,
        ]);
    }
}
