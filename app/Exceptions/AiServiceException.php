<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * Any failure talking to the AI provider. Rendered as a JSON error automatically.
 */
class AiServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self('AI provider is not configured. Set GEMINI_API_KEY in your .env file.', 'ai_not_configured', 500);
    }

    public static function timeout(?Throwable $previous = null): self
    {
        return new self('AI provider did not respond in time.', 'ai_timeout', 504, $previous);
    }

    public static function upstream(int $providerStatus): self
    {
        return new self("AI provider returned an error (HTTP {$providerStatus}).", 'ai_upstream_error', 502);
    }

    public static function invalidResponse(string $detail): self
    {
        return new self("AI provider returned an unusable response: {$detail}", 'ai_invalid_response', 502);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
        ], $this->status);
    }
}
