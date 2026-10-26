<?php

namespace App;

class TeamPermissions
{
    public const TABS = ['dashboard' => 'Home', 'sheets' => 'Sheets', 'books' => 'Books', 'lapsing' => 'Lapsing (PPE)', 'masters' => 'Masters', 'reports' => 'Financial Reports', 'tax-reports' => 'Tax Reports', 'settings' => 'Settings'];

    /** @return array<string, array{label: string, description: string, role: string, tabs: list<string>}> */
    public static function templates(): array
    {
        return [
            'accountant' => ['label' => 'Accountant', 'description' => 'Work across the accounting modules. Company registration and team management remain owner-only.', 'role' => 'accountant', 'tabs' => array_keys(self::TABS)],
            'bookkeeper' => ['label' => 'Bookkeeper', 'description' => 'Enter transactions and work with Masters, Books and PPE.', 'role' => 'staff', 'tabs' => ['dashboard', 'sheets', 'books', 'masters', 'lapsing']],
            'reports' => ['label' => 'Reporting staff', 'description' => 'Open Books and reporting modules. This controls module access, not read-only access within a module.', 'role' => 'staff', 'tabs' => ['dashboard', 'books', 'reports', 'tax-reports']],
        ];
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
