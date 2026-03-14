<?php

namespace Ges\LaravelGreenApi\Commands;

use Ges\LaravelGreenApi\Services\GreenApiInboxService;
use Ges\LaravelGreenApi\Services\GreenApiService;
use Illuminate\Console\Command;
use RuntimeException;

class SyncGreenApiWebhookCommand extends Command
{
    protected $signature = 'green-api:sync-webhook {--url=} {--authorization-header=}';

    protected $description = 'Configure Green API webhook settings from the package configuration.';

    public function handle(GreenApiService $greenApiService, GreenApiInboxService $greenApiInboxService): int
    {
        try {
            $response = $greenApiService->configureWebhook(
                $this->option('url') ?: null,
                $this->option('authorization-header') ?: null
            );

            $greenApiInboxService->markWebhookSynced();

            $this->components->info(trans('green-api::messages.webhook_synced'));
            $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
