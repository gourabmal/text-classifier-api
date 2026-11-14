<?php

namespace App\Contracts;

use App\Data\AiClassification;
use App\Exceptions\AiServiceException;

/**
 * An AI provider that can label a piece of text.
 * Swap implementations (Gemini, OpenAI, Claude, a mock...) in AppServiceProvider.
 */
interface TextClassifier
{
    /**
     * @throws AiServiceException when the provider is unreachable, misconfigured, or returns garbage.
     */
    public function classify(string $text): AiClassification;
}
