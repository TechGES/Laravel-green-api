<?php

return [
    'api_url' => env('GREEN_API_URL', ''),
    'media_url' => env('GREEN_API_MEDIA_URL', ''),
    'instance_id' => env('GREEN_API_INSTANCE_ID', ''),
    'token' => env('GREEN_API_TOKEN', ''),
    'test_chat_id' => env('GREEN_API_TEST_CHAT_ID', ''),
    'webhook_url' => env('GREEN_API_WEBHOOK_URL', ''),
    'webhook_authorization_header' => env('GREEN_API_WEBHOOK_AUTHORIZATION_HEADER', ''),
    'contact_model' => App\Models\User::class,
    'contact_name_attribute' => 'name',
    'contact_phone_attribute' => 'phone',
    'contact_search_attributes' => [
        'name',
        'email',
        'phone',
    ],
];
