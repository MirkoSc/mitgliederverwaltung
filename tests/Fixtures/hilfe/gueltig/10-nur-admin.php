<?php

declare(strict_types=1);

use App\Domain\Permission;

// Made-up guide for AnleitungenTest and HilfeTest (issue #114).
return [
    'titel' => 'Nur für den Admin',
    'kurz' => 'Braucht das Recht für die Einstellungen.',
    'recht' => Permission::AdminEinstellungen,
    'schritte' => [
        ['text' => 'Wählen Sie **Einstellungen**.'],
    ],
];
