<?php

declare(strict_types=1);

namespace IntentPHP\Guard\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiClient implements AiClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeout,
        private readonly int $maxTokens,
    ) {}

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    public function generate(string $prompt): string
    {
        if (! $this->isAvailable()) {
            return '[AI unavailable — GUARD_AI_API_KEY not set.]';
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a Laravel security expert. Respond concisely.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => $this->maxTokens,
            'temperature' => 0.2,
        ];

        $response = $this->request($payload);

        if ($response === null) {
            return '[AI request failed after retries.]';
        }

        return $response['choices'][0]['message']['content'] ?? '[Empty AI response.]';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function request(array $payload): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/chat/completions';

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])->timeout($this->timeout)->post($url, $payload);

                if ($response->successful()) {
                    return $response->json();
                }

                $status = $response->status();

                // Retry on 429 (rate limit) or 5xx (server error)
                if ($attempt === 0 && ($status === 429 || $status >= 500)) {
                    $retryAfter = min((int) $response->header('Retry-After', '2'), 10);
                    sleep($retryAfter);
                    continue;
                }

                Log::warning('Guard AI request failed', [
                    'status' => $status,
                    'body' => $response->body(),
                ]);

                return null;
            } catch (\Throwable $e) {
                if ($attempt === 0) {
                    sleep(1);
                    continue;
                }

                Log::warning('Guard AI request exception', [
                    'message' => $e->getMessage(),
                ]);

                return null;
            }
        }

        return null;
    }
}
