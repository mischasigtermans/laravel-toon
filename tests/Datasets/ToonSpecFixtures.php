<?php

use Throwable;

/**
 * Build a Pest dataset from a Toon spec JSON fixture.
 *
 * Usage:
 *   ->with(toonSpecDataset('decode/blank-lines'))
 *   ->with(toonSpecDataset('encode/tabular-arrays'))
 */
function toonSpecDataset(string $fixture): array
{
    // Allow passing with or without ".json"
    $fixture = str_ends_with($fixture, '.json')
        ? $fixture
        : "{$fixture}.json";

    // Resolve project root from tests/Datasets/
    $projectRoot = dirname(__DIR__, 2);

    $file = $projectRoot
        . '/node_modules/@toon-format/spec/tests/fixtures/'
        . $fixture;

    try {
        if (! is_file($file)) {
            throw new Exception("Spec file [{$file}] not found");
        }

        $decoded = json_decode(
            file_get_contents($file),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        if (! isset($decoded['tests']) || ! is_array($decoded['tests'])) {
            throw new Exception("Spec file [{$file}] has no valid tests array");
        }

        // Preserve spec "name" as dataset keys
        $testsByName = array_column(
            $decoded['tests'],
            null,
            'name'
        );

        return array_map(
            static fn (array $s) => [
                'input'       => $s['input'],
                'expected'    => $s['expected'] ?? null,
                'options'     => $s['options'] ?? [],
                'shouldError' => $s['shouldError'] ?? false,
            ],
            $testsByName
        );
    } catch (Throwable $e) {
        // Surface loader errors as a single dataset entry
        return [
            $e->getMessage() => [
                'input'       => '',
                'expected'    => false,
            ],
        ];
    }
}
