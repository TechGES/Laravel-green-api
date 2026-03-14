# Laravel Green API

Laravel package for Green API inbound webhooks, outbound messaging, and a persisted WhatsApp-like inbox model.

## Install

```bash
composer require ges/laravel-green-api
php artisan laravel-green-api:install
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
