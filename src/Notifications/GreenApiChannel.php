<?php

namespace Ges\LaravelGreenApi\Notifications;

use Ges\LaravelGreenApi\Services\GreenApiInboxService;
use Ges\LaravelGreenApi\Services\GreenApiService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use RuntimeException;

class GreenApiChannel
{
    public function __construct(
        protected GreenApiInboxService $inboxService,
        protected GreenApiService $greenApiService,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toGreenApi')) {
            return;
        }

        $message = $notification->toGreenApi($notifiable);

        if ($message === null) {
            return;
        }

        if (is_string($message)) {
            $message = GreenApiMessage::make($message);
        }

        if (! $message instanceof GreenApiMessage) {
            throw new RuntimeException('Green API notifications must return a string, GreenApiMessage, or null from toGreenApi().');
        }

        if ($notifiable instanceof Model) {
            $this->sendToModel($notifiable, $message);

            return;
        }

        $route = $this->resolveRoute($notifiable, $notification);

        if ($route === null || $route === '') {
            return;
        }

        $this->sendToRoute($route, $message);
    }

    protected function sendToModel(Model $notifiable, GreenApiMessage $message): void
    {
        if ($message->hasFile()) {
            $this->inboxService->sendFileMessage(
                $notifiable,
                $message->file,
                $message->caption,
                $message->fileName
            );

            return;
        }

        if ($message->body === null || $message->body === '') {
            throw new RuntimeException('Green API text notifications require a non-empty body.');
        }

        $this->inboxService->sendTextMessage($notifiable, $message->body);
    }

    protected function sendToRoute(string $route, GreenApiMessage $message): void
    {
        if ($message->hasFile()) {
            $this->greenApiService->sendUploadedFile(
                $route,
                $message->file,
                $message->caption,
                $message->fileName
            );

            return;
        }

        if ($message->body === null || $message->body === '') {
            throw new RuntimeException('Green API text notifications require a non-empty body.');
        }

        $this->greenApiService->sendMessage($route, $message->body);
    }

    protected function resolveRoute(object $notifiable, Notification $notification): ?string
    {
        if (method_exists($notifiable, 'routeNotificationForGreenApi')) {
            $route = $notifiable->routeNotificationForGreenApi($notification);

            return is_string($route) ? $route : null;
        }

        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return null;
        }

        foreach (['green_api', 'greenApi'] as $channel) {
            $route = $notifiable->routeNotificationFor($channel, $notification);

            if (is_string($route) && $route !== '') {
                return $route;
            }
        }

        return null;
    }
}
