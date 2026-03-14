<?php

namespace Ges\LaravelGreenApi\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GreenApiWebhookReceived
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public array $payload,
        public array $summary
    ) {}
}
