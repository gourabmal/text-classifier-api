<?php

namespace App\Enums;

enum Category: string
{
    case Complaint = 'Complaint';
    case Query = 'Query';
    case Feedback = 'Feedback';
    case Other = 'Other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Case-insensitive lookup; returns null for anything unrecognised.
     */
    public static function fromLoose(?string $value): ?self
    {
        $value = trim((string) $value);

        foreach (self::cases() as $case) {
            if (strcasecmp($case->value, $value) === 0) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Confidence used when the AI model does not return a usable score.
     * Specific categories get a moderate default; "Other" is a catch-all, so it gets less.
     */
    public function fallbackConfidence(): float
    {
        return match ($this) {
            self::Complaint, self::Query, self::Feedback => 0.70,
            self::Other => 0.50,
        };
    }
}
