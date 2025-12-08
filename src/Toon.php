<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon;

use MischaSigtermans\Toon\Converters\ToonDecoder;
use MischaSigtermans\Toon\Converters\ToonEncoder;

class Toon
{
    public function __construct(
        protected ToonEncoder $encoder,
        protected ToonDecoder $decoder,
    ) {}

    public function encode(mixed $data): string
    {
        return $this->encoder->encode($data);
    }

    public function decode(string $toon): array
    {
        return $this->decoder->decode($toon);
    }

    /**
     * Estimate token savings between JSON and TOON formats.
     *
     * @return array{json_chars: int, toon_chars: int, saved_chars: int, savings_percent: float}
     */
    public function diff(mixed $data): array
    {
        $json = json_encode($data) ?: '';
        $toon = $this->encode($data);

        $jsonLen = strlen($json);
        $toonLen = strlen($toon);

        return [
            'json_chars' => $jsonLen,
            'toon_chars' => $toonLen,
            'saved_chars' => $jsonLen - $toonLen,
            'savings_percent' => $jsonLen > 0 ? round((1 - $toonLen / $jsonLen) * 100, 1) : 0.0,
        ];
    }

    /**
     * Encode only specific keys from the data.
     */
    public function only(mixed $data, array $keys): string
    {
        $filtered = $this->filterKeys($data, $keys);

        return $this->encode($filtered);
    }

    /**
     * Recursively filter data to only include specified keys.
     */
    protected function filterKeys(mixed $data, array $keys): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        // Check if this is a sequential array (list of items)
        if (array_is_list($data)) {
            return array_map(fn ($item) => $this->filterKeys($item, $keys), $data);
        }

        // Associative array - filter to only specified keys
        $filtered = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $filtered[$key] = $data[$key];
            }
        }

        return $filtered;
    }
}
