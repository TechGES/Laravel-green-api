<?php

namespace Ges\LaravelGreenApi\Commands;

use Ges\LaravelGreenApi\Services\GreenApiInboxService;
use Ges\LaravelGreenApi\Services\GreenApiService;
use Illuminate\Console\Command;
use RuntimeException;

class CheckGreenApiConnectionCommand extends Command
{
    protected $signature = 'green-api:check-connection';

    protected $description = 'Check Green API instance connectivity and persist the latest state.';

    public function handle(GreenApiService $greenApiService, GreenApiInboxService $greenApiInboxService): int
    {
        try {
            $response = $greenApiService->getStateInstance();
            $state = is_string($response['stateInstance'] ?? null) ? $response['stateInstance'] : null;

            $greenApiInboxService->markConnectionChecked($state);

            $this->components->info(trans('green-api::messages.connection_checked', [
                'state' => $state ?? trans('green-api::messages.unknown'),
            ]));

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
