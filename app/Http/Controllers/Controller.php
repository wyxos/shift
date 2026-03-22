<?php

namespace App\Http\Controllers;

use App\Support\RichContentSanitizer;

abstract class Controller
{
    protected function sanitizeRichContent(?string $content): ?string
    {
        return app(RichContentSanitizer::class)->sanitize($content);
    }
}
