<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Dienste;

/** The tasks of the cron, found by file name (`*Task.php`), like the other extensions. */
final class CronAufgaben
{
    /**
     * @return list<CronTask> in file name order
     */
    public static function alle(Dienste $dienste): array
    {
        $dateien = glob(__DIR__ . '/*Task.php') ?: [];
        sort($dateien, SORT_STRING);

        $aufgaben = [];
        foreach ($dateien as $datei) {
            $klasse = __NAMESPACE__ . '\\' . basename($datei, '.php');
            if (!class_exists($klasse) || !is_subclass_of($klasse, CronTask::class) || !new \ReflectionClass($klasse)->isInstantiable()) {
                continue;
            }
            $aufgaben[] = $klasse::erzeugen($dienste);
        }

        return $aufgaben;
    }
}
