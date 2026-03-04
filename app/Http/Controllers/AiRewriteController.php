<?php

namespace App\Http\Controllers;

use App\Services\Ai\LocalRewriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AiRewriteController extends Controller
{
    public function improve(Request $request, LocalRewriteService $rewriteService): JsonResponse
    {
        $attributes = $request->validate([
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
