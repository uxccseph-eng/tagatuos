<?php

namespace App;

class TeamPermissions
{
    public const MEMBER_ROLES = ['manager', 'accountant', 'staff'];

    public const TABS = ['dashboard' => 'Home', 'sheets' => 'Sheets', 'books' => 'Books', 'lapsing' => 'Lapsing (PPE)', 'masters' => 'Masters', 'reports' => 'Financial Reports', 'tax-reports' => 'Tax Reports', 'settings' => 'Settings'];

    /** @return array<string, array{label: string, description: string, role: string, tabs: list<string>}> */
    public static function templates(): array
    {
        return [
            'manager' => ['label' => 'Manager', 'description' => 'Assign monthly closing work, review, approve completion and reopen months. Company ownership and team management remain owner-only.', 'role' => 'manager', 'tabs' => array_keys(self::TABS)],
            'accountant' => ['label' => 'Accountant', 'description' => 'Work across the accounting modules and edit assigned book details when Settings access is enabled. Ownership and team management remain owner-only.', 'role' => 'accountant', 'tabs' => array_keys(self::TABS)],
            'bookkeeper' => ['label' => 'Bookkeeper', 'description' => 'Enter transactions and work with Masters, Books and PPE.', 'role' => 'staff', 'tabs' => ['dashboard', 'sheets', 'books', 'masters', 'lapsing']],
            'reports' => ['label' => 'Reporting staff', 'description' => 'Open Books and reporting modules. This controls module access, not read-only access within a module.', 'role' => 'staff', 'tabs' => ['dashboard', 'books', 'reports', 'tax-reports']],
        ];
    }

    public static function canApproveClosing(string $role): bool
    {
        return in_array($role, ['owner', 'manager'], true);
    }

    public static function canEditBookSettings(string $role, mixed $stored): bool
    {
        return in_array($role, ['owner', 'manager', 'accountant'], true)
            && in_array('settings', self::allowed($role, $stored), true);
    }

    /** @return list<string> */
    public static function allowed(string $role, mixed $stored): array
    {
        if ($role === 'owner' || $stored === null) {
            return array_keys(self::TABS);
        }
        $tabs = is_array($stored) ? $stored : json_decode((string) $stored, true);

        return array_values(array_unique(['dashboard', ...array_intersect(is_array($tabs) ? $tabs : [], array_keys(self::TABS))]));
    }
}
