<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The small Markdown subset of the legal texts (docs/spec/06 - a text the
 * association writes itself, not a document format):
 *
 * - "# " and "## " start a heading (h2, the page title is the h1), "### " an h3
 * - lines starting with "- " form a bulleted list
 * - a blank line ends a paragraph; the lines of a paragraph are joined
 * - **bold**
 * - [text](url) for http(s)://, mailto: and site-relative "/" addresses only
 *
 * Everything else is plain text. The source is escaped first, so the output
 * is HTML-safe whatever the text contains - a script tag shows as text, and
 * a link with another scheme (javascript:) stays text, too. Pure function,
 * no dependency: the project takes no Markdown library for this.
 */
final class Markdown
{
    private const string LINK = '/\[([^\]]+)\]\(((?:https?:\/\/|mailto:|\/)[^\s)"]+)\)/u';

    public static function html(string $text): string
    {
        $ausgabe = [];
        $absatz = [];
        $liste = [];

        $schliesseAbsatz = static function () use (&$absatz, &$ausgabe): void {
            if ($absatz !== []) {
                $ausgabe[] = '<p>' . self::inline(implode(' ', $absatz)) . '</p>';
                $absatz = [];
            }
        };
        $schliesseListe = static function () use (&$liste, &$ausgabe): void {
            if ($liste !== []) {
                $ausgabe[] = '<ul>' . implode('', array_map(
                    static fn(string $punkt): string => '<li>' . self::inline($punkt) . '</li>',
                    $liste,
                )) . '</ul>';
                $liste = [];
            }
        };

        $zeilen = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        foreach ($zeilen as $zeile) {
            $zeile = rtrim($zeile);

            if ($zeile === '') {
                $schliesseAbsatz();
                $schliesseListe();
            } elseif (preg_match('/^(#{1,3})\s+(.+)$/', $zeile, $treffer) === 1) {
                $schliesseAbsatz();
                $schliesseListe();
                $stufe = strlen($treffer[1]) === 3 ? 'h3' : 'h2';
                $ausgabe[] = '<' . $stufe . '>' . self::inline($treffer[2]) . '</' . $stufe . '>';
            } elseif (str_starts_with($zeile, '- ')) {
                $schliesseAbsatz();
                $liste[] = substr($zeile, 2);
            } else {
                $schliesseListe();
                $absatz[] = $zeile;
            }
        }
        $schliesseAbsatz();
        $schliesseListe();

        return implode("\n", $ausgabe);
    }

    /** Escapes first, then marks up: the markers themselves are not escaped characters. */
    private static function inline(string $zeile): string
    {
        $sicher = htmlspecialchars($zeile, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fett = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $sicher) ?? $sicher;

        return preg_replace_callback(
            self::LINK,
            static fn(array $t): string => '<a href="' . $t[2] . '">' . $t[1] . '</a>',
            $fett,
        ) ?? $fett;
    }
}
