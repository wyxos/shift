<?php

namespace App\Rules;

use App\Services\TaskEmail\TaskEmailMessageParser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Throwable;

class EmailMessageFile implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The email must be a valid uploaded file.');

            return;
        }

        try {
            app(TaskEmailMessageParser::class)->parse($value);
        } catch (Throwable) {
            $fail('The email must be a valid .eml message.');
        }
    }
}
