<?php

namespace Ges\LaravelGreenApi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GreenApiMessage extends Model
{
    protected $table = 'green_api_messages';

    protected $fillable = [
        'green_api_conversation_id',
        'remote_message_id',
        'remote_chat_id',
        'direction',
        'webhook_type',
        'message_type',
        'status',
        'body',
        'caption',
        'file_name',
        'mime_type',
        'file_url',
        'sent_at',
        'delivered_at',
        'read_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(GreenApiConversation::class, 'green_api_conversation_id');
    }
}
