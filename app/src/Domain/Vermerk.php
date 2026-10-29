<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One entry of the note list of an annual fee (docs/spec/08-beitraege.md
 * section 2): who, when, what. Stored in `jahresbeitrag.vermerke` as
 * {"von": user id|null, "am": "Y-m-d H:i:s", "text": "..."}.
 */
final readonly class Vermerk
{
    public function __construct(
        /** User id; null for the system (e.g. the import). */
        public ?int $von,
        public \DateTimeImmutable $am,
        public string $text,
    ) {
    }

    /**
     * @return array{von: int|null, am: string, text: string}
     */
    public function alsArray(): array
    {
        return ['von' => $this->von, 'am' => $this->am->format('Y-m-d H:i:s'), 'text' => $this->text];
    }

    /**
     * @param array<mixed> $daten
     */
    public static function ausArray(array $daten): self
    {
        $am = is_string($daten['am'] ?? null) ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $daten['am']) : false;

        return new self(
            von: is_int($daten['von'] ?? null) ? $daten['von'] : null,
            am: $am !== false ? $am : new \DateTimeImmutable('@0'),
            text: is_string($daten['text'] ?? null) ? $daten['text'] : '',
        );
    }
}
