# Laravel TOON

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mischasigtermans/laravel-toon.svg?style=flat-square)](https://packagist.org/packages/mischasigtermans/laravel-toon)
[![Total Downloads](https://img.shields.io/packagist/dt/mischasigtermans/laravel-toon.svg?style=flat-square)](https://packagist.org/packages/mischasigtermans/laravel-toon)

The most complete [TOON](https://toonformat.dev/) implementation for Laravel, and the only one with full [TOON v3.0 specification](https://github.com/toon-format/spec/blob/main/SPEC.md) compliance. Listed as [official Laravel implementation](https://toonformat.dev/ecosystem/implementations.html).

TOON (Token-Optimized Object Notation) is a compact, YAML-like format designed to reduce token usage when sending data to LLMs. This package achieves **~50% token reduction** compared to JSON while maintaining full round-trip fidelity, backed by 470 tests.

## Installation

```bash
composer require mischasigtermans/laravel-toon
```

## Quick Start

```php
use MischaSigtermans\Toon\Facades\Toon;

$data = [
    'users' => [
        ['id' => 1, 'name' => 'Alice', 'active' => true],
        ['id' => 2, 'name' => 'Bob', 'active' => false],
    ],
];

// Encode to TOON
$toon = Toon::encode($data);

// Decode back to array
$original = Toon::decode($toon);
```

**Output:**
```
users[2]{id,name,active}:
  1,Alice,true
  2,Bob,false
```

### Global Helper Functions

For convenience, global helper functions are available:

```php
// Encode to TOON
$toon = toon_encode($data);

// Decode back to array
$original = toon_decode($toon);
```

### Collection Macro: `toToon`

You can convert any Laravel collection directly to TOON format with the built-in `toToon` macro:

```php
$collection = collect([
    ['id' => 1, 'name' => 'Alice'],
    ['id' => 2, 'name' => 'Bob'],
]);
$toon = $collection->toToon();
```

**Output:**
```
items[2]{id,name}:
  1,Alice
  2,Bob
```

### Eloquent Model: `toToon`

Eloquent models have a `toToon` method, similar to `toJson` and `toArray`:

```php
$user = User::find(1);
$toon = $user->toToon();
```

## Why TOON?

When building MCP servers or LLM-powered applications, every token counts. JSON's verbosity wastes context window space with repeated keys and structural characters.

**JSON (201 bytes):**
```json
{"users":[{"id":1,"name":"Alice","role":"admin"},{"id":2,"name":"Bob","role":"user"},{"id":3,"name":"Carol","role":"user"}]}
```

**TOON (62 bytes) - 69% smaller:**
```
users[3]{id,name,role}:
  1,Alice,admin
  2,Bob,user
  3,Carol,user
```

## Benchmarks

For a typical paginated API response (50 records):
- **JSON**: ~7,597 tokens
- **TOON**: ~3,586 tokens
- **Saved**: ~4,000 tokens per request

Real-world benchmarks from a production application with 17,000+ records:

| Data Type | JSON | TOON | Savings |
|-----------|------|------|---------|
| 50 records | 30,389 bytes | 14,343 bytes | **53%** |
| 100 records | 60,856 bytes | 28,498 bytes | **53%** |
| 500 records | 303,549 bytes | 140,154 bytes | **54%** |
| 1,000 records | 604,408 bytes | 277,614 bytes | **54%** |

## Features

### Tabular Format

Arrays of uniform objects with primitive values are encoded as compact tables:

```php
$data = [
    ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
    ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
];

$toon = Toon::encode($data);
// [2]{id,name,role}:
//   1,Alice,admin
//   2,Bob,user
```

### List Format for Nested Objects

Arrays containing objects with nested properties use list format for clarity:

```php
$data = [
    ['id' => 1, 'author' => ['name' => 'Jane', 'email' => 'jane@example.com']],
    ['id' => 2, 'author' => ['name' => 'John', 'email' => 'john@example.com']],
];

$toon = Toon::encode($data);
// [2]:
//   - id: 1
//     author:
//       name: Jane
//       email: jane@example.com
//   - id: 2
//     author:
//       name: John
//       email: john@example.com

$decoded = Toon::decode($toon);
// Returns original nested structure
```

### Type Preservation

All scalar types are preserved through encode/decode:

```php
$data = [
    'count' => 42,
    'price' => 19.99,
    'active' => true,
    'deleted' => false,
    'notes' => null,
];

$decoded = Toon::decode(Toon::encode($data));
// Types are preserved: int, float, bool, null
```

### String Quoting (Spec-Compliant)

Strings containing special characters are automatically quoted per the TOON spec:

```php
$data = ['message' => 'Hello, World: How are you?'];
$toon = Toon::encode($data);
// message: "Hello, World: How are you?"

$data = ['text' => "Line 1\nLine 2"];
$toon = Toon::encode($data);
// text: "Line 1\nLine 2"
```

Safe strings (alphanumeric, underscores, dots) remain unquoted for minimal overhead.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=toon-config
```

### Basic Options

```php
// config/toon.php
return [
    // Arrays with fewer items use regular object format instead of tables
    'min_rows_for_table' => 2,

    // Delimiter for array values: ',' (default), '\t' (tab), or '|' (pipe)
    'delimiter' => ',',

    // Strict mode for decoding (throws on malformed input)
    'strict' => true,
];
```

### Token-Saving Options

```php
return [
    // Omit values to save tokens: 'null', 'empty', 'false', or 'all'
    'omit' => ['null', 'empty'],

    // Always skip these keys
    'omit_keys' => ['created_at', 'updated_at'],

    // Shorten verbose keys
    'key_aliases' => [
        'description' => 'desc',
        'organization_id' => 'org_id',
    ],
];
```

### Value Transformation

```php
return [
    // Format dates (DateTime objects and ISO strings)
    'date_format' => 'Y-m-d',

    // Truncate long strings (adds ... suffix)
    'truncate_strings' => 100,

    // Limit decimal places for floats
    'number_precision' => 2,
];
```

## Utility Methods

### Measure Savings

```php
$data = User::with('roles')->get()->toArray();

$diff = Toon::diff($data);
// [
//     'json_chars' => 12500,
//     'toon_chars' => 5200,
//     'saved_chars' => 7300,
//     'savings_percent' => 58.4,
// ]
```

### Encode Specific Keys Only

```php
$users = User::all()->toArray();

// Only include id and name, exclude email, password, etc.
$toon = Toon::only($users, ['id', 'name']);
```

## Use Cases

### MCP Servers

Reduce token usage when returning data from MCP tool calls:

```php
public function handle(): string
{
    $users = User::with('roles')->limit(100)->get();

    return Toon::encode([
        'count' => $users->count(),
        'users' => $users->toArray(),
    ]);
}
```

### LLM Context

Pack more data into your context window:

```php
$context = Toon::encode([
    'conversation' => $messages,
    'user_profile' => $user->toArray(),
    'recent_orders' => $orders->toArray(),
]);

$response = $llm->chat([
    ['role' => 'system', 'content' => "Context:\n{$context}"],
    ['role' => 'user', 'content' => $question],
]);
```

### API Responses

Optional TOON responses for token-conscious clients:

```php
public function index(Request $request)
{
    $data = Product::paginate()->toArray();

    if ($request->header('Accept') === 'application/toon') {
        return response(Toon::encode($data))
            ->header('Content-Type', 'application/toon');
    }

    return response()->json($data);
}
```

## Spec Compliance

This package implements the [TOON v3.0 specification](https://github.com/toon-format/spec/blob/main/SPEC.md) and passes the official specification test suite. Key compliance features:

- **String quoting**: Safe strings unquoted, special characters properly escaped (`\n`, `\r`, `\t`, `\"`, `\\`)
- **Delimiter support**: Comma (default), tab, and pipe delimiters
- **Tabular format**: Compact tables for arrays of primitive-only objects (`[N]{fields}:`)
- **List format**: Readable structure for arrays with nested objects (`[N]:` with `- field:` items)
- **Inline arrays**: Primitive arrays on single line (`key[N]: a,b,c`)
- **Strict mode**: Optional validation during decoding
- **Backward compatibility**: Decoder accepts legacy formats (backslash escaping, dot-notation columns)

## Testing

```bash
composer test
```

The test suite includes 470 tests covering encoding, decoding, nested object handling, and official spec compliance fixtures.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Credits

- [Mischa Sigtermans](https://github.com/mischasigtermans)

## License

MIT
