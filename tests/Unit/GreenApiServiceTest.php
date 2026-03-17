<?php

namespace Ges\LaravelGreenApi\Tests\Unit;

use Ges\LaravelGreenApi\Services\GreenApiService;
use Ges\LaravelGreenApi\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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

    public function test_check_whatsapp_rejects_invalid_phone_number_length(): void
    {
        $service = $this->app->make(GreenApiService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Green API phone number must contain between 11 and 16 digits.');

        $service->checkWhatsapp('12345');
    }

    public function test_check_whatsapp_throws_when_response_is_missing_boolean_flag(): void
    {
        Http::fake([
            'https://api.example.test/*' => Http::response([
                'foo' => 'bar',
            ]),
        ]);

        $service = $this->app->make(GreenApiService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Green API returned an invalid checkWhatsapp response.');

        $service->checkWhatsapp('+33 6 12 34 56 78');
    }
}
