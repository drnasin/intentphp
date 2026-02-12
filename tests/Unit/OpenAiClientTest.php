<?php

declare(strict_types=1);

namespace Tests\Unit;

use IntentPHP\Guard\AI\OpenAiClient;
use PHPUnit\Framework\TestCase;

class OpenAiClientTest extends TestCase
{
    public function test_is_available_returns_false_when_key_is_empty(): void
    {
        $client = new OpenAiClient(
            baseUrl: 'https://api.openai.com/v1',
            apiKey: '',
            model: 'gpt-4.1-mini',
            timeout: 30,
            maxTokens: 1024,
        );

        $this->assertFalse($client->isAvailable());
    }

    public function test_is_available_returns_true_when_key_is_set(): void
    {
        $client = new OpenAiClient(
            baseUrl: 'https://api.openai.com/v1',
            apiKey: 'sk-test-key-123',
            model: 'gpt-4.1-mini',
            timeout: 30,
            maxTokens: 1024,
        );

        $this->assertTrue($client->isAvailable());
    }

    public function test_generate_returns_unavailable_message_when_no_key(): void
    {
        $client = new OpenAiClient(
            baseUrl: 'https://api.openai.com/v1',
            apiKey: '',
            model: 'gpt-4.1-mini',
            timeout: 30,
            maxTokens: 1024,
        );

        $result = $client->generate('test prompt');

        $this->assertStringContainsString('AI unavailable', $result);
    }
}
