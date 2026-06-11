<?php

namespace App\Enums;

enum OrganisationRole: string
{
    case Administrator = 'administrator';
    case LeadDeveloper = 'lead_developer';
    case ClientProjectManager = 'client_project_manager';
    case Developer = 'developer';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::LeadDeveloper => 'Lead Developer',
            self::ClientProjectManager => 'Client / Project Manager',
            self::Developer => 'Developer',
        };
    }

    public function canManageOrganisation(): bool
    {
        return $this === self::Administrator;
    }

    public function canManageAccess(): bool
    {
        return in_array($this, [
            self::Administrator,
            self::LeadDeveloper,
            self::ClientProjectManager,
        ], true);
    }

    public function canManageTaskScope(): bool
    {
        return in_array($this, [
            self::Administrator,
            self::LeadDeveloper,
            self::ClientProjectManager,
        ], true);
    }

    public function canManageTechnicalSettings(): bool
    {
        return in_array($this, [
            self::Administrator,
            self::LeadDeveloper,
            self::Developer,
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
