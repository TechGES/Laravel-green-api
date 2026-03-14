<?php

namespace Ges\LaravelGreenApi\Tests\Feature;

use Ges\LaravelGreenApi\Models\GreenApiConfig;
use Ges\LaravelGreenApi\Models\GreenApiConversation;
use Ges\LaravelGreenApi\Models\GreenApiMessage;
use Ges\LaravelGreenApi\Services\GreenApiInboxService;
use Ges\LaravelGreenApi\Tests\Fixtures\User;
use Ges\LaravelGreenApi\Tests\TestCase;

class GreenApiInboxServiceTest extends TestCase
{
    public function test_unsupported_webhook_updates_config_without_creating_message(): void
    {
        $service = $this->app->make(GreenApiInboxService::class);

        $message = $service->ingestWebhook([
            'typeWebhook' => 'somethingUnexpected',
            'timestamp' => time(),
            'instanceData' => [
                'idInstance' => '123',
            ],
            'senderData' => [
                'chatId' => '33612345678@c.us',
            ],
        ]);

        $this->assertNull($message);
        $this->assertSame(0, GreenApiConversation::query()->count());
        $this->assertSame(0, GreenApiMessage::query()->count());
        $this->assertSame('somethingUnexpected', GreenApiConfig::query()->first()->last_webhook_type);
    }

    public function test_incoming_webhook_creates_single_conversation_per_contact(): void
    {
        $user = User::query()->create([
            'name' => 'Jane Doe',
            'phone' => '+33 6 12 34 56 78',
        ]);

        $service = $this->app->make(GreenApiInboxService::class);

        $payload = [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => time(),
            'idMessage' => 'msg-1',
            'instanceData' => [
                'idInstance' => '123',
            ],
            'senderData' => [
                'chatId' => '33612345678@c.us',
                'sender' => '33612345678@c.us',
            ],
            'messageData' => [
                'typeMessage' => 'textMessage',
                'textMessageData' => [
                    'textMessage' => 'Hello there',
                ],
            ],
        ];

        $message = $service->ingestWebhook($payload);

        $this->assertNotNull($message);
        $this->assertSame(1, GreenApiConversation::query()->count());
        $this->assertSame((string) $user->getKey(), GreenApiConversation::query()->first()->contact_id);
        $this->assertSame(1, GreenApiConversation::query()->first()->unread_count);
    }
}
