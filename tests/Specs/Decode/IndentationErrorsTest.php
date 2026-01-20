<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

beforeEach(function () {
    Toon::clearResolvedInstances();
});

it(
    'handles strict mode indentation validation - non-multiple indentation, tab characters, custom indent sizes',
    function (
        mixed $input,
        mixed $expected,
        array $options = [],
        bool $shouldError = false,
    ) {
        if (! empty($options)) {
            config(['toon' => array_merge(config('toon', []), $options)]);
            Toon::clearResolvedInstances();
        }

        if ($shouldError) {
            expect(fn () => Toon::decode($input))->toThrow(\Exception::class);
        } else {
            expect(Toon::decode($input))->toEqual($expected);
        }
    }
)
    ->with(toonSpecDataset('decode/indentation-errors'))
    ->group('spec', 'decode');
