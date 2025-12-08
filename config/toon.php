<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Rows for Table Format
    |--------------------------------------------------------------------------
    |
    | Arrays with fewer items than this will be encoded as regular YAML-like
    | objects instead of the compact tabular format. Set to 1 to always use
    | tables for uniform arrays, or higher to only use tables for larger
    | datasets where the header overhead is worth it.
    |
    | Example with min_rows = 2:
    |   1 item  → id: 1\n name: Alice
    |   2 items → items[2]{id,name}:\n  1,Alice\n  2,Bob
    |
    */
    'min_rows_for_table' => 2,

    /*
    |--------------------------------------------------------------------------
    | Maximum Flatten Depth
    |--------------------------------------------------------------------------
    |
    | How many levels deep to flatten nested objects within arrays. Nested
    | objects become dot-notation columns (e.g., author.name, author.email).
    | Objects nested deeper than this limit will be JSON-encoded as a string.
    |
    | Example with max_depth = 2:
    |   user.profile.name → flattened to column
    |   user.profile.settings.theme → JSON string "[{...}]"
    |
    | Increase for deeply nested data structures. Decrease if your objects
    | are very wide (many fields) to keep column headers manageable.
    |
    */
    'max_flatten_depth' => 3,

    /*
    |--------------------------------------------------------------------------
    | Escape Style
    |--------------------------------------------------------------------------
    |
    | How to escape special characters in string values. Special characters
    | include commas (,), colons (:), and newlines which have meaning in the
    | TOON format.
    |
    | Supported styles:
    | - 'backslash': Escape with backslash (Hello, World → Hello\, World)
    |
    */
    'escape_style' => 'backslash',

    /*
    |--------------------------------------------------------------------------
    | Omit Values
    |--------------------------------------------------------------------------
    |
    | Specify which value types to omit from the output. This saves tokens
    | when your data has many optional/nullable fields or default values.
    |
    | Supported values:
    |   - 'null'  : Omit keys with null values
    |   - 'empty' : Omit keys with empty string values ('')
    |   - 'false' : Omit keys with false values
    |   - 'all'   : Shorthand for ['null', 'empty', 'false']
    |
    | Example with omit = ['null', 'empty']:
    |   ['name' => 'Alice', 'email' => null, 'bio' => '']  →  name: Alice
    |
    | Note: In tabular format, these values are still represented as empty
    | cells to maintain column alignment.
    |
    */
    'omit' => [],

    /*
    |--------------------------------------------------------------------------
    | Omit Keys
    |--------------------------------------------------------------------------
    |
    | Specify keys that should always be omitted from the output, regardless
    | of their value. Useful for excluding verbose or unnecessary fields.
    |
    | Example:
    | 'omit_keys' => ['created_at', 'updated_at', 'deleted_at']
    |
    */
    'omit_keys' => [],

    /*
    |--------------------------------------------------------------------------
    | Key Aliases
    |--------------------------------------------------------------------------
    |
    | Map long key names to shorter aliases to save tokens. Aliases are applied
    | to both regular key-value pairs and table column headers.
    |
    | Uncomment or add your own aliases:
    |
    */
    'key_aliases' => [
        // 'created_at' => 'c@',
        // 'updated_at' => 'u@',
        // 'deleted_at' => 'd@',
        // 'description' => 'desc',
        // 'organization_id' => 'org_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Date Format
    |--------------------------------------------------------------------------
    |
    | Format DateTime objects and ISO date strings using this format. When null,
    | dates are passed through as-is. Uses PHP date format syntax.
    |
    | Examples:
    | 'Y-m-d H:i' → 2024-01-15 14:30
    | 'Y-m-d' → 2024-01-15
    | 'd/m/Y' → 15/01/2024
    |
    */
    'date_format' => null,

    /*
    |--------------------------------------------------------------------------
    | Truncate Strings
    |--------------------------------------------------------------------------
    |
    | Maximum length for string values. Strings exceeding this length will be
    | truncated with an ellipsis (...). When null, strings are not truncated.
    |
    */
    'truncate_strings' => null,

    /*
    |--------------------------------------------------------------------------
    | Number Precision
    |--------------------------------------------------------------------------
    |
    | Maximum decimal places for float values. When null, floats are passed
    | through as-is. Useful for reducing precision on monetary values etc.
    |
    */
    'number_precision' => null,

];
