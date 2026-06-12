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

    public function hierarchyLevel(): int
    {
        return match ($this) {
            self::Administrator => 100,
            self::LeadDeveloper, self::ClientProjectManager => 50,
            self::Developer => 10,
        };
    }

    public function canManageRole(self $targetRole): bool
    {
        if ($this === self::Administrator) {
            return true;
        }

        return $targetRole->hierarchyLevel() < $this->hierarchyLevel();
    }

    public function canAssignRole(self $role): bool
    {
        if ($this === self::Administrator) {
            return true;
        }

        return $role->hierarchyLevel() < $this->hierarchyLevel();
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

    public function canManageExternalRoles(): bool
    {
        return in_array($this, [
            self::Administrator,
            self::LeadDeveloper,
            self::ClientProjectManager,
        ], true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    /**
     * @return array<int, self>
     */
    public static function assignableBy(?self $role): array
    {
        if ($role === null) {
            return [];
        }

        return array_values(array_filter(
            self::cases(),
            static fn (self $candidate) => $role->canAssignRole($candidate),
        ));
    }
}
