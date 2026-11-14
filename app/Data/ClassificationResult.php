<?php

namespace App\Data;

use App\Enums\Category;

/**
 * Final, normalised result returned to API clients.
 */
final readonly class ClassificationResult
{
    public function __construct(
        public Category $category,
        public float $confidence,
        public string $confidenceSource,
        public string $model,
        public int $latencyMs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'confidence' => $this->confidence,
            'confidence_source' => $this->confidenceSource,
            'model' => $this->model,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
