<?php

declare(strict_types=1);

namespace App\Listen;

/** What a column holds - decides how it is shown and how it is exported. */
enum SpaltenTyp
{
    /** Text; line breaks are allowed. */
    case Text;

    /** A calendar day: DateTimeInterface or "Y-m-d". Excel gets a real date. */
    case Datum;

    /** A whole number. */
    case Zahl;

    /** An amount in integer cent (CLAUDE.md section 5). */
    case Geld;
}
