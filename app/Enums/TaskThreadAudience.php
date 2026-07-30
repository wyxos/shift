<?php

namespace App\Enums;

enum TaskThreadAudience: string
{
    case All = 'all';
    case Team = 'team';

    public static function fromStoredType(string $type): self
    {
        return $type === 'internal' ? self::Team : self::All;
    }

    public function storedType(): string
    {
        return match ($this) {
            self::All => 'external',
            self::Team => 'internal',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All',
            self::Team => 'Team',
        };
    }
}
