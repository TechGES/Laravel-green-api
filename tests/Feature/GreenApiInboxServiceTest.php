<?php

namespace Ges\LaravelGreenApi\Tests\Feature;

use Ges\LaravelGreenApi\Models\GreenApiConfig;
use Ges\LaravelGreenApi\Models\GreenApiConversation;
use Ges\LaravelGreenApi\Models\GreenApiMessage;
use Ges\LaravelGreenApi\Services\GreenApiInboxService;
use Ges\LaravelGreenApi\Tests\Fixtures\User;
use Ges\LaravelGreenApi\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

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

    public function test_outgoing_status_webhook_updates_existing_message_without_creating_duplicate(): void
    {
        $user = User::query()->create([
            'name' => 'Jane Doe',
            'phone' => '+33 6 12 34 56 78',
        ]);

        $service = $this->app->make(GreenApiInboxService::class);
        $conversation = $service->ensureConversationForContact($user);
        $lastMessageAt = Carbon::parse('2026-03-15 10:00:00');

        $conversation->update([
            'last_message_direction' => 'outgoing_api',
            'last_message_type' => 'textMessage',
            'last_message_preview' => 'Hello there',
            'last_message_at' => $lastMessageAt,
        ]);

        $existingMessage = GreenApiMessage::query()->create([
            'green_api_conversation_id' => $conversation->id,
            'remote_message_id' => 'msg-1',
            'remote_chat_id' => $conversation->chat_id,
            'direction' => 'outgoing_api',
            'webhook_type' => 'outgoingAPIMessageReceived',
            'message_type' => 'textMessage',
            'status' => 'sent',
            'body' => 'Hello there',
            'sent_at' => $lastMessageAt,
            'raw_data' => ['idMessage' => 'msg-1'],
        ]);

        $message = $service->ingestWebhook([
            'typeWebhook' => 'outgoingMessageStatus',
            'timestamp' => time(),
            'idMessage' => 'msg-1',
            'chatId' => $conversation->chat_id,
            'status' => 'read',
            'instanceData' => [
                'idInstance' => '123',
            ],
        ]);

        $this->assertNotNull($message);
        $this->assertSame($existingMessage->id, $message->id);
        $this->assertSame(1, GreenApiMessage::query()->count());

        $existingMessage->refresh();
        $conversation->refresh();

        $this->assertSame('read', $existingMessage->status);
        $this->assertSame('Hello there', $existingMessage->body);
        $this->assertSame('textMessage', $existingMessage->message_type);
        $this->assertNotNull($existingMessage->read_at);
        $this->assertNotNull($existingMessage->delivered_at);
        $this->assertTrue($conversation->last_message_at->equalTo($lastMessageAt));
        $this->assertSame('Hello there', $conversation->last_message_preview);
    }

    public function test_outgoing_status_webhook_without_message_id_does_not_create_placeholder_message(): void
    {
        $user = User::query()->create([
            'name' => 'Jane Doe',
            'phone' => '+33 6 12 34 56 78',
        ]);

        $service = $this->app->make(GreenApiInboxService::class);
        $conversation = $service->ensureConversationForContact($user);

        $message = $service->ingestWebhook([
            'typeWebhook' => 'outgoingMessageStatus',
            'timestamp' => time(),
            'chatId' => $conversation->chat_id,
            'status' => 'read',
            'instanceData' => [
                'idInstance' => '123',
            ],
        ]);

        $this->assertNull($message);
        $this->assertSame(1, GreenApiConversation::query()->count());
        $this->assertSame(0, GreenApiMessage::query()->count());
    }

    public function test_check_whatsapp_uses_contact_phone_number(): void
    {
        Http::fake([
            'https://api.example.test/*' => Http::response([
                'existsWhatsapp' => true,
            ]),
        ]);

        $user = User::query()->create([
            'name' => 'Jane Doe',
            'phone' => '+33 6 12 34 56 78',
        ]);

        $service = $this->app->make(GreenApiInboxService::class);
        $response = $service->checkWhatsapp($user);

        $this->assertTrue($response);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return str_contains($request->url(), '/waInstance123456/checkWhatsapp/secret')
                && ($payload['phoneNumber'] ?? null) === '33612345678';
        });
    }

    public function test_check_whatsapp_returns_false_when_contact_is_not_on_whatsapp(): void
    {
        Http::fake([
            'https://api.example.test/*' => Http::response([
                'existsWhatsapp' => false,
            ]),
        ]);

        $user = User::query()->create([
            'name' => 'Jane Doe',
            'phone' => '+33 6 12 34 56 79',
        ]);

        $service = $this->app->make(GreenApiInboxService::class);

        $this->assertFalse($service->checkWhatsapp($user));
    }
}
