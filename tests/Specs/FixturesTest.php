<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Converters\ToonDecoder;
use MischaSigtermans\Toon\Converters\ToonEncoder;

dataset('fixtures', function () {
    $options = ['indentSize' => 'indent'];

    foreach (glob(dirname(__DIR__, 2).'/node_modules/@toon-format/spec/tests/fixtures/*/*.json') as $file) {
        $category = basename(dirname($file));
        $fixture = json_decode(file_get_contents($file), false, flags: JSON_THROW_ON_ERROR);

        foreach ($fixture->tests as $test) {
            $config = [];

            foreach ((array) ($test->options ?? []) as $name => $value) {
                $config[$options[$name] ?? $name] = $value;
            }

            yield "{$category}/".basename($file, '.json')." › {$test->name}" => [
                $category,
                $test->input,
                $test->expected ?? null,
                $config,
                $test->shouldError ?? false,
            ];
        }
    }
});

it('conforms to the TOON spec', function (string $category, mixed $input, mixed $expected, array $config, bool $shouldError) {
    $run = $category === 'encode'
        ? fn () => (new ToonEncoder($config))->encode($input)
        : fn () => (new ToonDecoder($config))->decode($input);

    if ($shouldError) {
        expect($run)->toThrow(Exception::class);

        return;
    }

    $expected = is_string($expected) || $category === 'encode'
        ? $expected
        : json_decode(json_encode($expected), true);

    expect($run())->toEqual($expected);
})->with('fixtures')->group('spec');
