<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Domain\AuditAktion;

/**
 * The audit log (docs/spec/02-sicherheit.md section 8): append-only, with a
 * hash chain. Every other area records through protokollieren():
 *
 *     $audit->protokollieren(AuditAktion::BackupErstellt, $konto->id, details: ['datei' => 12]);
 *
 * Details are ids and counters, never member data (CLAUDE.md section 4).
 * The IP is kept only as an HMAC under the server key.
 *
 * The chain: hash = SHA-256 over the previous hash and all fields of the
 * row. pruefen() recomputes it. It detects a changed row, a removed or
 * inserted row in the middle and a removed first row; removing rows at the
 * very end leaves a valid shorter chain (the database cannot tell) - that
 * needs a copy of the head hash outside the database, which is not done.
 *
 * Call protokollieren() outside an open transaction: the writer lock is
 * released when the method returns, before a caller's commit. A second writer
 * could then still read the old head hash, the chain would fork and the check
 * would report a break.
 */
final class AuditLog
{
    /** prev_hash of the first row. */
    public const string ANFANG = '0000000000000000000000000000000000000000000000000000000000000000';

    private const string SPERRE = 'mv_audit_log';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $serverKey,
    ) {
    }

    /**
     * @param array<string, scalar|null|list<scalar>> $details
     */
    public function protokollieren(
        AuditAktion|string $aktion,
        ?int $userId = null,
        ?string $entity = null,
        int|string|null $entityId = null,
        array $details = [],
        ?string $ip = null,
        ?\DateTimeImmutable $jetzt = null,
    ): int {
        $aktion = $aktion instanceof AuditAktion ? $aktion->value : $aktion;
        $ts = ($jetzt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $ipHash = $ip === null || $ip === '' ? null : hash_hmac('sha256', $ip, $this->serverKey);
        $json = json_encode($details === [] ? new \stdClass() : $details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $entityId = $entityId === null ? null : (string) $entityId;

        // One writer at a time, so that two rows never chain to the same
        // predecessor - also on an empty table, where there is no row to lock.
        $sperre = $this->pdo->prepare('SELECT GET_LOCK(?, 10)');
        $sperre->execute([self::SPERRE]);
        if ((int) $sperre->fetchColumn() !== 1) {
            throw new \RuntimeException('Audit log is busy.');
        }

        try {
            $prev = $this->pdo->query('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1')?->fetchColumn();
            $prev = is_string($prev) ? $prev : self::ANFANG;
            $hash = self::hashBilden($prev, $ts, $userId, $aktion, $entity, $entityId, $ipHash, $json);

            $this->pdo->prepare(
                'INSERT INTO audit_log (ts, user_id, action, entity, entity_id, ip_hash, details, prev_hash, hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([$ts, $userId, $aktion, $entity, $entityId, $ipHash, $json, $prev, $hash]);

            return (int) $this->pdo->lastInsertId();
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::SPERRE]);
        }
    }

    /** Recomputes the whole chain, in batches. */
    public function pruefen(): KettenPruefung
    {
        $erwartetPrev = self::ANFANG;
        $letzteId = 0;
        $geprueft = 0;

        while (true) {
            $stmt = $this->pdo->prepare('SELECT * FROM audit_log WHERE id > ? ORDER BY id LIMIT 500');
            $stmt->execute([$letzteId]);
            $zeilen = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($zeilen === []) {
                return new KettenPruefung($geprueft, null);
            }
            foreach ($zeilen as $zeile) {
                $eintrag = self::eintrag($zeile);
                if ($eintrag->prevHash !== $erwartetPrev) {
                    return new KettenPruefung($geprueft, $eintrag->id, 'Verkettung unterbrochen (Eintrag fehlt oder wurde eingefügt)');
                }
                if (!hash_equals($eintrag->sollHash(), $eintrag->hash)) {
                    return new KettenPruefung($geprueft, $eintrag->id, 'Inhalt des Eintrags wurde verändert');
                }
                $erwartetPrev = $eintrag->hash;
                $letzteId = $eintrag->id;
                $geprueft++;
            }
        }
    }

    /**
     * Newest first.
     *
     * @return list<AuditEintrag>
     */
    public function neueste(int $anzahl = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_log ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, max(1, $anzahl), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(self::eintrag(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function anzahl(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')?->fetchColumn();
    }

    /**
     * @internal shared by writing and checking, so both always agree
     */
    public static function hashBilden(
        string $prev,
        string $ts,
        ?int $userId,
        string $aktion,
        ?string $entity,
        ?string $entityId,
        ?string $ipHash,
        string $details,
    ): string {
        return hash('sha256', json_encode(
            [$prev, $ts, $userId, $aktion, $entity, $entityId, $ipHash, $details],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param array<string, mixed> $z
     */
    private static function eintrag(array $z): AuditEintrag
    {
        return new AuditEintrag(
            id: (int) $z['id'],
            ts: (string) $z['ts'],
            userId: $z['user_id'] === null ? null : (int) $z['user_id'],
            action: (string) $z['action'],
            entity: $z['entity'] === null ? null : (string) $z['entity'],
            entityId: $z['entity_id'] === null ? null : (string) $z['entity_id'],
            ipHash: $z['ip_hash'] === null ? null : (string) $z['ip_hash'],
            details: (string) $z['details'],
            prevHash: (string) $z['prev_hash'],
            hash: (string) $z['hash'],
        );
    }
}
