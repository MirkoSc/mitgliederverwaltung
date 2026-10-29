<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * Where a run of /install stands (docs/spec/04-installation-und-update.md
 * section 2). Kept in the session; every step is its own request.
 *
 *   Datenbank ─► Art ─┬─► Admin ─► Schluessel ─► Mail ─► config.php
 *                     └─► Wiederherstellung ─► WiederherstellungLaeuft ─► config.php
 */
enum InstallSchritt: string
{
    /** 1. database credentials and connection test */
    case Datenbank = 'datenbank';

    /** 3. fresh installation or restore (the server key of 2. exists now) */
    case Art = 'art';

    /** 4. migrations and the first admin */
    case Admin = 'admin';

    /** 4. the server key, shown once to be printed */
    case Schluessel = 'schluessel';

    /** 5. mail settings and a test mail, skippable */
    case Mail = 'mail';

    /** restore: backup file and its key */
    case Wiederherstellung = 'wiederherstellung';

    /** restore: the step chain is running (public/js/install.js) */
    case WiederherstellungLaeuft = 'wiederherstellung-laeuft';

    /**
     * The steps the progress list names, in order - one list per path.
     *
     * @return list<array{self, string}>
     */
    public function weg(): array
    {
        $anfang = [
            [self::Datenbank, 'Datenbank'],
            [self::Art, 'Art der Installation'],
        ];

        return match ($this) {
            self::Wiederherstellung, self::WiederherstellungLaeuft => [
                ...$anfang,
                [self::Wiederherstellung, 'Backup auswählen'],
                [self::WiederherstellungLaeuft, 'Backup einspielen'],
            ],
            default => [
                ...$anfang,
                [self::Admin, 'Erster Admin'],
                [self::Schluessel, 'Sicherungsschlüssel'],
                [self::Mail, 'Mailversand'],
            ],
        };
    }
}
