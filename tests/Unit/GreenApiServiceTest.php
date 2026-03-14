<?php

namespace Ges\LaravelGreenApi\Tests\Unit;

use Ges\LaravelGreenApi\Services\GreenApiService;
use Ges\LaravelGreenApi\Tests\TestCase;

class GreenApiServiceTest extends TestCase
{
    public function test_normalize_chat_id_strips_formatting(): void
    {
        $service = $this->app->make(GreenApiService::class);

        $this->assertSame('33612345678@c.us', $service->normalizeChatId('+33 6 12 34 56 78'));
    }

    public function test_summarize_webhook_marks_supported_types(): void
    {
        $service = $this->app->make(GreenApiService::class);

        $summary = $service->summarizeWebhook([
            'typeWebhook' => 'incomingMessageReceived',
            'instanceData' => ['idInstance' => '123'],
            'messageData' => ['typeMessage' => 'imageMessage'],
        ]);

        $this->assertTrue($summary['supported']);
        $this->assertSame('incoming_file', $summary['category']);
    }
}
