<?php

namespace Ges\LaravelGreenApi\Tests;

use Ges\LaravelGreenApi\GreenApiServiceProvider;
use Ges\LaravelGreenApi\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            GreenApiServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('green_api.contact_model', User::class);
        $app['config']->set('green_api.api_url', 'https://api.example.test');
        $app['config']->set('green_api.media_url', 'https://media.example.test');
        $app['config']->set('green_api.instance_id', '123456');
        $app['config']->set('green_api.token', 'secret');
        $app['config']->set('green_api.webhook_authorization_header', 'Bearer test-token');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('green_api_configs')) {
            Schema::create('green_api_configs', function (Blueprint $table): void {
                $table->id();
                $table->string('api_url')->nullable();
                $table->string('media_url')->nullable();
                $table->string('instance_id')->nullable();
                $table->text('token')->nullable();
                $table->string('test_chat_id')->nullable();
                $table->string('webhook_url')->nullable();
                $table->text('webhook_authorization_header')->nullable();
                $table->string('instance_state')->nullable();
                $table->string('last_webhook_type')->nullable();
                $table->timestamp('last_connection_checked_at')->nullable();
                $table->timestamp('last_webhook_received_at')->nullable();
                $table->timestamp('last_webhook_synced_at')->nullable();
                $table->json('last_webhook_payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('green_api_conversations')) {
            Schema::create('green_api_conversations', function (Blueprint $table): void {
                $table->id();
                $table->string('contact_id')->nullable()->index();
                $table->string('chat_id')->unique();
                $table->string('phone')->nullable()->index();
                $table->string('last_message_direction')->nullable();
                $table->string('last_message_type')->nullable();
                $table->string('last_message_preview', 255)->nullable();
                $table->unsignedInteger('unread_count')->default(0);
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('green_api_messages')) {
            Schema::create('green_api_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('green_api_conversation_id')->constrained('green_api_conversations')->cascadeOnDelete();
                $table->string('remote_message_id')->unique();
                $table->string('remote_chat_id')->nullable()->index();
                $table->string('direction')->nullable();
                $table->string('webhook_type')->nullable();
                $table->string('message_type')->nullable();
                $table->string('status')->nullable();
                $table->longText('body')->nullable();
                $table->longText('caption')->nullable();
                $table->string('file_name')->nullable();
                $table->string('mime_type')->nullable();
                $table->text('file_url')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->json('raw_data')->nullable();
                $table->timestamps();
            });
        }

        \DB::table('green_api_messages')->delete();
        \DB::table('green_api_conversations')->delete();
        \DB::table('green_api_configs')->delete();
        \DB::table('users')->delete();
    }
}
