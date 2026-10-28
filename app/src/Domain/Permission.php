<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Every right the application checks (docs/spec/02-sicherheit.md section 6).
 * Checked server side per action: a route declares the one it needs
 * (App\Http\Zugriff) and App\Http\LoginGuard enforces it; hiding a link is
 * never the check (CLAUDE.md section 4).
 *
 * COMPLETE on purpose (docs/spec/05-entwicklung.md section 6): later issues
 * use these rights and do not add new ones - a missing one is argued for in
 * the pull request. Which role holds which right is App\Domain\Rolle.
 */
enum Permission: string
{
    /** Search members, open one, see its history (07 section 9). */
    case MitgliederSehen = 'mitglieder.sehen';

    /**
     * Create and edit members (including IBAN, departments, fee type),
     * restore a version, maintain families, note a proof or an honour.
     */
    case MitgliederBearbeiten = 'mitglieder.bearbeiten';

    /** Delete a member or a proof (07 section 9: admin only). */
    case MitgliederLoeschen = 'mitglieder.loeschen';

    /** The lists and their exports (09); fee columns need BeitraegeVerwalten. */
    case ListenSehen = 'listen.sehen';

    /**
     * Everything in 08: annual fees, paid status, the tab "Beiträge", direct
     * debits and the debit file, the list "Selbst kassieren", fee columns in
     * the full export.
     */
    case BeitraegeVerwalten = 'beitraege.verwalten';

    /**
     * The settings page: club data, departments, fee types, functions,
     * birthdays, honour levels (11, 07), mail and legal texts.
     */
    case AdminEinstellungen = 'admin.einstellungen';

    /** Invite, lock, delete users, change their role (02 section 5). */
    case AdminBenutzer = 'admin.benutzer';

    /** Initial import and later reconciliation (10). */
    case AdminImport = 'admin.import';

    /** Update, backup and restore, system check, integrity check, maintenance (04). */
    case AdminSystem = 'admin.system';

    /** The audit log (02 section 8). */
    case AdminAudit = 'admin.audit';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::MitgliederSehen => 'Mitglieder suchen und ansehen',
            self::MitgliederBearbeiten => 'Mitglieder anlegen und bearbeiten',
            self::MitgliederLoeschen => 'Mitglieder und Nachweise löschen',
            self::ListenSehen => 'Listen ansehen und exportieren',
            self::BeitraegeVerwalten => 'Beiträge und Einzug',
            self::AdminEinstellungen => 'Einstellungen',
            self::AdminBenutzer => 'Benutzer verwalten',
            self::AdminImport => 'Daten importieren',
            self::AdminSystem => 'System (Update, Backup, Systemcheck)',
            self::AdminAudit => 'Audit-Log ansehen',
        };
    }

    /**
     * The `admin.*` rights - the only ones a route under /admin may declare
     * (tests/Http/RoutenZugriffTest.php).
     */
    public function istAdmin(): bool
    {
        return str_starts_with($this->value, 'admin.');
    }
}
