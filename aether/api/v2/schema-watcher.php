<?php
/**
 * Aether v2 — Schema Watcher
 * Continuously detects DB structural changes (tables, columns, FKs, indexes)
 * and feeds them to the knowledge graph + audit log.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/audit-log.php';

class AetherSchemaWatcher
{
    private PDO $db;
    private string $schemaName;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: aether_db();
        $this->schemaName = DB_NAME;
    }

    /**
     * Build a normalised map of the current schema.
     */
    public function snapshot(): array {
        $tables  = [];
        $colCnt  = 0;
        $fkCnt   = 0;

        // Tables + columns
        $stmt = $this->db->prepare(
            "SELECT t.TABLE_NAME, t.TABLE_COMMENT, t.TABLE_ROWS,
                    c.COLUMN_NAME, c.DATA_TYPE, c.IS_NULLABLE, c.COLUMN_KEY,
                    c.COLUMN_DEFAULT, c.COLUMN_TYPE, c.ORDINAL_POSITION
             FROM information_schema.TABLES t
             JOIN information_schema.COLUMNS c
               ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME
             WHERE t.TABLE_SCHEMA = ? AND t.TABLE_TYPE = 'BASE TABLE'
             ORDER BY t.TABLE_NAME, c.ORDINAL_POSITION"
        );
        $stmt->execute([$this->schemaName]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = $r['TABLE_NAME'];
            if (!isset($tables[$t])) {
                $tables[$t] = [
                    'name'     => $t,
                    'comment'  => $r['TABLE_COMMENT'] ?: '',
                    'rows'     => (int)$r['TABLE_ROWS'],
                    'columns'  => [],
                    'foreign_keys' => [],
                ];
            }
            $tables[$t]['columns'][$r['COLUMN_NAME']] = [
                'type'     => $r['COLUMN_TYPE'],
                'nullable' => $r['IS_NULLABLE'] === 'YES',
                'key'      => $r['COLUMN_KEY'],
                'default'  => $r['COLUMN_DEFAULT'],
                'position' => (int)$r['ORDINAL_POSITION'],
            ];
            $colCnt++;
        }

        // Foreign keys
        $stmt = $this->db->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME,
                    REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL"
        );
        $stmt->execute([$this->schemaName]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = $r['TABLE_NAME'];
            if (!isset($tables[$t])) continue;
            $tables[$t]['foreign_keys'][] = [
                'column'      => $r['COLUMN_NAME'],
                'constraint'  => $r['CONSTRAINT_NAME'],
                'ref_table'   => $r['REFERENCED_TABLE_NAME'],
                'ref_column'  => $r['REFERENCED_COLUMN_NAME'],
            ];
            $fkCnt++;
        }

        $payload = [
            'schema'        => $this->schemaName,
            'tables'        => $tables,
            'table_count'   => count($tables),
            'column_count'  => $colCnt,
            'fk_count'      => $fkCnt,
        ];
        $payload['fingerprint'] = hash('sha256', json_encode($this->canonical($payload)));
        return $payload;
    }

    /** Canonical normalisation for deterministic fingerprints. */
    private function canonical(array $snap): array {
        $out = [];
        ksort($snap['tables']);
        foreach ($snap['tables'] as $t => $info) {
            ksort($info['columns']);
            $fks = $info['foreign_keys'];
            usort($fks, fn($a, $b) => strcmp(
                $a['column'].$a['ref_table'].$a['ref_column'],
                $b['column'].$b['ref_table'].$b['ref_column']
            ));
            $info['foreign_keys'] = $fks;
            $out[$t] = $info;
        }
        return $out;
    }

    /** Get the most recent stored snapshot (or null). */
    public function lastSnapshot(): ?array {
        $r = $this->db->query(
            "SELECT * FROM aether_schema_snapshots ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['snapshot'] = json_decode($r['snapshot_json'], true) ?: [];
        return $r;
    }

    /**
     * Compare two snapshots and return a list of structured changes.
     */
    public function diff(array $oldSnap, array $newSnap): array {
        $changes = [];
        $oldT = $oldSnap['tables'] ?? [];
        $newT = $newSnap['tables'] ?? [];

        foreach (array_diff(array_keys($newT), array_keys($oldT)) as $t) {
            $changes[] = [
                'change_type' => 'created',
                'object_type' => 'table',
                'object_name' => $t,
                'impact'      => 'medium',
                'details'     => ['columns' => array_keys($newT[$t]['columns'] ?? [])],
            ];
        }
        foreach (array_diff(array_keys($oldT), array_keys($newT)) as $t) {
            $changes[] = [
                'change_type' => 'dropped',
                'object_type' => 'table',
                'object_name' => $t,
                'impact'      => 'critical',
                'details'     => ['columns' => array_keys($oldT[$t]['columns'] ?? [])],
            ];
        }
        foreach (array_intersect(array_keys($oldT), array_keys($newT)) as $t) {
            $oc = $oldT[$t]['columns'] ?? [];
            $nc = $newT[$t]['columns'] ?? [];
            foreach (array_diff(array_keys($nc), array_keys($oc)) as $col) {
                $changes[] = [
                    'change_type' => 'added',
                    'object_type' => 'column',
                    'object_name' => "$t.$col",
                    'impact'      => 'low',
                    'details'     => $nc[$col],
                ];
            }
            foreach (array_diff(array_keys($oc), array_keys($nc)) as $col) {
                $changes[] = [
                    'change_type' => 'removed',
                    'object_type' => 'column',
                    'object_name' => "$t.$col",
                    'impact'      => 'high',
                    'details'     => $oc[$col],
                ];
            }
            foreach (array_intersect(array_keys($oc), array_keys($nc)) as $col) {
                if ($oc[$col]['type'] !== $nc[$col]['type']
                    || $oc[$col]['nullable'] !== $nc[$col]['nullable']) {
                    $changes[] = [
                        'change_type' => 'modified',
                        'object_type' => 'column',
                        'object_name' => "$t.$col",
                        'impact'      => 'medium',
                        'details'     => ['from' => $oc[$col], 'to' => $nc[$col]],
                    ];
                }
            }

            // FK diff
            $oldFkSet = array_map(fn($f) => "{$f['column']}->{$f['ref_table']}.{$f['ref_column']}", $oldT[$t]['foreign_keys'] ?? []);
            $newFkSet = array_map(fn($f) => "{$f['column']}->{$f['ref_table']}.{$f['ref_column']}", $newT[$t]['foreign_keys'] ?? []);
            foreach (array_diff($newFkSet, $oldFkSet) as $fk) {
                $changes[] = [
                    'change_type' => 'added', 'object_type' => 'foreign_key',
                    'object_name' => "$t:$fk", 'impact' => 'medium', 'details' => [],
                ];
            }
            foreach (array_diff($oldFkSet, $newFkSet) as $fk) {
                $changes[] = [
                    'change_type' => 'removed', 'object_type' => 'foreign_key',
                    'object_name' => "$t:$fk", 'impact' => 'high', 'details' => [],
                ];
            }
        }
        return $changes;
    }

    /**
     * Run a full sync: snapshot now, diff vs last, persist changes, audit, return result.
     */
    public function sync(): array {
        $now  = $this->snapshot();
        $last = $this->lastSnapshot();
        $changes = [];
        $isFirst = !$last;

        if ($last && $last['fingerprint'] === $now['fingerprint']) {
            return [
                'changed' => false,
                'fingerprint' => $now['fingerprint'],
                'changes' => [],
                'first_run' => false,
            ];
        }

        if ($last) {
            $changes = $this->diff($last['snapshot'], $now);
        }

        // store new snapshot
        $stmt = $this->db->prepare(
            "INSERT INTO aether_schema_snapshots
             (fingerprint, table_count, column_count, fk_count, snapshot_json)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $now['fingerprint'],
            $now['table_count'],
            $now['column_count'],
            $now['fk_count'],
            json_encode($now, JSON_UNESCAPED_UNICODE),
        ]);

        // record each detected change
        if (!empty($changes)) {
            $ins = $this->db->prepare(
                "INSERT INTO aether_schema_changes
                 (change_type, object_type, object_name, details_json, impact_level)
                 VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($changes as $c) {
                $ins->execute([
                    $c['change_type'], $c['object_type'], $c['object_name'],
                    json_encode($c['details'] ?? [], JSON_UNESCAPED_UNICODE),
                    $c['impact'] ?? 'info',
                ]);
            }
            AetherAudit::log(
                'schema_change',
                'Detected ' . count($changes) . ' schema change(s)',
                ['changes' => array_slice($changes, 0, 50)],
                'medium'
            );
        } elseif ($isFirst) {
            AetherAudit::log(
                'schema_baseline',
                'Initial schema snapshot recorded (' . $now['table_count'] . ' tables)',
                ['table_count' => $now['table_count']],
                'info'
            );
        }

        return [
            'changed' => !empty($changes) || $isFirst,
            'first_run' => $isFirst,
            'fingerprint' => $now['fingerprint'],
            'changes' => $changes,
            'table_count' => $now['table_count'],
            'column_count' => $now['column_count'],
        ];
    }

    public function recentChanges(int $limit = 50): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM aether_schema_changes ORDER BY id DESC LIMIT " . max(1, min($limit, 200))
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
