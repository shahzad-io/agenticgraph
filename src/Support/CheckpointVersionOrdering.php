<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Support;

/**
 * Deterministic ordering for checkpoint version listings.
 */
final class CheckpointVersionOrdering
{
    /**
     * Sort checkpoint version rows ascending (oldest first).
     *
     * @param  array<int, array{id: string, node: string, created_at: string, sequence?: int}>  $rows
     * @return array<int, array{id: string, node: string, created_at: string}>
     */
    public static function sortAscending(array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            $createdAtCompare = strcmp((string) $a['created_at'], (string) $b['created_at']);
            if ($createdAtCompare !== 0) {
                return $createdAtCompare;
            }

            $sequenceCompare = ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0);
            if ($sequenceCompare !== 0) {
                return $sequenceCompare;
            }

            return strcmp($a['id'], $b['id']);
        });

        return array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'node' => $row['node'],
                'created_at' => $row['created_at'],
            ],
            $rows
        );
    }
}
