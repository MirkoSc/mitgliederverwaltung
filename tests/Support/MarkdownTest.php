<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Markdown;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    public function testHeadingsParagraphsAndLists(): void
    {
        $html = Markdown::html("# Impressum\n\nZeile eins\nZeile zwei\n\n## Kontakt\n\n- Eins\n- **Zwei**\n");

        self::assertSame(
            "<h2>Impressum</h2>\n<p>Zeile eins Zeile zwei</p>\n<h2>Kontakt</h2>\n<ul><li>Eins</li><li><strong>Zwei</strong></li></ul>",
            $html,
        );
    }

    public function testMarkupIsEscapedFirst(): void
    {
        $html = Markdown::html('<script>alert("x")</script> & **fett**');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&amp;', $html);
        self::assertStringContainsString('<strong>fett</strong>', $html);
    }

    public function testOnlyAllowedLinkSchemes(): void
    {
        self::assertSame(
            '<p><a href="https://example.org/x">Web</a> <a href="/datenschutz">Intern</a></p>',
            Markdown::html('[Web](https://example.org/x) [Intern](/datenschutz)'),
        );

        // "//host" and "/\\host" are no own paths, they lead to another host.
        self::assertStringNotContainsString('<a ', Markdown::html('[x](//evil.example)'));
        self::assertStringNotContainsString('<a ', Markdown::html('[x](/\\evil.example)'));

        $gefaelscht = Markdown::html('[klick](javascript:alert(1))');
        self::assertStringNotContainsString('<a ', $gefaelscht);
        self::assertStringContainsString('[klick](javascript:alert(1))', $gefaelscht);
    }

    public function testEmptyTextGivesEmptyHtml(): void
    {
        self::assertSame('', Markdown::html(''));
    }
}
