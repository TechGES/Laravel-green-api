<?php

namespace Ges\LaravelGreenApi\Services;

use Ges\LaravelGreenApi\Models\GreenApiConfig;
use Ges\LaravelGreenApi\Models\GreenApiConversation;
use Ges\LaravelGreenApi\Models\GreenApiMessage;
use Ges\LaravelGreenApi\Support\GreenApiContactManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class GreenApiInboxService
{
    public function __construct(
        protected GreenApiService $greenApiService,
        protected GreenApiContactManager $greenApiContactManager
    ) {}

    public function currentConfig(): GreenApiConfig
    {
        return GreenApiConfig::query()->firstOrCreate([], GreenApiConfig::defaultAttributes());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function saveConfig(array $attributes): GreenApiConfig
    {
        $config = $this->currentConfig();
        $config->fill($attributes);
        $config->save();
        $this->greenApiService->forgetPersistedConfig();

        return $config->fresh();
    }

    public function markConnectionChecked(?string $state = null): GreenApiConfig
    {
        $config = $this->currentConfig();
        $config->fill([
            'instance_state' => $state,
            'last_connection_checked_at' => now(),
        ]);
        $config->save();

        return $config->fresh();
    }

    public function markWebhookSynced(): GreenApiConfig
    {
        $config = $this->currentConfig();
        $config->fill([
            'last_webhook_synced_at' => now(),
        ]);
        $config->save();

        return $config->fresh();
    }

    public function contact(int|string $contactId): ?Model
    {
        $contact = $this->greenApiContactManager->find($contactId);

        if ($contact === null) {
            return null;
        }

        return $this->attachConversationToContact($contact, includeMessages: true);
    }

    public function ensureConversationForContact(Model $contact): GreenApiConversation
    {
        $chatId = $this->greenApiService->normalizeChatId($this->greenApiContactManager->phone($contact));
        $phone = $this->greenApiService->extractPhoneNumber($chatId);

        $conversation = GreenApiConversation::query()->firstOrCreate(
            ['chat_id' => $chatId],
            [
                'contact_id' => (string) $contact->getKey(),
                'phone' => $phone,
            ]
        );

        if ((string) $conversation->contact_id !== (string) $contact->getKey()) {
            $conversation->update(['contact_id' => (string) $contact->getKey()]);
        }

        return $conversation->fresh();
    }

    public function sendTextMessage(Model $contact, string $body): GreenApiMessage
    {
        $conversation = $this->ensureConversationForContact($contact);
        $response = $this->greenApiService->sendMessage($conversation->chat_id, $body);

        return $this->storeOutgoingMessage($conversation, $response, [
            'message_type' => 'textMessage',
            'body' => $body,
        ]);
    }

    public function sendFileMessage(
        Model $contact,
        mixed $file,
        ?string $caption = null,
        ?string $fileName = null
    ): GreenApiMessage {
        $conversation = $this->ensureConversationForContact($contact);
        $resolvedFileName = $this->resolveFileName($file, $fileName);
        $response = $this->greenApiService->sendUploadedFile($conversation->chat_id, $file, $caption, $resolvedFileName);

        return $this->storeOutgoingMessage($conversation, $response, [
            'message_type' => 'documentMessage',
            'caption' => $caption,
            'file_name' => $resolvedFileName,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingestWebhook(array $payload): ?GreenApiMessage
    {
        $summary = $this->greenApiService->summarizeWebhook($payload);
        $config = $this->currentConfig();

        $config->update([
            'instance_state' => $summary['category'] === 'authorization_state'
                ? (is_string($payload['stateInstance'] ?? null) ? $payload['stateInstance'] : null)
                : $config->instance_state,
            'last_webhook_type' => $summary['typeWebhook'],
            'last_webhook_received_at' => now(),
            'last_webhook_payload' => $payload,
        ]);

        if (! $summary['supported']) {
            return null;
        }

        $chatId = $this->extractChatId($payload);

        if ($chatId === null) {
            return null;
        }

        $conversation = $this->findOrCreateConversationByChatId($chatId);

        if ($conversation === null) {
            return null;
        }

        return $this->storeWebhookMessage($conversation, $payload, $summary);
    }

    /**
     * @return Collection<int, Model>
     */
    public function contacts(string $search = ''): Collection
    {
        $contacts = $this->greenApiContactManager->search(
            $this->greenApiContactManager->contacts(),
            $search
        );

        $contacts = $this->attachConversations($contacts);

        return $contacts
            ->sortByDesc(fn (Model $contact) => $contact->greenApiConversation?->last_message_at?->timestamp ?? 0)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $attributes
     */
    private function storeOutgoingMessage(GreenApiConversation $conversation, array $response, array $attributes): GreenApiMessage
    {
        $message = GreenApiMessage::query()->updateOrCreate(
            ['remote_message_id' => (string) ($response['idMessage'] ?? Str::ulid())],
            array_merge($attributes, [
                'green_api_conversation_id' => $conversation->id,
                'remote_chat_id' => $conversation->chat_id,
                'direction' => 'outgoing_api',
                'webhook_type' => 'outgoingAPIMessageReceived',
                'status' => is_string($response['statusMessage'] ?? null) ? $response['statusMessage'] : 'sent',
                'sent_at' => now(),
                'raw_data' => $response,
            ])
        );

        $this->touchConversation(
            $conversation,
            $message->direction,
            $message->message_type,
            $message->body ?: $message->caption ?: $message->file_name
        );

        return $message;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{typeWebhook: string, messageType: ?string, category: string, supported: bool}  $summary
     */
    private function storeWebhookMessage(GreenApiConversation $conversation, array $payload, array $summary): GreenApiMessage
    {
        $message = GreenApiMessage::query()->firstOrNew([
            'remote_message_id' => $this->extractRemoteMessageId($payload) ?? Str::ulid()->toBase32(),
        ]);

        $message->fill([
            'green_api_conversation_id' => $conversation->id,
            'remote_chat_id' => $conversation->chat_id,
            'direction' => $this->resolveDirection($summary['category']),
            'webhook_type' => $summary['typeWebhook'],
            'message_type' => $summary['messageType'],
            'status' => $this->extractStatus($payload),
            'body' => $this->extractBody($payload),
            'caption' => $this->extractCaption($payload),
            'file_name' => $this->extractFileName($payload),
            'mime_type' => $this->extractMimeType($payload),
            'file_url' => $this->extractFileUrl($payload),
            'sent_at' => $message->sent_at ?? now(),
            'raw_data' => $payload,
        ]);

        if ($message->status === 'delivered') {
            $message->delivered_at = now();
        }

        if ($message->status === 'read') {
            $message->read_at = now();
        }

        $message->save();

        $preview = $message->body
            ?: $message->caption
            ?: $message->file_name
            ?: match ($summary['category']) {
                'incoming_call' => trans('green-api::messages.preview.incoming_call'),
                'incoming_block' => trans('green-api::messages.preview.incoming_block'),
                'authorization_state' => trans('green-api::messages.preview.authorization_state'),
                default => trans('green-api::messages.preview.default'),
            };

        $this->touchConversation(
            $conversation,
            $message->direction,
            $message->message_type,
            $preview,
            $message->direction === 'incoming'
        );

        return $message;
    }

    private function touchConversation(
        GreenApiConversation $conversation,
        string $direction,
        ?string $messageType,
        ?string $preview,
        bool $incrementUnread = false
    ): void {
        $conversation->update([
            'last_message_direction' => $direction,
            'last_message_type' => $messageType,
            'last_message_preview' => Str::limit((string) $preview, 120),
            'last_message_at' => now(),
            'unread_count' => $incrementUnread ? $conversation->unread_count + 1 : $conversation->unread_count,
        ]);
    }

    private function findOrCreateConversationByChatId(string $chatId): ?GreenApiConversation
    {
        $normalizedChatId = $this->greenApiService->normalizeChatId($chatId);
        $phone = $this->greenApiService->extractPhoneNumber($normalizedChatId);
        $contact = $this->greenApiContactManager->resolveByPhone($phone);

        return GreenApiConversation::query()->firstOrCreate(
            ['chat_id' => $normalizedChatId],
            [
                'contact_id' => $contact ? (string) $contact->getKey() : null,
                'phone' => $phone,
            ]
        );
    }

    /**
     * @param  Collection<int, Model>  $contacts
     * @return Collection<int, Model>
     */
    private function attachConversations(Collection $contacts): Collection
    {
        if ($contacts->isEmpty()) {
            return $contacts;
        }

        $conversations = GreenApiConversation::query()
            ->with('latestMessage')
            ->whereIn('contact_id', $contacts->map(fn (Model $contact): string => (string) $contact->getKey()))
            ->get()
            ->keyBy(fn (GreenApiConversation $conversation): string => (string) $conversation->contact_id);

        return $contacts->map(function (Model $contact) use ($conversations): Model {
            $contact->setRelation('greenApiConversation', $conversations->get((string) $contact->getKey()));

            return $contact;
        });
    }

    private function attachConversationToContact(Model $contact, bool $includeMessages = false): Model
    {
        $conversation = GreenApiConversation::query()
            ->with($includeMessages ? ['messages', 'latestMessage'] : ['latestMessage'])
            ->where('contact_id', (string) $contact->getKey())
            ->first();

        $contact->setRelation('greenApiConversation', $conversation);

        return $contact;
    }

    private function resolveFileName(mixed $file, ?string $fileName = null): string
    {
        if ($fileName !== null && $fileName !== '') {
            return $fileName;
        }

        if ($file instanceof UploadedFile || $this->isTemporaryUploadedFile($file)) {
            return $file->getClientOriginalName();
        }

        if (! is_string($file) || $file === '') {
            throw new \RuntimeException('Green API file payload must be a file path or uploaded file instance.');
        }

        return basename($file);
    }

    private function isTemporaryUploadedFile(mixed $file): bool
    {
        return is_object($file)
            && class_exists('Livewire\\Features\\SupportFileUploads\\TemporaryUploadedFile')
            && $file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractChatId(array $payload): ?string
    {
        $candidates = [
            $payload['chatId'] ?? null,
            data_get($payload, 'senderData.chatId'),
            data_get($payload, 'senderData.sender'),
            data_get($payload, 'messageData.fileMessageData.chatId'),
            data_get($payload, 'messageData.extendedTextMessageData.chatId'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractRemoteMessageId(array $payload): ?string
    {
        $candidate = $payload['idMessage'] ?? data_get($payload, 'idMessage');

        return is_string($candidate) && $candidate !== '' ? $candidate : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractStatus(array $payload): ?string
    {
        $candidate = $payload['status'] ?? $payload['statusMessage'] ?? null;

        return is_string($candidate) && $candidate !== '' ? $candidate : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractBody(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'messageData.textMessageData.textMessage'),
            data_get($payload, 'messageData.extendedTextMessageData.text'),
            data_get($payload, 'messageData.pollMessageData.name'),
            data_get($payload, 'messageData.deletedMessageData.text'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractCaption(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'messageData.fileMessageData.caption'),
            data_get($payload, 'messageData.imageMessageData.caption'),
            data_get($payload, 'messageData.videoMessageData.caption'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractFileName(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'messageData.fileMessageData.fileName'),
            data_get($payload, 'messageData.documentMessageData.fileName'),
            data_get($payload, 'messageData.imageMessageData.fileName'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractMimeType(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'messageData.fileMessageData.mimeType'),
            data_get($payload, 'messageData.documentMessageData.mimeType'),
            data_get($payload, 'messageData.imageMessageData.mimeType'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractFileUrl(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'messageData.fileMessageData.downloadUrl'),
            data_get($payload, 'messageData.documentMessageData.downloadUrl'),
            data_get($payload, 'messageData.imageMessageData.downloadUrl'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveDirection(string $category): string
    {
        return match ($category) {
            'phone_message' => 'outgoing_phone',
            'api_message', 'outgoing_status', 'authorization_state' => 'outgoing_api',
            default => 'incoming',
        };
    }
}
