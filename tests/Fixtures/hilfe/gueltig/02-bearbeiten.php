<?php

declare(strict_types=1);

use App\Domain\Permission;

// Made-up guide for AnleitungenTest and HilfeTest (issue #114).
return [
    'titel' => 'Zum Bearbeiten',
    'kurz' => 'Braucht das Recht, Mitglieder zu bearbeiten.',
    'recht' => Permission::MitgliederBearbeiten,
    'schritte' => [
        ['text' => 'Wählen Sie **Mitglied speichern**.'],
    ],
];
