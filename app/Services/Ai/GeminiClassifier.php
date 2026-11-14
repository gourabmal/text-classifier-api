<?php

namespace App\Services\Ai;

use App\Contracts\TextClassifier;
use App\Data\AiClassification;
use App\Exceptions\AiServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini implementation using the generateContent REST endpoint
 * with structured JSON output.
 */
class GeminiClassifier implements TextClassifier
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly string $baseUrl,
    ) {}

    public function classify(string $text): AiClassification
    {
        if ($this->apiKey === '') {
            throw AiServiceException::notConfigured();
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->post($this->endpoint(), $this->payload($text));
        } catch (ConnectionException $e) {
            throw AiServiceException::timeout($e);
        }

        if ($response->failed()) {
            throw AiServiceException::upstream($response->status());
        }

        return $this->parse($response->json() ?? []);
    }

    private function endpoint(): string
    {
        return rtrim($this->baseUrl, '/')."/models/{$this->model}:generateContent";
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $text): array
    {
        return [
            'systemInstruction' => [
                'parts' => [['text' => ClassificationPrompt::systemInstruction()]],
            ],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => ClassificationPrompt::userMessage($text)]]],
            ],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => ClassificationPrompt::responseSchema(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function parse(array $body): AiClassification
    {
        if ($reason = data_get($body, 'promptFeedback.blockReason')) {
            throw AiServiceException::invalidResponse("input was blocked ({$reason}).");
        }

        $text = collect(data_get($body, 'candidates.0.content.parts', []))
            ->reject(fn ($part) => ($part['thought'] ?? false) === true)
            ->pluck('text')
            ->implode('');

        $decoded = json_decode(trim($text), true);

        if (! is_array($decoded)) {
            throw AiServiceException::invalidResponse('expected a JSON object.');
        }

        $category = $decoded['category'] ?? null;
        $confidence = $decoded['confidence'] ?? null;

        return new AiClassification(
            category: is_string($category) ? $category : null,
            confidence: is_numeric($confidence) ? (float) $confidence : null,
            model: (string) data_get($body, 'modelVersion', $this->model),
        );
    }
}
