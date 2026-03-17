<?php

namespace Ges\LaravelGreenApi;

use Ges\LaravelGreenApi\Commands\CheckGreenApiConnectionCommand;
use Ges\LaravelGreenApi\Commands\SyncGreenApiWebhookCommand;
use Ges\LaravelGreenApi\Models\GreenApiConversation;
use Ges\LaravelGreenApi\Notifications\GreenApiChannel;
use Ges\LaravelGreenApi\Relations\CastsOwnerKeyToStringHasOne;
use Ges\LaravelGreenApi\Services\GreenApiInboxService;
use Ges\LaravelGreenApi\Services\GreenApiService;
use Ges\LaravelGreenApi\Support\GreenApiContactManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\ChannelManager;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GreenApiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-green-api')
            ->hasConfigFile('green_api')
            ->hasRoute('api')
            ->hasTranslations()
            ->hasMigrations([
                'create_green_api_configs_table',
                'create_green_api_conversations_table',
                'create_green_api_messages_table',
            ])
            ->hasCommands([
                CheckGreenApiConnectionCommand::class,
                SyncGreenApiWebhookCommand::class,
            ])
            ->runsMigrations()
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations();
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(GreenApiContactManager::class, function (): GreenApiContactManager {
            return new GreenApiContactManager;
        });

        $this->app->singleton(GreenApiService::class, function (): GreenApiService {
            return new GreenApiService;
        });

        $this->app->singleton(GreenApiInboxService::class, function ($app): GreenApiInboxService {
            return new GreenApiInboxService(
                $app->make(GreenApiService::class),
                $app->make(GreenApiContactManager::class)
            );
        });

        $this->app->alias(GreenApiService::class, 'green-api');
    }

    public function packageBooted(): void
    {
        $channelManager = $this->app->make(ChannelManager::class);

        foreach (['green_api', GreenApiChannel::class] as $driver) {
            $channelManager->extend($driver, fn ($app): GreenApiChannel => $app->make(GreenApiChannel::class));
        }

        /** @var class-string<Model>|mixed $contactModel */
        $contactModel = config('green_api.contact_model');

        if (! is_string($contactModel) || ! is_subclass_of($contactModel, Model::class)) {
            return;
        }

        $contactModel::resolveRelationUsing('greenApiConversation', function (Model $model) {
            $instance = new GreenApiConversation;
            $instance->setConnection($model->getConnectionName());

            return new CastsOwnerKeyToStringHasOne(
                $instance->newQuery(),
                $model,
                'contact_id',
                $model->getKeyName()
            );
        });
    }
}
