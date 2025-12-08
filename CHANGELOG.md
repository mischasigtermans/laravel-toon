# Changelog

All notable changes to this project will be documented in this file.

## [0.2.0] - 2025-12-08

### Added
- Unified `omit` config array to skip values: `['null', 'empty', 'false']` or `['all']`
- `omit_keys` config array to always skip specific keys
- `key_aliases` config for shortening key names (saves tokens)
- `date_format` config for formatting DateTime objects and ISO date strings
- `truncate_strings` config to limit string length with ellipsis
- `number_precision` config to limit decimal places for floats
- `Toon::diff($data)` method to calculate token savings between JSON and TOON
- `Toon::only($data, $keys)` method to encode only specific keys

### Changed
- Replaced `omit_null_values` boolean with flexible `omit` array (breaking change)

### Removed
- `omit_null_values` config option (use `'omit' => ['null']` instead)

## [0.1.0] - 2025-12-07

### Added
- Initial release
- TOON encoding with automatic nested object flattening using dot notation
- TOON decoding with nested object reconstruction
- Tabular array format for compact representation
- Type preservation (int, float, bool, null)
- Special character escaping (comma, colon, newline)
- Configurable flatten depth and table thresholds
- Laravel 9, 10, 11, and 12 support
- PHP 8.2+ support
