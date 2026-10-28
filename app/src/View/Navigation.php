<?php

declare(strict_types=1);

namespace App\View;

use App\Domain\Konto;
use App\Domain\Permission;
use App\Http\HttpMethod;

/**
 * The whole navigation of the application (docs/spec/06-oberflaeche.md
 * section 2): five areas and no more - Mitglieder, Listen, Beiträge,
 * Einstellungen and the user menu.
 *
 * Complete from issue #1 on (docs/spec/05-entwicklung.md section 6): later
 * issues register the routes behind these entries in their own files and
 * do not edit this one. An entry whose route does not exist yet renders as
 * inactive (App\View\NavItem).
 */
final class Navigation
{
    public const string MITGLIEDER = '/app/mitglieder';
    public const string LISTEN = '/app/listen';
    public const string BEITRAEGE = '/app/beitraege';
    public const string EINSTELLUNGEN = '/admin';

    public const string PASSWORT = '/app/konto/passwort';
    public const string ZWEITER_FAKTOR = '/app/konto/zweiter-faktor';
    public const string BARRIEREFREI = '/app/konto/barrierefrei';
    public const string ABMELDEN = '/abmelden';

    /**
     * The main navigation, in display order. Beiträge and Einstellungen
     * are for the admin only (06 section 2) - their rights say so.
     *
     * @return list<NavItem>
     */
    public static function haupt(): array
    {
        return [
            new NavItem('Mitglieder', self::MITGLIEDER, Permission::MitgliederSehen),
            new NavItem('Listen', self::LISTEN, Permission::ListenSehen),
            new NavItem('Beiträge', self::BEITRAEGE, Permission::BeitraegeVerwalten),
            new NavItem('Einstellungen', self::EINSTELLUNGEN, Permission::AdminEinstellungen),
        ];
    }

    /**
     * The user menu (06 section 2): own password, second factor, the
     * Barrierefrei-Modus switch and logging out - things every account does
     * for itself, so none of them needs a right.
     *
     * @return list<NavItem>
     */
    public static function benutzermenue(Konto $konto): array
    {
        return [
            new NavItem('Passwort ändern', self::PASSWORT),
            new NavItem('Zweiter Faktor', self::ZWEITER_FAKTOR),
            new NavItem(
                $konto->barrierefrei ? 'Barrierefrei-Modus ausschalten' : 'Barrierefrei-Modus einschalten',
                self::BARRIEREFREI,
                methode: HttpMethod::Post,
            ),
            new NavItem('Abmelden', self::ABMELDEN, methode: HttpMethod::Post),
        ];
    }

    /**
     * Where an account lands after login and on "/": the first main entry
     * it may open.
     */
    public static function startseiteFuer(Konto $konto): string
    {
        foreach (self::haupt() as $eintrag) {
            if ($eintrag->sichtbarFuer($konto)) {
                return $eintrag->href;
            }
        }

        return self::MITGLIEDER;
    }
}
