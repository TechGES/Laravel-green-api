# Laravel Green API

Laravel package for Green API inbound webhooks, outbound messaging, and a persisted WhatsApp-like inbox model.

## Install

```bash
composer require ges/laravel-green-api
php artisan green-api:install
php artisan migrate
```

## Configuration

Publish the config file and set the Green API credentials:

```env
GREEN_API_URL=
GREEN_API_MEDIA_URL=
GREEN_API_INSTANCE_ID=
GREEN_API_TOKEN=
GREEN_API_TEST_CHAT_ID=
GREEN_API_WEBHOOK_URL=
GREEN_API_WEBHOOK_AUTHORIZATION_HEADER=
```

The package uses `App\Models\User` as the default contact model and adds a dynamic `greenApiConversation` relation automatically at boot.

## Commands

```bash
php artisan green-api:check-connection
php artisan green-api:sync-webhook
```

## Usage

```php
use Ges\LaravelGreenApi\Services\GreenApiInboxService;

$inbox = app(GreenApiInboxService::class);
$message = $inbox->sendTextMessage($user, 'Hello');
$conversation = $user->greenApiConversation;
```

Inbound webhooks are exposed at `POST /green-api/webhook`.

## Notifications

The package also exposes a Laravel notification channel via `GreenApiChannel`.

```php
use Ges\LaravelGreenApi\Notifications\GreenApiChannel;
use Ges\LaravelGreenApi\Notifications\GreenApiMessage;
use Illuminate\Notifications\Notification;

class InvoicePaid extends Notification
{
    public function via(object $notifiable): array
    {
        return [GreenApiChannel::class];
    }

    public function toGreenApi(object $notifiable): GreenApiMessage
    {
        return GreenApiMessage::make('Invoice paid.');
    }
}
```

For file delivery:

```php
public function toGreenApi(object $notifiable): GreenApiMessage
{
    return GreenApiMessage::make()
        ->file(storage_path('app/invoice.pdf'), 'Invoice attached', 'invoice.pdf');
}
```

When the notifiable is the configured contact model, notifications are persisted into the package inbox. For anonymous or non-model notifiables, route the destination with `green_api`:

```php
use Illuminate\Support\Facades\Notification;

Notification::route('green_api', '+33 6 12 34 56 78')
    ->notify(new InvoicePaid);
```
