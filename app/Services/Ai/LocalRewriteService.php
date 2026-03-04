<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class LocalRewriteService
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * @param  array<int, string>  $protectedTokens
     */
    public function improveHtml(string $html, array $protectedTokens = [], ?string $context = null): string
    {
        $provider = strtolower((string) config('shift_ai.provider', 'ollama'));
        $systemPrompt = $this->buildSystemPrompt($protectedTokens);
        $userPrompt = $this->buildUserPrompt($html, $context);

        $rawOutput = match ($provider) {
            'ollama' => $this->callOllama($systemPrompt, $userPrompt),
            'lmstudio' => $this->callLmStudio($systemPrompt, $userPrompt),
            default => throw new RuntimeException('Unsupported AI provider. Configure SHIFT_AI_PROVIDER to "ollama" or "lmstudio".'),
        };

        $improved = $this->normalizeOutput($rawOutput);
        $this->assertProtectedTokensWerePreserved($improved, $protectedTokens);

        return $improved;
    }

    /**
     * @param  array<int, string>  $protectedTokens
     */
    private function buildSystemPrompt(array $protectedTokens): string
    {
        $tokenRule = 'There are no protected placeholder tokens in this request.';

        if ($protectedTokens !== []) {
            $quotedTokens = array_map(
                static fn (string $token): string => '- "'.$token.'"',
                $protectedTokens
            );
            $tokenRule = "Protected placeholder tokens must remain exactly unchanged and appear exactly once each:\n".implode("\n", $quotedTokens);
        }

        return implode("\n", [
            'You improve existing HTML message content.',
            'Return valid HTML only. Do not return Markdown. Do not wrap in code fences.',
            'Rewrite to be concise and straight to the point while keeping all key information.',
            'Preserve all existing HTML structure unless changing wording is necessary for clarity.',
            'Do not remove essential details and do not invent new facts.',
            'Do not remove links, mentions, or semantic blocks.',
            $tokenRule,
            'If content is already clear, return it unchanged.',
        ]);
    }

    private function buildUserPrompt(string $html, ?string $context = null): string
    {
        $lines = [
            'Improve the following HTML message for clarity, grammar, and concise professionalism.',
            'Make it concise and straight to the point without removing key information.',
            'Keep the original intent and tone.',
        ];

        $trimmedContext = trim((string) $context);
        if ($trimmedContext !== '') {
            $lines[] = 'Thread context (for reference, use when rewriting):';
            $lines[] = $trimmedContext;
        }

        $lines[] = 'HTML input:';
        $lines[] = $html;

        return implode("\n", $lines);
    }

    private function callOllama(string $systemPrompt, string $userPrompt): string
    {
        $baseUrl = rtrim((string) config('shift_ai.ollama.base_url', 'http://127.0.0.1:11434'), '/');
        $model = (string) config('shift_ai.model', 'llama3.1');
        $timeout = (int) config('shift_ai.timeout', 60);

        $response = $this->http->timeout($timeout)->acceptJson()->post($baseUrl.'/api/chat', [
            'model' => $model,
            'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'options' => [
                'temperature' => 0.2,
            ],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Local AI request failed: '.$response->status());
        }

        $content = (string) data_get($response->json(), 'message.content', '');
        if ($content === '') {
            throw new RuntimeException('Local AI returned an empty response.');
        }

        return $content;
    }

    private function callLmStudio(string $systemPrompt, string $userPrompt): string
    {
        $baseUrl = rtrim((string) config('shift_ai.lmstudio.base_url', 'http://127.0.0.1:1234/v1'), '/');
        $model = (string) config('shift_ai.model', 'llama3.1');
        $timeout = (int) config('shift_ai.timeout', 60);
        $apiKey = trim((string) config('shift_ai.lmstudio.api_key', ''));

        $client = $this->http->timeout($timeout)->acceptJson();
        if ($apiKey !== '') {
            $client = $client->withToken($apiKey);
        }

        $response = $client->post($baseUrl.'/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.2,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Local AI request failed: '.$response->status());
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');
        if ($content === '') {
            throw new RuntimeException('Local AI returned an empty response.');
        }

        return $content;
    }

    private function normalizeOutput(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            throw new RuntimeException('Local AI returned an empty response.');
        }

        $matches = [];
        if (preg_match('/^```(?:html)?\s*(.*?)\s*```$/si', $trimmed, $matches) === 1) {
            $trimmed = trim((string) ($matches[1] ?? ''));
        }

        if ($trimmed === '') {
            throw new RuntimeException('Local AI returned an empty response.');
        }

        return $trimmed;
    }

    /**
     * @param  array<int, string>  $protectedTokens
     */
    private function assertProtectedTokensWerePreserved(string $content, array $protectedTokens): void
    {
        foreach ($protectedTokens as $token) {
            if ($token === '') {
                continue;
            }

            if (substr_count($content, $token) !== 1) {
                throw new RuntimeException('AI response did not preserve protected placeholders.');
            }
        }
    }
}
