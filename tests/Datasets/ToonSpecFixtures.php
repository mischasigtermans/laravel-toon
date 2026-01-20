<?php

declare(strict_types=1);

function mapSpecOptionsToConfig(array $options): array
{
    $mapping = [
        'flattenDepth' => 'key_folding_depth',
        'keyFolding' => 'key_folding',
        'expandPaths' => 'expand_paths',
    ];

    $mapped = [];
    foreach ($options as $key => $value) {
        $configKey = $mapping[$key] ?? $key;
        $mapped[$configKey] = $value;
    }

    return $mapped;
}

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
        .'/node_modules/@toon-format/spec/tests/fixtures/'
        .$fixture;

    try {
        if (! is_file($file)) {
            throw new Exception("Spec file [{$file}] not found");
        }

        $decoded = json_decode(
            file_get_contents($file),
            false,
            flags: JSON_THROW_ON_ERROR
        );

        if (! isset($decoded->tests) || ! is_array($decoded->tests)) {
            throw new Exception("Spec file [{$file}] has no valid tests array");
        }

        $result = [];
        foreach ($decoded->tests as $s) {
            $name = $s->name;
            // For encode tests, input needs stdClass preserved to distinguish {} from []
            // For decode tests, input is a string so no conversion needed
            // Expected values are compared against output, so convert to arrays for simpler comparison
            $expected = $s->expected ?? null;
            if ($expected !== null && ! is_string($expected)) {
                $expected = json_decode(json_encode($expected), true);
            }

            $result[$name] = [
                'input' => $s->input,
                'expected' => $expected,
                'options' => mapSpecOptionsToConfig((array) ($s->options ?? new \stdClass)),
                'shouldError' => $s->shouldError ?? false,
            ];
        }

        return $result;
    } catch (Throwable $e) {
        // Surface loader errors as a single dataset entry
        return [
            $e->getMessage() => [
                'input' => '',
                'expected' => false,
            ],
        ];
    }
}
