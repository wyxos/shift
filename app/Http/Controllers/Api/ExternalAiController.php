<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\LocalRewriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ExternalAiController extends Controller
{
    public function improve(Request $request, LocalRewriteService $rewriteService): JsonResponse
    {
        if (! config('shift_ai.enabled', false)) {
            return response()->json([
                'error' => 'AI improvement is disabled.',
            ], 404);
        }

        $attributes = $request->validate([
            'project' => ['required', 'exists:projects,token'],
            'html' => ['required', 'string'],
            'protected_tokens' => ['sometimes', 'array'],
            'protected_tokens.*' => ['string', 'max:120'],
            'context' => ['nullable', 'string', 'max:12000'],
        ]);

        try {
            $improvedHtml = $rewriteService->improveHtml(
                (string) $attributes['html'],
                $attributes['protected_tokens'] ?? [],
                $attributes['context'] ?? null
            );
        } catch (Throwable $e) {
            return response()->json([
                'error' => $e->getMessage() ?: 'Unable to improve message with AI.',
            ], 422);
        }

        return response()->json([
            'improved_html' => $improvedHtml,
        ]);
    }
}
