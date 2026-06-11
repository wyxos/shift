<?php

namespace App\Enums;

enum ExternalUserRole: string
{
    case Owner = 'owner';
    case ClientDeveloper = 'client_developer';
    case ShiftLeadDeveloper = 'shift_lead_developer';
    case ShiftDeveloper = 'shift_developer';
    case User = 'user';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::ClientDeveloper => 'Client Developer',
            self::ShiftLeadDeveloper => 'SHIFT Lead Developer',
            self::ShiftDeveloper => 'SHIFT Developer',
            self::User => 'User',
            self::Guest => 'Guest',
        };
    }

    public function canSubmitRequirements(): bool
    {
        return in_array($this, [
            self::Owner,
            self::ClientDeveloper,
            self::ShiftLeadDeveloper,
            self::ShiftDeveloper,
        ], true);
    }

    public function canManageExternalRoles(): bool
    {
        return in_array($this, [
            self::ShiftLeadDeveloper,
            self::ShiftDeveloper,
        ], true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
