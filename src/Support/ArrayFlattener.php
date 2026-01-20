<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Support;

class ArrayFlattener
{
    public function __construct(
        protected int $maxDepth = 3,
    ) {}

    /**
     * @return array{columns: array<string>, rows: array<array<mixed>>}
     */
    public function flatten(array $items): array
    {
        if (empty($items)) {
            return ['columns' => [], 'rows' => []];
        }

        $columns = $this->extractColumns($items);
        $rows = array_map(fn (array $item) => $this->flattenRow($item, $columns), $items);

        return ['columns' => $columns, 'rows' => $rows];
    }

    public function hasNestedObjects(array $items): bool
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach ($item as $value) {
                if (is_array($value) && ! array_is_list($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string>
     */
    protected function extractColumns(array $items): array
    {
        $columns = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $this->walkItem($item, '', $columns, 0);
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param  array<string>  $columns
     */
    protected function walkItem(array $item, string $prefix, array &$columns, int $depth): void
    {
        foreach ($item as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && ! array_is_list($value) && $depth < $this->maxDepth) {
                $this->walkItem($value, $path, $columns, $depth + 1);
            } else {
                $columns[] = $path;
            }
        }
    }

    /**
     * @param  array<string>  $columns
     * @return array<mixed>
     */
    protected function flattenRow(array $row, array $columns): array
    {
        return array_map(fn (string $col) => $this->getByPath($row, $col), $columns);
    }

    protected function getByPath(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (! is_array($data) || ! array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }
}
