<?php

namespace App\Http\Requests\Api;

use App\Rules\EmailMessageFile;
use Illuminate\Foundation\Http\FormRequest;

class ImportExternalTaskEmailRequest extends FormRequest
{
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
            'project' => ['required', 'exists:projects,token'],
            'email' => ['bail', 'required', 'file', 'max:20480', 'extensions:eml', new EmailMessageFile],
            'user.id' => ['nullable'],
            'user.name' => ['nullable', 'string', 'max:255'],
            'user.email' => ['nullable', 'email'],
            'user.environment' => ['nullable', 'string', 'max:255'],
            'user.url' => ['nullable', 'url'],
            'metadata.url' => ['nullable', 'url'],
            'metadata.environment' => ['nullable', 'string', 'max:255'],
        ];
    }
}
