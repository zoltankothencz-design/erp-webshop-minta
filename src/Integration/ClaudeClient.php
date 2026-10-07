<?php
declare(strict_types=1);

namespace App\Integration;

/** Az Anthropic Messages API kliense (POST /v1/messages). A kulcs a .env-ből jön, soha nem a kódból. */
final class ClaudeClient implements AiClient
{
    public function __construct(
        private HttpClient $http,
        private string $baseUrl,
        private string $apiKey,
        private string $model,
    ) {
        if ($apiKey === '') {
            throw new \RuntimeException('Az ANTHROPIC_API_KEY nincs beállítva (.env). Használd az AI_DRIVER=mock értéket kulcs nélkül.');
        }
    }

    public function complete(string $system, string $user, int $maxTokens = 400): string
    {
        $res = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/v1/messages', [
            'headers' => ['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01'],
            'json' => [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ],
        ]);
        foreach (($res['json']['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                return (string) $block['text'];
            }
        }
        throw new IntegrationException('Az AI válasza nem tartalmazott szöveget.');
    }
}
