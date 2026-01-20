<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

beforeEach(function () {
    config(['toon.strict' => true]);
    config(['toon.delimiter' => ',']);
    config(['toon.indent' => 2]);
    config(['toon.omit' => []]);
    config(['toon.omit_keys' => []]);
    config(['toon.key_aliases' => []]);
    config(['toon.date_format' => null]);
    config(['toon.truncate_strings' => null]);
    config(['toon.number_precision' => null]);
});

function loadFixture(string $category, string $name): array
{
    $path = __DIR__.'/../fixtures/'.$category.'/'.$name.'.json';

    if (! file_exists($path)) {
        return ['version' => '1.4', 'category' => $category, 'tests' => []];
    }

    return json_decode(file_get_contents($path), true);
}

function applyOptions(array $options): void
{
    if (isset($options['delimiter'])) {
        config(['toon.delimiter' => $options['delimiter']]);
    }
    if (isset($options['indent'])) {
        config(['toon.indent' => $options['indent']]);
    }
    if (isset($options['strict'])) {
        config(['toon.strict' => $options['strict']]);
    }
}

describe('TOON Spec Compliance - Encode Primitives', function () {
    $fixture = loadFixture('encode', 'primitives');

    foreach ($fixture['tests'] as $test) {
        $name = $test['name'];
        $input = $test['input'];
        $expected = $test['expected'];
        $shouldError = $test['shouldError'] ?? false;

        if ($shouldError) {
            it($name, function () use ($input) {
                expect(fn () => Toon::encode($input))->toThrow(\Exception::class);
            });
        } else {
            it($name, function () use ($input, $expected, $test) {
                if (isset($test['options'])) {
                    applyOptions($test['options']);
                }

                $result = Toon::encode($input);
                expect($result)->toBe($expected);
            });
        }
    }
});

describe('TOON Spec Compliance - Encode Objects', function () {
    $fixture = loadFixture('encode', 'objects');

    foreach ($fixture['tests'] as $test) {
        $name = $test['name'];
        $input = $test['input'];
        $expected = $test['expected'];
        $shouldError = $test['shouldError'] ?? false;

        if ($shouldError) {
            it($name, function () use ($input) {
                expect(fn () => Toon::encode($input))->toThrow(\Exception::class);
            });
        } else {
            it($name, function () use ($input, $expected, $test) {
                if (isset($test['options'])) {
                    applyOptions($test['options']);
                }

                $result = Toon::encode($input);
                expect($result)->toBe($expected);
            });
        }
    }
});
