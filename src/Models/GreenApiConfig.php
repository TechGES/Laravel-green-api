<?php

namespace Ges\LaravelGreenApi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class GreenApiConfig extends Model
{
    protected $table = 'green_api_configs';

    protected $fillable = [
        'api_url',
        'media_url',
        'instance_id',
        'token',
        'test_chat_id',
        'webhook_url',
        'webhook_authorization_header',
        'instance_state',
        'last_webhook_type',
        'last_connection_checked_at',
        'last_webhook_received_at',
        'last_webhook_synced_at',
        'last_webhook_payload',
    ];

    protected function casts(): array
    {
        return [
            'last_connection_checked_at' => 'datetime',
            'last_webhook_received_at' => 'datetime',
            'last_webhook_synced_at' => 'datetime',
            'last_webhook_payload' => 'array',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultAttributes(): array
    {
        return Arr::only(config('green_api', []), [
            'api_url',
            'media_url',
            'instance_id',
            'token',
            'test_chat_id',
            'webhook_url',
            'webhook_authorization_header',
        ]);
    }
}
