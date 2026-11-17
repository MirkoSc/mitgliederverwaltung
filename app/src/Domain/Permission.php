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
 * the pull request and in 02 section 6 (#87 added beitraege.sehen for the
 * role "Nur lesen"; the role "Verwaltung" added admin.verein,
 * bankdaten.sehen and beitraege.auswerten). Which role holds which right is
 * App\Domain\Rolle.
 */
enum Permission: string
{
    /** Search members, open one, see its history (07 section 9). */
    case MitgliederSehen = 'mitglieder.sehen';

    /**
     * Create and edit members (including IBAN, departments, fee type),
     * restore a version, maintain families, note a proof or an honour.
     * Whoever holds it also holds BankdatenSehen - the form shows the IBAN
     * (tests/Domain/RolleTest.php).
     */
    case MitgliederBearbeiten = 'mitglieder.bearbeiten';

    /**
     * See the bank details of members: IBAN, BIC (and the bank named by
     * it), Kontoinhaber, Mandatsreferenz, Mandatsdatum - in the tab
     * "Daten", the history, the search, the lists and every export. Without
     * it ("Nur lesen": whoever congratulates or visits members) they are
     * left out everywhere; fee type, amounts and paid status are
     * BeitraegeSehen.
     */
    case BankdatenSehen = 'bankdaten.sehen';

    /** Delete a member or a proof (07 section 9: admin only). */
    case MitgliederLoeschen = 'mitglieder.loeschen';

    /** The lists and their exports (09); fee columns need BeitraegeSehen. */
    case ListenSehen = 'listen.sehen';

    /**
     * Read the fee data of members without changing anything: the tab
     * "Beiträge" (paid status, amounts, notes), fee columns in the lists,
     * the list "Selbst kassieren" (issue #87). Not the page "Beiträge",
     * direct debits or the debit file - those are BeitraegeVerwalten.
     */
    case BeitraegeSehen = 'beitraege.sehen';

    /**
     * Everything in 08 that changes or collects: the page "Beiträge" (create
     * and set annual fees, paid status, notes), direct debits and the debit
     * file. Reading fee data is BeitraegeSehen.
     */
    case BeitraegeVerwalten = 'beitraege.verwalten';

    /**
     * The tab "Beiträge" of the page "Statistik" (09 section 7): what a
     * year's fees bring in, figures only. Not the page "Beiträge".
     */
    case BeitraegeAuswerten = 'beitraege.auswerten';

    /**
     * The settings page (/admin) with the business settings: club data
     * with bank account, creditor id, association numbers and logo (11),
     * departments, fee types, functions, round birthdays, honour levels
     * (07). The only admin.* right of the role "Verwaltung".
     */
    case AdminVerein = 'admin.verein';

    /**
     * The technical settings on the same page: mail and legal texts
     * (Impressum, Datenschutz), and the design system page. Admin only.
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
            self::BankdatenSehen => 'Bankverbindung und Mandat der Mitglieder ansehen',
            self::MitgliederLoeschen => 'Mitglieder und Nachweise löschen',
            self::ListenSehen => 'Listen ansehen und exportieren',
            self::BeitraegeSehen => 'Bezahlt-Status und Beitragsangaben ansehen',
            self::BeitraegeVerwalten => 'Beiträge und Einzug',
            self::BeitraegeAuswerten => 'Statistik der Beiträge ansehen',
            self::AdminVerein => 'Vereinsdaten, Abteilungen, Beitragsarten und weitere Vereinseinstellungen',
            self::AdminEinstellungen => 'Mail und Rechtstexte',
            self::AdminBenutzer => 'Benutzer verwalten',
            self::AdminImport => 'Daten importieren',
            self::AdminSystem => 'System (Update, Backup, Systemcheck)',
            self::AdminAudit => 'Audit-Log ansehen',
        };
    }

    /**
     * The `admin.*` rights - the only ones a route under /admin may declare
     * (tests/Http/RoutenZugriffTest.php). Vorstand and "Nur lesen" hold
     * none, "Verwaltung" holds admin.verein only.
     */
    public function istAdmin(): bool
    {
        return str_starts_with($this->value, 'admin.');
    }
}
