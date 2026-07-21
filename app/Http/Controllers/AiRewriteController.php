<?php

namespace App\Http\Controllers;

use App\Services\Ai\ContentRewriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiRewriteController extends Controller
{
    public function improve(Request $request, ContentRewriteService $rewriteService): JsonResponse
    {
        if (! config('ai_features.rewrite.enabled', false)) {
            return response()->json([
                'error' => 'AI improvement is disabled.',
            ], 404);
        }

        $attributes = $request->validate([
            'html' => ['required', 'string', 'max:50000'],
            'protected_tokens' => ['sometimes', 'array', 'max:100'],
            'protected_tokens.*' => ['string', 'max:120'],
            'context' => ['nullable', 'string', 'max:12000'],
        ]);

        try {
            $improvedHtml = $rewriteService->improveHtml(
                (string) $attributes['html'],
                $attributes['protected_tokens'] ?? [],
                $attributes['context'] ?? null
            );
        } catch (Throwable $exception) {
            Log::warning('AI content rewrite failed.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'error' => 'Unable to improve message with AI.',
            ], 422);
        }

        return response()->json([
            'improved_html' => $improvedHtml,
        ]);
    }
}
