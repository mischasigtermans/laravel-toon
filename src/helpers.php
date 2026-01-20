<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

if (! function_exists('toon_encode')) {
    function toon_encode(mixed $data): string
    {
        return Toon::encode($data);
    }
}

if (! function_exists('toon_decode')) {
    function toon_decode(string $toon): array
    {
        return Toon::decode($toon);
    }
}
