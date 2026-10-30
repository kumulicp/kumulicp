<?php

namespace App\Support\Encryption;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Walks every location in EncryptedStores and reports which key reads each
 * encrypted value. With $rotate, values only a previous key can read are
 * re-encrypted with the current one. Values that can't be read are never
 * modified.
 */
class KeyScanner
{
    public function __construct(private KeyRing $key_ring, private int $chunk_size = 500) {}

    /**
     * @return array<int, array{location: string, current: int, previous: int, rotated: int, plaintext: int, undecryptable: int, conflicts: int}>
     */
    public function scan(bool $rotate = false, ?string $only_table = null): array
    {
        $report = [];

        foreach (EncryptedStores::columns() as $store) {
            if ($this->skip($store, $only_table)) {
                continue;
            }

            $report[] = $this->scanLocation($store, false, $rotate);
        }

        foreach (EncryptedStores::jsonColumns() as $store) {
            if ($this->skip($store, $only_table)) {
                continue;
            }

            $report[] = $this->scanLocation($store, true, $rotate);
        }

        return $report;
    }

    private function skip(array $store, ?string $only_table): bool
    {
        return ($only_table !== null && $store['table'] !== $only_table)
            || ! Schema::hasColumn($store['table'], $store['column']);
    }

    private function scanLocation(array $store, bool $is_json, bool $rotate): array
    {
        $totals = [
            'location' => $store['table'].'.'.$store['column'],
            'current' => 0,
            'previous' => 0,
            'rotated' => 0,
            'plaintext' => 0,
            'undecryptable' => 0,
            'conflicts' => 0,
        ];

        DB::table($store['table'])
            ->select('id', $store['column'])
            ->whereNotNull($store['column'])
            ->where($store['column'], '!=', '')
            ->chunkById($this->chunk_size, function ($rows) use ($store, $is_json, $rotate, &$totals) {
                foreach ($rows as $row) {
                    $original = $row->{$store['column']};
                    $counts = ['current' => 0, 'previous' => 0, 'plaintext' => 0, 'undecryptable' => 0];

                    $updated = $is_json
                        ? $this->scanJson($original, $rotate, $counts)
                        : $this->scanValue($original, $rotate, $counts);

                    foreach ($counts as $status => $count) {
                        $totals[$status] += $count;
                    }

                    if ($updated === null || $updated === $original) {
                        continue;
                    }

                    // Only write if the row is untouched since it was read, so a
                    // concurrent app write is never overwritten. Re-run to pick it up.
                    $written = DB::table($store['table'])
                        ->where('id', $row->id)
                        ->where($store['column'], $original)
                        ->update([$store['column'] => $updated]);

                    $written ? $totals['rotated']++ : $totals['conflicts']++;
                }
            }, 'id');

        return $totals;
    }

    // Plaintext inside a JSON column is normal and never reaches here (scanJson
    // only passes payload-shaped strings); in an always-encrypted column it is a
    // legacy leftover, counted so keys:check can warn about it.
    private function scanValue(string $value, bool $rotate, array &$counts): ?string
    {
        $status = $this->key_ring->inspect($value)['status'];
        $counts[$status]++;

        return $rotate && $status === KeyRing::PREVIOUS ? $this->key_ring->reencrypt($value) : null;
    }

    private function scanJson(string $json, bool $rotate, array &$counts): ?string
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return null;
        }

        $changed = false;

        array_walk_recursive($data, function (&$leaf) use ($rotate, &$counts, &$changed) {
            if (! KeyRing::isPayload($leaf)) {
                return;
            }

            $replacement = $this->scanValue($leaf, $rotate, $counts);

            if ($replacement !== null) {
                $leaf = $replacement;
                $changed = true;
            }
        });

        return $changed ? json_encode($data) : null;
    }
}
