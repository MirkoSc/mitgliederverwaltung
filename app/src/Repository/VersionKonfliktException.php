<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Someone else saved the member in the meantime: the version the form was
 * loaded with is no longer the latest. Nothing was written; the form shows
 * the current state and asks to repeat the change.
 */
final class VersionKonfliktException extends \RuntimeException
{
}
