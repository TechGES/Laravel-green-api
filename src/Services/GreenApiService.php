<?php

namespace Ges\LaravelGreenApi\Services;

use Ges\LaravelGreenApi\Models\GreenApiConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class GreenApiService
{
    private const array SUPPORTED_WEBHOOK_TYPES = [
        'incomingMessageReceived',
        'outgoingMessageReceived',
        'outgoingAPIMessageReceived',
        'outgoingMessageStatus',
        'stateInstanceChanged',
        'incomingBlock',
        'incomingCall',
    ];

    private ?GreenApiConfig $persistedConfig = null;

    private bool $persistedConfigResolved = false;

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->jsonRequest('GET', $this->apiEndpoint('getSettings'));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function setSettings(array $settings): array
    {
        if ($settings === []) {
            throw new RuntimeException('At least one Green API setting must be provided.');
        }

        return $this->jsonRequest('POST', $this->apiEndpoint('setSettings'), $settings);
    }

    /**
     * @return array<string, mixed>
     */
    public function getStateInstance(): array
    {
        return $this->jsonRequest('GET', $this->apiEndpoint('getStateInstance'));
    }

    /**
     * @return array<string, mixed>
     */
    public function configureWebhook(
        ?string $webhookUrl = null,
        ?string $webhookAuthorizationHeader = null
    ): array {
        return $this->setSettings([
            'webhookUrl' => $webhookUrl ?: $this->webhookUrl(),
            'webhookUrlToken' => $webhookAuthorizationHeader ?? $this->webhookAuthorizationHeader(),
            'incomingWebhook' => 'yes',
            'outgoingMessageWebhook' => 'yes',
            'outgoingAPIMessageWebhook' => 'yes',
            'deletedMessageWebhook' => 'yes',
            'editedMessageWebhook' => 'yes',
            'outgoingWebhook' => 'yes',
            'stateWebhook' => 'yes',
            'incomingBlockWebhook' => 'yes',
            'pollMessageWebhook' => 'yes',
            'incomingCallWebhook' => 'yes',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(string $chatId, string $message): array
    {
        return $this->jsonRequest('POST', $this->apiEndpoint('sendMessage'), [
            'chatId' => $this->normalizeChatId($chatId),
            'message' => $message,
        ]);
    }

    public function checkWhatsapp(int|string $phoneNumber): bool
    {
        $response = $this->jsonRequest('POST', $this->apiEndpoint('checkWhatsapp'), [
            'phoneNumber' => $this->normalizePhoneNumber($phoneNumber),
        ]);

        $existsWhatsapp = $response['existsWhatsapp'] ?? null;

        if (! is_bool($existsWhatsapp)) {
            throw new RuntimeException('Green API returned an invalid checkWhatsapp response.');
        }

        return $existsWhatsapp;
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadFile(mixed $file, ?string $fileName = null): array
    {
        $resolvedFile = $this->resolveFile($file, $fileName);

        $response = $this->mediaRequest()
            ->attach('file', $resolvedFile['contents'], $resolvedFile['fileName'])
            ->post($this->mediaEndpoint('uploadFile'));

        return $this->decodeJson($this->throwIfFailed($response, 'Unable to upload file to Green API.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function sendFileByUrl(
        string $chatId,
        string $urlFile,
        string $fileName,
        ?string $caption = null
    ): array {
        $payload = [
            'chatId' => $this->normalizeChatId($chatId),
            'urlFile' => $urlFile,
            'fileName' => $fileName,
        ];

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }

        return $this->jsonRequest('POST', $this->apiEndpoint('sendFileByUrl'), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendUploadedFile(
        string $chatId,
        mixed $file,
        ?string $caption = null,
        ?string $fileName = null
    ): array {
        $resolvedFile = $this->resolveFile($file, $fileName);
        $upload = $this->uploadResolvedFile($resolvedFile['contents'], $resolvedFile['fileName']);

        return $this->sendFileByUrl(
            $chatId,
            $upload['urlFile'],
            $resolvedFile['fileName'],
            $caption
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function receiveNotification(): ?array
    {
        $response = $this->request()
            ->get($this->apiEndpoint('receiveNotification'));

        $this->throwIfFailed($response, 'Unable to receive Green API notification.');

        return $this->decodeNullableJson($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteNotification(int|string $receiptId): array
    {
        $response = $this->request()
            ->delete($this->apiEndpoint('deleteNotification', (string) $receiptId));

        return $this->decodeJson($this->throwIfFailed($response, 'Unable to delete Green API notification.'));
    }

    public function hasValidWebhookAuthorizationHeader(?string $authorizationHeader): bool
    {
        $expectedHeader = $this->webhookAuthorizationHeader();

        if ($expectedHeader === '') {
            return true;
        }

        if ($authorizationHeader === null) {
            return false;
        }

        return hash_equals($expectedHeader, trim($authorizationHeader));
    }

    public function normalizeChatId(string $chatId): string
    {
        $normalizedChatId = strtolower(trim($chatId));

        if (str_ends_with($normalizedChatId, '@c.us')) {
            $normalizedChatId = substr($normalizedChatId, 0, -5);
        }

        $phone = preg_replace('/\D+/', '', $normalizedChatId);

        if (! is_string($phone) || $phone === '') {
            throw new RuntimeException('Green API chat ID must contain the phone prefix and number.');
        }

        return $phone.'@c.us';
    }

    public function extractPhoneNumber(string $chatId): string
    {
        return str_replace('@c.us', '', $this->normalizeChatId($chatId));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{typeWebhook: string, messageType: ?string, category: string, supported: bool}
     */
    public function summarizeWebhook(array $payload): array
    {
        $typeWebhook = is_string($payload['typeWebhook'] ?? null) ? $payload['typeWebhook'] : '';
        $messageType = data_get($payload, 'messageData.typeMessage');
        $messageType = is_string($messageType) ? $messageType : null;

        $category = match ($typeWebhook) {
            'incomingMessageReceived' => match ($messageType) {
                'imageMessage', 'videoMessage', 'audioMessage', 'documentMessage', 'stickerMessage' => 'incoming_file',
                'pollMessage', 'pollUpdateMessage' => 'survey',
                'editedMessage' => 'edited_message',
                'deletedMessage' => 'deleted_message',
                default => 'incoming_message',
            },
            'outgoingMessageReceived' => 'phone_message',
            'outgoingAPIMessageReceived' => 'api_message',
            'outgoingMessageStatus' => 'outgoing_status',
            'stateInstanceChanged' => 'authorization_state',
            'incomingBlock' => 'incoming_block',
            'incomingCall' => 'incoming_call',
            default => 'unsupported',
        };

        return [
            'typeWebhook' => $typeWebhook,
            'messageType' => $messageType,
            'category' => $category,
            'supported' => in_array($typeWebhook, self::SUPPORTED_WEBHOOK_TYPES, true),
        ];
    }

    public function isSupportedWebhook(array $payload): bool
    {
        return $this->summarizeWebhook($payload)['supported'];
    }

    public function forgetPersistedConfig(): void
    {
        $this->persistedConfig = null;
        $this->persistedConfigResolved = false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function jsonRequest(string $method, string $url, array $payload = []): array
    {
        $options = [];

        if ($payload !== []) {
            $options['json'] = $payload;
        }

        $response = $this->request()->send($method, $url, $options);

        return $this->decodeJson($this->throwIfFailed($response, 'Green API request failed.'));
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->timeout(30);
    }

    private function mediaRequest(): PendingRequest
    {
        return Http::timeout(30);
    }

    private function apiEndpoint(string $method, ?string $suffix = null): string
    {
        return $this->buildEndpoint($this->apiUrl(), $method, $suffix);
    }

    private function mediaEndpoint(string $method, ?string $suffix = null): string
    {
        return $this->buildEndpoint($this->mediaUrl(), $method, $suffix);
    }

    private function buildEndpoint(string $baseUrl, string $method, ?string $suffix = null): string
    {
        $endpoint = rtrim($baseUrl, '/').'/waInstance'.$this->instanceId().'/'.$method.'/'.$this->token();

        if ($suffix !== null && $suffix !== '') {
            $endpoint .= '/'.ltrim($suffix, '/');
        }

        return $endpoint;
    }

    private function apiUrl(): string
    {
        return $this->configuredString('api_url');
    }

    private function mediaUrl(): string
    {
        return $this->configuredString('media_url');
    }

    private function instanceId(): string
    {
        return $this->configuredString('instance_id');
    }

    private function token(): string
    {
        return $this->configuredString('token');
    }

    private function webhookUrl(): string
    {
        $configuredWebhookUrl = $this->configuredOptionalString('webhook_url');

        if ($configuredWebhookUrl !== null) {
            return $configuredWebhookUrl;
        }

        if (Route::has('green-api.webhook')) {
            return route('green-api.webhook');
        }

        throw new RuntimeException('Green API webhook URL is not configured.');
    }

    private function webhookAuthorizationHeader(): string
    {
        return $this->configuredOptionalString('webhook_authorization_header') ?? '';
    }

    private function configuredString(string $key): string
    {
        $value = $this->configuredOptionalString($key);

        if ($value === null) {
            throw new RuntimeException("Green API configuration key [green_api.{$key}] is not set.");
        }

        return $value;
    }

    /**
     * @return array{contents: string, fileName: string}
     */
    private function resolveFile(mixed $file, ?string $fileName = null): array
    {
        if ($file instanceof UploadedFile || $this->isTemporaryUploadedFile($file)) {
            $path = $file->getRealPath();

            if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('The uploaded file could not be read.');
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException('The uploaded file could not be read.');
            }

            return [
                'contents' => $contents,
                'fileName' => $fileName ?: $file->getClientOriginalName(),
            ];
        }

        if (! is_string($file) || ! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException("The file [{$file}] could not be read.");
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException("The file [{$file}] could not be read.");
        }

        return [
            'contents' => $contents,
            'fileName' => $fileName ?: basename($file),
        ];
    }

    private function throwIfFailed(Response $response, string $message): Response
    {
        if ($response->failed()) {
            $reason = trim($response->body());
            $errorMessage = $reason === '' ? $message : $message.' '.$reason;

            throw new RuntimeException($errorMessage);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Response $response): array
    {
        $payload = $this->decodeNullableJson($response);

        if ($payload === null) {
            throw new RuntimeException('Green API returned an empty response.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeNullableJson(Response $response): ?array
    {
        if (trim($response->body()) === '') {
            return null;
        }

        $payload = $response->json();

        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Green API returned an unexpected response payload.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadResolvedFile(string $contents, string $fileName): array
    {
        $response = $this->mediaRequest()
            ->attach('file', $contents, $fileName)
            ->post($this->mediaEndpoint('uploadFile'));

        return $this->decodeJson($this->throwIfFailed($response, 'Unable to upload file to Green API.'));
    }

    private function normalizePhoneNumber(int|string $phoneNumber): string
    {
        $normalizedPhoneNumber = preg_replace('/\D+/', '', (string) $phoneNumber);

        if (! is_string($normalizedPhoneNumber) || $normalizedPhoneNumber === '') {
            throw new RuntimeException('Green API phone number must contain digits only.');
        }

        $length = strlen($normalizedPhoneNumber);

        if ($length < 11 || $length > 16) {
            throw new RuntimeException('Green API phone number must contain between 11 and 16 digits.');
        }

        return $normalizedPhoneNumber;
    }

    private function configuredOptionalString(string $key): ?string
    {
        $persistedConfig = $this->persistedConfig();
        $persistedValue = $persistedConfig?->{$key};

        if (is_string($persistedValue) && trim($persistedValue) !== '') {
            return trim($persistedValue);
        }

        $configuredValue = config("green_api.{$key}");

        if (! is_string($configuredValue) || trim($configuredValue) === '') {
            return null;
        }

        return trim($configuredValue);
    }

    private function persistedConfig(): ?GreenApiConfig
    {
        if ($this->persistedConfigResolved) {
            return $this->persistedConfig;
        }

        $this->persistedConfigResolved = true;

        try {
            if (! Schema::hasTable('green_api_configs')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return $this->persistedConfig = GreenApiConfig::query()->first();
    }

    private function isTemporaryUploadedFile(mixed $file): bool
    {
        $temporaryUploadedFileClass = 'Livewire\\Features\\SupportFileUploads\\TemporaryUploadedFile';

        return is_object($file)
            && class_exists($temporaryUploadedFileClass)
            && $file instanceof $temporaryUploadedFileClass;
    }
}
