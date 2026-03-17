<?php

namespace Ges\LaravelGreenApi\Tests\Feature;

use Ges\LaravelGreenApi\Models\GreenApiConversation;
use Ges\LaravelGreenApi\Models\GreenApiMessage as PersistedGreenApiMessage;
use Ges\LaravelGreenApi\Notifications\GreenApiChannel;
use Ges\LaravelGreenApi\Notifications\GreenApiMessage;
use Ges\LaravelGreenApi\Tests\Fixtures\User;
use Ges\LaravelGreenApi\Tests\TestCase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class GreenApiChannelTest extends TestCase
{
    public function test_it_sends_a_text_notification_through_the_inbox_service_for_models(): void
    {
        Http::fake([
            'https://api.example.test/*' => Http::response([
                'idMessage' => 'msg-1',
                'statusMessage' => 'sent',
            ]),
        ]);

        $user = User::query()->create([
            'name' => 'Jane Doe',
            'phone' => '+33 6 12 34 56 78',
        ]);

        $user->notify(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [GreenApiChannel::class];
            }

            public function toGreenApi(object $notifiable): GreenApiMessage
            {
                return GreenApiMessage::make('Invoice paid');
            }
        });

        $this->assertSame(1, GreenApiConversation::query()->count());
        $this->assertSame(1, PersistedGreenApiMessage::query()->count());
        $this->assertSame('Invoice paid', PersistedGreenApiMessage::query()->first()->body);
        $this->assertSame((string) $user->getKey(), GreenApiConversation::query()->first()->contact_id);
    }

    public function test_it_sends_a_file_notification_through_the_inbox_service_for_models(): void
    {
        Http::fake([
            'https://media.example.test/*' => Http::response([
                'urlFile' => 'https://cdn.example.test/invoice.pdf',
            ]),
            'https://api.example.test/*' => Http::response([
                'idMessage' => 'msg-2',
                'statusMessage' => 'sent',
            ]),
        ]);

        $user = User::query()->create([
            'name' => 'Jane Doe',
            'phone' => '+33 6 12 34 56 78',
        ]);

        $user->notify(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [GreenApiChannel::class];
            }

            public function toGreenApi(object $notifiable): GreenApiMessage
            {
                return GreenApiMessage::make()
                    ->file(__FILE__, 'Invoice attached', 'invoice.pdf');
            }
        });

        $this->assertSame(1, PersistedGreenApiMessage::query()->count());
        $this->assertSame('documentMessage', PersistedGreenApiMessage::query()->first()->message_type);
        $this->assertSame('Invoice attached', PersistedGreenApiMessage::query()->first()->caption);
        $this->assertSame('invoice.pdf', PersistedGreenApiMessage::query()->first()->file_name);
    }

    public function test_it_sends_to_a_routed_chat_id_for_anonymous_notifiables(): void
    {
        Http::fake([
            'https://api.example.test/*' => Http::response([
                'idMessage' => 'msg-3',
                'statusMessage' => 'sent',
            ]),
        ]);

        $notifiable = new AnonymousNotifiable;
        $notifiable->route('green_api', '+33 6 12 34 56 78');
        $notifiable->notify(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [GreenApiChannel::class];
            }

            public function toGreenApi(object $notifiable): string
            {
                return 'Anonymous delivery';
            }
        });

        $this->assertSame(0, GreenApiConversation::query()->count());
        $this->assertSame(0, PersistedGreenApiMessage::query()->count());
        Http::assertSentCount(1);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return str_contains($request->url(), '/waInstance123456/sendMessage/secret')
                && ($payload['chatId'] ?? null) === '33612345678@c.us'
                && ($payload['message'] ?? null) === 'Anonymous delivery';
        });
    }

    public function test_it_supports_the_green_api_driver_alias(): void
    {
        Http::fake([
            'https://api.example.test/*' => Http::response([
                'idMessage' => 'msg-4',
                'statusMessage' => 'sent',
            ]),
        ]);

        $notifiable = new AnonymousNotifiable;
        $notifiable->route('green_api', '+33 6 12 34 56 78');
        $notifiable->notify(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['green_api'];
            }

            public function toGreenApi(object $notifiable): string
            {
                return 'Alias delivery';
            }
        });

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return str_contains($request->url(), '/waInstance123456/sendMessage/secret')
                && ($payload['chatId'] ?? null) === '33612345678@c.us'
                && ($payload['message'] ?? null) === 'Alias delivery';
        });
    }
}
