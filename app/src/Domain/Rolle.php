<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The four fixed roles (docs/spec/02-sicherheit.md section 6) - no role
 * editor, no scopes: role to rights is this one `match`. The order of the
 * cases is the order everywhere a role is chosen or listed.
 *
 * The values are what `user.rolle` stores, so they are part of the data
 * format. A value an older release does not know (e.g. "verwaltung" after a
 * downgrade) is read as "Nur lesen" (App\Repository\BenutzerRepository).
 */
enum Rolle: string
{
    case Admin = 'admin';
    case Verwaltung = 'verwaltung';
    case Vorstand = 'vorstand';
    case Lesend = 'lesend';

    /** @return list<Permission> */
    public function rechte(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            // Everything of the Vorstand, plus the page "Beiträge" with
            // direct debit and debit file, and the business settings
            // (club data, departments, fee types, functions, birthdays,
            // honour levels) on the settings page. Not: deleting members,
            // import, users, mail, legal texts, system, audit log.
            self::Verwaltung => [
                Permission::MitgliederSehen,
                Permission::MitgliederBearbeiten,
                Permission::BankdatenSehen,
                Permission::ListenSehen,
                Permission::BeitraegeSehen,
                Permission::BeitraegeVerwalten,
                Permission::BeitraegeAuswerten,
                Permission::AdminVerein,
            ],
            // Members: search, view, create, edit (also IBAN, department,
            // fee type); lists and exports; whether a member has paid
            // (read only, like "Nur lesen"); the fee statistics. Not: the
            // page "Beiträge" (annual fees, direct debit, debit file),
            // settings, users, system.
            self::Vorstand => [
                Permission::MitgliederSehen,
                Permission::MitgliederBearbeiten,
                Permission::BankdatenSehen,
                Permission::ListenSehen,
                Permission::BeitraegeSehen,
                Permission::BeitraegeAuswerten,
            ],
            // Read only (issue #87): members, families, proofs, history,
            // lists with exports, and whether a member has paid. Changes
            // nothing; no bank details of members, no fee statistics, no
            // page "Beiträge", no direct debit, no /admin.
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
            self::Verwaltung => 'Verwaltung',
            self::Vorstand => 'Vorstand',
            self::Lesend => 'Nur lesen',
        };
    }
}
