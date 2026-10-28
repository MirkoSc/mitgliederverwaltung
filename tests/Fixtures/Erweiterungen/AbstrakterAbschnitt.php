<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Erweiterungen;

use App\Admin\Einstellungen\Abschnitt;

/** A base class an issue might share between sections - must be ignored. */
abstract class AbstrakterAbschnitt implements Abschnitt
{
}
