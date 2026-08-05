<?php

namespace App\Http\Requests\Mcp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegisterOAuthClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'client_name' => ['nullable', 'string', 'max:255'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:5'],
            'redirect_uris.*' => ['required', 'url', 'distinct'],
            'grant_types' => ['required', 'array', 'size:2'],
            'grant_types.*' => ['required', 'string', 'distinct', Rule::in(['authorization_code', 'refresh_token'])],
            'response_types' => ['required', 'array', 'size:1'],
            'response_types.*' => ['required', 'string', Rule::in(['code'])],
            'token_endpoint_auth_method' => ['required', Rule::in(['none'])],
            'scope' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->array('redirect_uris') as $redirectUri) {
                if (! is_string($redirectUri) || ! $this->isAllowedRedirectUri($redirectUri)) {
                    $validator->errors()->add(
                        'redirect_uris',
                        'Redirect URIs must use HTTPS, except for HTTP loopback callbacks.',
                    );
                }
            }

            $requestedScopes = array_values(array_filter(explode(' ', $this->string('scope')->toString())));
            $unsupportedScopes = array_diff($requestedScopes, array_keys(config('shift_mcp.scopes')));

            if ($unsupportedScopes !== []) {
                $validator->errors()->add('scope', 'The requested OAuth scope is not supported.');
            }
        }];
    }

    private function isAllowedRedirectUri(string $redirectUri): bool
    {
        $scheme = strtolower((string) parse_url($redirectUri, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($redirectUri, PHP_URL_HOST), '[]'));

        if ($scheme === 'https') {
            return $host !== '';
        }

        return $scheme === 'http'
            && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
