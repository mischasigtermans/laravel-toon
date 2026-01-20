# Laravel TOON

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mischasigtermans/laravel-toon.svg?style=flat-square)](https://packagist.org/packages/mischasigtermans/laravel-toon)
[![Total Downloads](https://img.shields.io/packagist/dt/mischasigtermans/laravel-toon.svg?style=flat-square)](https://packagist.org/packages/mischasigtermans/laravel-toon)

A spec-compliant TOON (Token-Optimized Object Notation) encoder/decoder for Laravel with intelligent nested object handling.

TOON is a compact, YAML-like format designed to reduce token usage when sending data to LLMs. This package implements the [TOON v3.0 specification](https://github.com/toon-format/spec/blob/main/SPEC.md) and achieves **40-60% token reduction** compared to JSON while maintaining full round-trip fidelity.

## Installation

```bash
composer require mischasigtermans/laravel-toon
```

## Quick Start

```php
use MischaSigtermans\Toon\Facades\Toon;

$data = [
    'users' => [
        ['id' => 1, 'name' => 'Alice', 'role' => ['id' => 'admin', 'level' => 10]],
        ['id' => 2, 'name' => 'Bob', 'role' => ['id' => 'user', 'level' => 1]],
    ],
];

// Encode to TOON
$toon = Toon::encode($data);

// Decode back to array
$original = Toon::decode($toon);
```

**Output:**
```
users:
  items[2]{id,name,role.id,role.level}:
    1,Alice,admin,10
    2,Bob,user,1
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

## Why TOON?

When building MCP servers or LLM-powered applications, every token counts. JSON's verbosity wastes context window space with repeated keys and structural characters.

**JSON (398 bytes):**
```json
{"orders":[{"id":"ord_1","status":"shipped","customer":{"id":"cust_1","name":"Alice"},"total":99.99},{"id":"ord_2","status":"pending","customer":{"id":"cust_2","name":"Bob"},"total":149.50}]}
```

**TOON (186 bytes) - 53% smaller:**
```
orders:
  items[2]{id,status,customer.id,customer.name,total}:
    ord_1,shipped,cust_1,Alice,99.99
    ord_2,pending,cust_2,Bob,149.5
```

## Benchmarks

Real-world benchmarks from a production application with 17,000+ records:

| Data Type | JSON | TOON | Savings |
|-----------|------|------|---------|
| 50 records (nested objects) | 13,055 bytes | 5,080 bytes | **61%** |
| 100 records (nested objects) | 26,156 bytes | 10,185 bytes | **61%** |
| 500 records (nested objects) | 129,662 bytes | 49,561 bytes | **62%** |
| 1,000 records (nested objects) | 258,965 bytes | 98,629 bytes | **62%** |
| 100 records (mixed nesting) | 43,842 bytes | 26,267 bytes | **40%** |
| Single object | 169 bytes | 124 bytes | **27%** |

### Token Impact

For a typical paginated API response (50 records):
- **JSON**: ~3,274 tokens
- **TOON**: ~1,279 tokens
- **Saved**: ~2,000 tokens per request

## Features

### Nested Object Flattening

The key differentiator. Arrays containing objects with nested properties are automatically flattened using dot notation:

```php
$data = [
    ['id' => 1, 'author' => ['name' => 'Jane', 'email' => 'jane@example.com']],
    ['id' => 2, 'author' => ['name' => 'John', 'email' => 'john@example.com']],
];

$toon = Toon::encode($data);
// items[2]{id,author.name,author.email}:
//   1,Jane,jane@example.com
//   2,John,john@example.com

$decoded = Toon::decode($toon);
// Returns original nested structure
```

### Multi-Level Nesting

Handles deeply nested structures:

```php
$data = [
    [
        'id' => 1,
        'product' => [
            'name' => 'Widget',
            'category' => ['id' => 'cat_1', 'name' => 'Electronics'],
        ],
    ],
];

$toon = Toon::encode($data);
// items[1]{id,product.name,product.category.id,product.category.name}:
//   1,Widget,cat_1,Electronics
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

    // How deep to flatten nested objects (deeper = JSON string)
    'max_flatten_depth' => 3,

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
- **Array formats**: Inline primitives (`[N]: a,b,c`) and tabular objects (`[N]{fields}:`)
- **Nested object flattening**: Dot notation for nested properties in tabular format
- **Strict mode**: Optional validation during decoding
- **Backward compatibility**: Decoder accepts legacy backslash escaping

## Testing

```bash
composer test
```

The test suite includes 118 tests covering encoding, decoding, nested object handling, and official spec compliance fixtures.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Credits

- [Mischa Sigtermans](https://github.com/mischasigtermans)

## License

MIT
