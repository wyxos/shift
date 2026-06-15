<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in-progress';
    case AwaitingFeedback = 'awaiting-feedback';
    case OnHold = 'on-hold';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In Progress',
            self::AwaitingFeedback => 'Awaiting Feedback',
            self::OnHold => 'On Hold',
            self::Completed => 'Completed',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function defaultOpenValues(): array
    {
        return [
            self::Pending->value,
            self::InProgress->value,
            self::AwaitingFeedback->value,
            self::OnHold->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
