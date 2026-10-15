<?php

namespace App\Http\Requests\Api;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class ExternalInboxRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'project' => ['required', 'string', 'max:255'],
            'user' => ['sometimes', 'array'],
            'user.id' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if ((! is_string($value) && ! is_int($value)) || strlen((string) $value) > 255) {
                    $fail('The application user ID must be a string or integer of at most 255 characters.');
                }
            }],
            'user.environment' => ['nullable', 'string', 'max:255'],
            'user.url' => ['nullable', 'url', 'max:2048'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'project.required' => 'The project token is required.',
            'user.url.url' => 'The application URL must be a valid URL.',
            'per_page.max' => 'Request no more than 100 notifications per page.',
        ];
    }
}
