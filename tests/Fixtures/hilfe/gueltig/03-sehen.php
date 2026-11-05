<?php

declare(strict_types=1);

use App\Domain\Permission;

// Made-up guide for AnleitungenTest and HilfeTest (issue #114).
return [
    'titel' => 'Zum Ansehen',
    'kurz' => 'Braucht das Recht, Mitglieder zu sehen.',
    'recht' => Permission::MitgliederSehen,
    'schritte' => [
        ['text' => 'Wählen Sie **Mitglieder**.'],
    ],
];
