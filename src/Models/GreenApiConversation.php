<?php

namespace Ges\LaravelGreenApi\Models;

use Ges\LaravelGreenApi\Relations\CastsOwnerKeyToStringBelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GreenApiConversation extends Model
{
    protected $table = 'green_api_conversations';

    protected $fillable = [
        'contact_id',
        'chat_id',
        'phone',
        'last_message_direction',
        'last_message_type',
        'last_message_preview',
        'unread_count',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $contactModel */
        $contactModel = config('green_api.contact_model', 'App\\Models\\User');
        $instance = new $contactModel;
        $instance->setConnection($this->getConnectionName());

        return new CastsOwnerKeyToStringBelongsTo(
            $instance->newQuery(),
            $this,
            'contact_id',
            $instance->getKeyName(),
            'contact'
        );
    }

    public function messages(): HasMany
    {
        return $this->hasMany(GreenApiMessage::class, 'green_api_conversation_id')
            ->orderBy('sent_at')
            ->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(GreenApiMessage::class, 'green_api_conversation_id')->latestOfMany('sent_at');
    }
}
