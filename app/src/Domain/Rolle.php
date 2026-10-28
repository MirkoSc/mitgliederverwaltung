<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The two fixed roles (docs/spec/02-sicherheit.md section 6) - no role
 * editor, no scopes: role to rights is this one `match`.
 *
 * The values are what `user.rolle` stores, so they are part of the data
 * format.
 */
enum Rolle: string
{
    case Admin = 'admin';
    case Vorstand = 'vorstand';

    /** @return list<Permission> */
    public function rechte(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            // Members: search, view, create, edit (also IBAN, department,
            // fee type); lists and exports. Not: annual fees, direct debit,
            // paid status, debit file, settings, users, system.
            self::Vorstand => [
                Permission::MitgliederSehen,
                Permission::MitgliederBearbeiten,
                Permission::ListenSehen,
            ],
        };
    }

    public function darf(Permission $recht): bool
    {
        return in_array($recht, $this->rechte(), true);
    }

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Vorstand => 'Vorstand',
        };
    }
}
