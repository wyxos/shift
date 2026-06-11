<?php

namespace App\Enums;

enum RequirementStatus: string
{
    case Submitted = 'submitted';
    case InReview = 'in-review';
    case AwaitingFeedback = 'awaiting-feedback';
    case ReadyToFinalize = 'ready-to-finalize';
    case Parked = 'parked';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::InReview => 'In Review',
            self::AwaitingFeedback => 'Awaiting Feedback',
            self::ReadyToFinalize => 'Ready',
            self::Parked => 'Parked',
            self::Declined => 'Declined',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
