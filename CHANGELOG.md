# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0] - 2026-11-09

Targets the [TOON v4.1 specification](https://toonformat.dev/guide/whats-new-in-v4.html). Nested objects stay in tables through nested field groups, and objects of uniform objects get a keyed table form. Encoder and decoder are rewritten against the spec and pass all 538 official fixtures.

**Upgrading from 1.x**
- If you only encode for an LLM, update a published config (Migration step 1) and you're done.
- If you decode this package's output elsewhere, or store it, upgrade those decoders first and re-encode stored TOON (steps 4 and 5).
- If you construct `ToonEncoder` or `ToonDecoder` yourself, or call `ArrayFlattener` or `ArrayUnflattener`, see steps 2 and 3.

**Breaking**
- Nested objects in tabular arrays encode as nested field groups (`{id,artist{id,name}}`) instead of dot-notation columns (`{id,artist.id,artist.name}`). The decoder reads dotted field names as literal keys.
- Arrays whose nested objects differ in shape use list form instead of a padded table with `null` cells.
- Objects whose values are two or more uniform objects encode in keyed tabular form (`servers[2:]{host,port}:`).
- Empty arrays encode as `key: []` and root `[]` instead of `key[0]:`.
- Strings starting with `#` or containing `[`, `]` anywhere are quoted.
- Empty tabular cells decode to `""` instead of `null`.
- The decoder drops legacy backslash unescaping (`Hello\, World`). Unquoted tokens decode as-is.
- Invalid escape sequences, unterminated strings and bare scalar lines error in non-strict mode too.
- Requires Laravel 12 or 13. Every Laravel 10 and 11 release carries unpatched security advisories, and Composer refuses to install them by default. Stay on 1.x for those versions.
- `number_precision` rounds to canonical form: `99.5`, not `99.50`, and `100`, not `100.00`.
- `min_rows_for_table` defaults to `1`. The spec requires tabular form for every uniform array.
- `ToonEncoder` and `ToonDecoder` take the config array as their only constructor argument.
- `toon_decode()` returns `mixed`, so a root primitive comes back as itself.
- Removed the `key_folding`, `key_folding_depth` and `expand_paths` config options, following their removal from the spec.
- Removed the `max_flatten_depth` config option. Nested field groups have no depth limit.
- Removed the `ArrayFlattener` and `ArrayUnflattener` support classes.

**Added**
- Keyed tabular form for objects of uniform objects, encode and decode.
- Nested field groups at any depth, encode and decode.
- Full-line `#` comments are skipped when decoding.
- `\uXXXX` escapes for control characters.
- Strict-mode errors for malformed headers, duplicate keys, indentation jumps and trailing content after a root array.
- `BackedEnum`, `JsonSerializable` and `Stringable` values are normalized before encoding.

**Fixed**
- Keys containing `.` inside nested objects no longer break tabular encoding ([#13](https://github.com/mischasigtermans/laravel-toon/issues/13)).
- `truncate_strings` counts characters, so a cut never splits a multibyte character.

**Migration**
1. Remove `max_flatten_depth`, `key_folding`, `key_folding_depth` and `expand_paths` from a published `config/toon.php`, and set `min_rows_for_table` to `1`.
2. Pass the config as the first constructor argument: `new ToonEncoder($config)`. A 1.x call, `new ToonEncoder(null, $config)`, still runs on 2.0 and ignores `$config`.
3. `ArrayFlattener` and `ArrayUnflattener` have no replacement. The encoder and decoder handle nesting themselves.
4. Re-encode stored TOON that used dot-notation columns, key folding or 0.x backslash escaping: decode it with 1.x, encode it with 2.0.
5. Update decoders that read this package's output before upgrading the encoder. A 1.x decoder misreads nested field groups, keyed tables and empty arrays without an error: `tags: []` comes back as the string `"[]"`.

## [1.1.1] - 2026-11-09

### Fixed
- An array of objects whose cells would hold a list or an object encodes in list form, so every table row decodes to what was encoded.
- An array of objects with a key containing `.` at any depth encodes in list form, so the key keeps its value ([#13](https://github.com/mischasigtermans/laravel-toon/issues/13)).

## [1.1.0] - 2026-03-23

### Added
- Laravel 13 support

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
