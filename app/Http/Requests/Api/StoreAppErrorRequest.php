<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppErrorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
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
            'source' => ['required', 'string', 'in:backend,ui'],
            'environment' => ['nullable', 'string', 'max:255'],
            'release' => ['nullable', 'string', 'max:255'],
            'git_sha' => ['nullable', 'string', 'max:255'],
            'exception' => ['nullable', 'array'],
            'exception.class' => ['nullable', 'string', 'max:512'],
            'exception.message' => ['nullable', 'string', 'max:20000'],
            'error' => ['nullable', 'array'],
            'error.name' => ['nullable', 'string', 'max:512'],
            'error.message' => ['nullable', 'string', 'max:20000'],
            'message' => ['nullable', 'string', 'max:20000'],
            'stack' => ['nullable', 'string', 'max:20000'],
            'stacktrace' => ['nullable', 'array'],
            'stacktrace.frames' => ['nullable', 'array', 'max:100'],
            'stacktrace.frames.*.file' => ['nullable', 'string', 'max:2048'],
            'stacktrace.frames.*.line' => ['nullable', 'integer', 'min:0'],
            'stacktrace.frames.*.function' => ['nullable', 'string', 'max:512'],
            'stacktrace.frames.*.in_app' => ['nullable', 'boolean'],
            'context' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'user' => ['nullable', 'array'],
            'user.id' => ['nullable', 'string', 'max:255'],
            'user.name' => ['nullable', 'string', 'max:255'],
            'user.email' => ['nullable', 'email', 'max:255'],
            'user.environment' => ['nullable', 'string', 'max:255'],
            'user.url' => ['nullable', 'string', 'max:2048'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}
