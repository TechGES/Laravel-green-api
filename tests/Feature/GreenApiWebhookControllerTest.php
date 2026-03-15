<?php

namespace Ges\LaravelGreenApi\Tests\Feature;

use Ges\LaravelGreenApi\Models\GreenApiMessage;
use Ges\LaravelGreenApi\Services\GreenApiInboxService;
use Ges\LaravelGreenApi\Tests\Fixtures\User;
use Ges\LaravelGreenApi\Tests\TestCase;

class GreenApiWebhookControllerTest extends TestCase
{
    public function test_outgoing_webhook_sequence_updates_existing_message_without_duplicate_rows(): void
    {
        $user = User::query()->create([
            'name' => 'Jane Doe',
            'phone' => '+33 6 12 34 56 78',
        ]);

        $service = $this->app->make(GreenApiInboxService::class);
        $conversation = $service->ensureConversationForContact($user);

        GreenApiMessage::query()->create([
            'green_api_conversation_id' => $conversation->id,
            'remote_message_id' => 'msg-1',
            'remote_chat_id' => $conversation->chat_id,
            'direction' => 'outgoing_api',
            'webhook_type' => 'outgoingAPIMessageReceived',
            'message_type' => 'textMessage',
            'status' => 'sent',
            'body' => 'Hello from app',
            'sent_at' => now(),
            'raw_data' => ['idMessage' => 'msg-1'],
        ]);

        $headers = ['Authorization' => 'Bearer test-token'];

        $this->postJson(route('green-api.webhook'), [
            'typeWebhook' => 'outgoingAPIMessageReceived',
            'timestamp' => time(),
            'idMessage' => 'msg-1',
            'chatId' => $conversation->chat_id,
            'instanceData' => [
                'idInstance' => '123',
            ],
            'messageData' => [
                'typeMessage' => 'extendedTextMessage',
                'extendedTextMessageData' => [
                    'text' => 'Hello from app',
                    'chatId' => $conversation->chat_id,
                ],
            ],
        ], $headers)->assertOk();

        $this->postJson(route('green-api.webhook'), [
            'typeWebhook' => 'outgoingMessageStatus',
            'timestamp' => time(),
            'idMessage' => 'msg-1',
            'chatId' => $conversation->chat_id,
            'status' => 'delivered',
            'instanceData' => [
                'idInstance' => '123',
            ],
        ], $headers)->assertOk();

        $this->postJson(route('green-api.webhook'), [
            'typeWebhook' => 'outgoingMessageStatus',
            'timestamp' => time(),
            'idMessage' => 'msg-1',
            'chatId' => $conversation->chat_id,
            'status' => 'read',
            'instanceData' => [
                'idInstance' => '123',
            ],
        ], $headers)->assertOk();

        $this->assertSame(1, GreenApiMessage::query()->count());

        $message = GreenApiMessage::query()->firstOrFail();

        $this->assertSame('msg-1', $message->remote_message_id);
        $this->assertSame('read', $message->status);
        $this->assertSame('Hello from app', $message->body);
        $this->assertNotNull($message->delivered_at);
        $this->assertNotNull($message->read_at);
    }
}
