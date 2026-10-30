<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The three fixed roles (docs/spec/02-sicherheit.md section 6) - no role
 * editor, no scopes: role to rights is this one `match`.
 *
 * The values are what `user.rolle` stores, so they are part of the data
 * format.
 */
enum Rolle: string
{
    case Admin = 'admin';
    case Vorstand = 'vorstand';
    case Lesend = 'lesend';

    /** @return list<Permission> */
    public function rechte(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            // Members: search, view, create, edit (also IBAN, department,
            // fee type); lists and exports; whether a member has paid
            // (read only, like "Nur lesen"). Not: the page "Beiträge"
            // (annual fees, direct debit, debit file), settings, users,
            // system.
            self::Vorstand => [
                Permission::MitgliederSehen,
                Permission::MitgliederBearbeiten,
                Permission::ListenSehen,
                Permission::BeitraegeSehen,
            ],
            // Read only (issue #87): members, families, proofs, history,
            // lists with exports, and whether a member has paid. Changes
            // nothing; no page "Beiträge", no direct debit, no /admin.
            self::Lesend => [
                Permission::MitgliederSehen,
                Permission::ListenSehen,
                Permission::BeitraegeSehen,
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
            self::Lesend => 'Nur lesen',
        };
    }
}
