<?php

namespace App\Data;

/**
 * Raw, unvalidated output from the AI provider.
 * Values may be missing or out of range; ClassificationService normalises them.
 */
final readonly class AiClassification
{
    public function __construct(
        public ?string $category,
        public ?float $confidence,
        public string $model,
    ) {}
}
