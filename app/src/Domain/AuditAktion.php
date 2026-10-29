<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What the audit log records (docs/spec/02-sicherheit.md section 8). The
 * values are stored in `audit_log.action`, so they are part of the data
 * format. Issues that produce an event use their case here; a missing one
 * is added in the same pull request.
 */
enum AuditAktion: string
{
    case AnmeldungErfolgreich = 'anmeldung.erfolgreich';
    case AnmeldungFehlgeschlagen = 'anmeldung.fehlgeschlagen';
    case Abmeldung = 'abmeldung';
    case BenutzerAngelegt = 'benutzer.angelegt';
    case BenutzerGeaendert = 'benutzer.geaendert';
    case BenutzerGesperrt = 'benutzer.gesperrt';
    case BenutzerGeloescht = 'benutzer.geloescht';
    case RolleGeaendert = 'rolle.geaendert';
    case ZweiterFaktorGeaendert = 'zweiter_faktor.geaendert';
    case PasswortGeaendert = 'passwort.geaendert';
    case PasswortZurueckgesetzt = 'passwort.zurueckgesetzt';
    case BackupErstellt = 'backup.erstellt';
    case BackupHeruntergeladen = 'backup.heruntergeladen';
    case UpdateDurchgefuehrt = 'update.durchgefuehrt';
    case UpdateZurueckgerollt = 'update.zurueckgerollt';
    case WartungAufgehoben = 'wartung.aufgehoben';
    case RestoreDurchgefuehrt = 'restore.durchgefuehrt';
    case ExportErzeugt = 'export.erzeugt';
    case ImportDurchgefuehrt = 'import.durchgefuehrt';
    case BestandGeleert = 'bestand.geleert';
    case AbgleichDurchgefuehrt = 'abgleich.durchgefuehrt';
    case EinzugErzeugt = 'einzug.erzeugt';
    case EinzugBestaetigt = 'einzug.bestaetigt';
    case EinzugVerworfen = 'einzug.verworfen';
    case EinzugDateiHeruntergeladen = 'einzug.datei_heruntergeladen';
    case VersionZurueckgeholt = 'mitglied.version_zurueckgeholt';
    case MitgliedGeloescht = 'mitglied.geloescht';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::AnmeldungErfolgreich => 'Anmeldung',
            self::AnmeldungFehlgeschlagen => 'Anmeldung fehlgeschlagen',
            self::ZweiterFaktorGeaendert => 'Zweiter Faktor geändert',
            self::PasswortGeaendert => 'Passwort geändert',
            self::PasswortZurueckgesetzt => 'Passwort zurückgesetzt',
            self::Abmeldung => 'Abmeldung',
            self::BenutzerAngelegt => 'Benutzer angelegt',
            self::BenutzerGeaendert => 'Benutzer geändert',
            self::BenutzerGesperrt => 'Benutzer gesperrt',
            self::BenutzerGeloescht => 'Benutzer gelöscht',
            self::RolleGeaendert => 'Rolle geändert',
            self::BackupErstellt => 'Backup erstellt',
            self::BackupHeruntergeladen => 'Backup heruntergeladen',
            self::UpdateDurchgefuehrt => 'Update durchgeführt',
            self::UpdateZurueckgerollt => 'Update zurückgerollt',
            self::WartungAufgehoben => 'Wartungsmodus aufgehoben',
            self::RestoreDurchgefuehrt => 'Backup eingespielt',
            self::ExportErzeugt => 'Export erzeugt',
            self::ImportDurchgefuehrt => 'Import durchgeführt',
            self::BestandGeleert => 'Mitgliederbestand geleert',
            self::AbgleichDurchgefuehrt => 'Abgleich durchgeführt',
            self::EinzugErzeugt => 'Einzug erzeugt',
            self::EinzugBestaetigt => 'Einzug bestätigt',
            self::EinzugVerworfen => 'Einzug verworfen',
            self::EinzugDateiHeruntergeladen => 'Lastschriftdatei heruntergeladen',
            self::VersionZurueckgeholt => 'Version zurückgeholt',
            self::MitgliedGeloescht => 'Mitglied gelöscht',
        };
    }
}
