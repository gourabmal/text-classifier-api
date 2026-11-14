<?php

namespace Tests\Unit;

use App\Enums\Category;
use PHPUnit\Framework\TestCase;

class CategoryTest extends TestCase
{
    public function test_from_loose_matches_case_insensitively_and_trims(): void
    {
        $this->assertSame(Category::Complaint, Category::fromLoose(' complaint '));
        $this->assertSame(Category::Query, Category::fromLoose('QUERY'));
    }

    public function test_from_loose_returns_null_for_unknown_values(): void
    {
        $this->assertNull(Category::fromLoose('Spam'));
        $this->assertNull(Category::fromLoose(null));
    }

    public function test_fallback_confidence_mapping(): void
    {
        $this->assertSame(0.70, Category::Complaint->fallbackConfidence());
        $this->assertSame(0.50, Category::Other->fallbackConfidence());
    }
}
