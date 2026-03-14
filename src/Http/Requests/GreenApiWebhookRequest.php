<?php

namespace Ges\LaravelGreenApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GreenApiWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'typeWebhook' => ['required', 'string'],
            'timestamp' => ['nullable', 'integer'],
            'instanceData' => ['required', 'array'],
            'instanceData.idInstance' => ['nullable'],
            'instanceData.wid' => ['nullable', 'string'],
            'instanceData.typeInstance' => ['nullable', 'string'],
            'senderData' => ['sometimes', 'array'],
            'messageData' => ['sometimes', 'array'],
            'status' => ['sometimes', 'string'],
            'stateInstance' => ['sometimes', 'string'],
            'statusMessage' => ['sometimes', 'string'],
            'callData' => ['sometimes', 'array'],
            'incomingBlockData' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'typeWebhook.required' => trans('green-api::messages.validation.type_webhook_required'),
            'instanceData.required' => trans('green-api::messages.validation.instance_data_required'),
        ];
    }
}
