# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2026-01-21

### Added
- Full [TOON v3.0 specification](https://github.com/toon-format/spec/blob/main/SPEC.md) compliance
- Global helper functions: `toon_encode()` and `toon_decode()`
- Spec-compliant string quoting (strings with special characters are now quoted with `"..."`)
- Proper escape sequences within quoted strings (`\n`, `\r`, `\t`, `\"`, `\\`)
- Delimiter support: comma (default), tab (`\t`), and pipe (`|`) via `delimiter` config option
- Strict mode for decoding with validation errors via `strict` config option
- Key folding: collapse single-key nested objects into dot notation via `key_folding` config
- Path expansion: expand dotted keys back to nested objects via `expand_paths` config
- Official specification test fixtures from [toon-format/spec](https://github.com/toon-format/spec)
- Inline primitive array format (`key[N]: a,b,c`)

### Changed
- **BREAKING**: String escaping now uses quoted strings instead of backslash escaping
  - Before: `message: Hello\, World\: Test`
  - After: `message: "Hello, World: Test"`
- **BREAKING**: Removed `escape_style` config option (no longer applicable)
- Float encoding now preserves full IEEE 754 double precision (16 significant digits)

### Migration Guide
The decoder maintains backward compatibility and parses both old backslash-escaped format and new quoted strings.

If you have code that expects the old output format:
1. Encoded output now uses quoted strings for special characters
2. Republish config: `php artisan vendor:publish --tag=toon-config --force`
3. Set `strict => false` if parsing legacy TOON with formatting issues

## [0.2.2] - 2025-12-28

### Added
- `toToon()` collection macro for direct Collection-to-TOON conversion (thanks [@jimmypuckett](https://github.com/jimmypuckett))

## [0.2.1] - 2025-12-08

### Fixed
- Laravel Collections are now properly converted to arrays before encoding, preventing JSON fallback output
- Other `Arrayable` and `Traversable` objects are also handled correctly

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
- TOON encoding with tabular array format
- TOON decoding with nested object reconstruction
- Type preservation (int, float, bool, null)
- Special character escaping (comma, colon, newline)
- Configurable table thresholds
- Laravel 9, 10, 11, and 12 support
- PHP 8.2+ support
