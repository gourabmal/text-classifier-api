<?php

namespace App\Services;

use App\Contracts\TextClassifier;
use App\Data\ClassificationResult;
use App\Enums\Category;
use Illuminate\Support\Facades\Log;

/**
 * Business logic: asks the AI provider, then turns its raw answer into a
 * trustworthy result (known category, confidence in [0, 1]).
 */
class ClassificationService
{
    public function __construct(private readonly TextClassifier $classifier) {}

    public function classify(string $text): ClassificationResult
    {
        $startedAt = hrtime(true);
        $raw = $this->classifier->classify($text);
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $category = Category::fromLoose($raw->category);
        $confidence = $raw->confidence;
        $source = 'model';

        // The model returned a category we don't know: treat it as Other and
        // don't trust its confidence, which was about a different label.
        if ($category === null) {
            $category = Category::Other;
            $confidence = null;
        }

        if ($confidence === null || ! is_finite($confidence)) {
            $confidence = $category->fallbackConfidence();
            $source = 'fallback';
        }

        $result = new ClassificationResult(
            category: $category,
            confidence: $this->normaliseConfidence($confidence),
            confidenceSource: $source,
            model: $raw->model,
            latencyMs: $latencyMs,
        );

        Log::info('Text classified', [
            'category' => $result->category->value,
            'confidence' => $result->confidence,
            'confidence_source' => $result->confidenceSource,
            'model' => $result->model,
            'latency_ms' => $result->latencyMs,
            'text_length' => mb_strlen($text),
        ]);

        return $result;
    }

    private function normaliseConfidence(float $value): float
    {
        // Some models answer in percent (e.g. 92) despite the schema.
        if ($value > 1 && $value <= 100) {
            $value /= 100;
        }

        return round(max(0.0, min(1.0, $value)), 2);
    }
}
