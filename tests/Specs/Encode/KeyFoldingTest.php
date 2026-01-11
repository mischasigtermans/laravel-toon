<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it(
    'handles key folding with safe mode, depth control, collision avoidance',
    function (
        mixed $input,
        mixed $expected,
        array $options,
        bool $shouldError = false,
    ) {
        expect(Toon::encode($input))
            ->when(
                $shouldError,
                fn ($e) => $e->toThrow(\Exception::class)
            )
            ->toEqual($expected);
    }
)
    ->with(
        array_map(
            fn (array $s) => [
                'input' => $s['input'],
                'expected' => $s['expected'] ?? null,
                'options' => $s['options'] ?? [],
                'shouldError' => $s['shouldError'] ?? false,
            ],
            array_column(json_decode(file_get_contents(
                __DIR__.'/../../../node_modules/@toon-format/spec/tests/fixtures/encode/key-folding.json'
            ), true)['tests'], null, 'name'))
    )
    ->group('spec', 'encode');
