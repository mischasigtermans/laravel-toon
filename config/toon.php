<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Rows for Table Format
    |--------------------------------------------------------------------------
    |
    | Arrays of uniform objects with fewer items than this use list form
    | instead of the tabular form. The TOON spec requires tabular form for
    | every uniform array, so values above 1 produce non-conforming output.
    |
    */
    'min_rows_for_table' => 1,

    /*
    |--------------------------------------------------------------------------
    | Indentation
    |--------------------------------------------------------------------------
    |
    | Number of spaces per indentation level. The TOON spec default is 2.
    |
    */
    'indent' => 2,

    /*
    |--------------------------------------------------------------------------
    | Delimiter
    |--------------------------------------------------------------------------
    |
    | The delimiter used to separate values in arrays and tabular data.
    | Supported values: ',' (comma, default), "\t" (tab), '|' (pipe)
    |
    | Tab and pipe delimiters can reduce the need for quoting when your
    | data contains many commas.
    |
    */
    'delimiter' => ',',

    /*
    |--------------------------------------------------------------------------
    | Strict Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the decoder will throw exceptions for:
    | - Array length mismatches (declared vs actual item count)
    | - Tabular row width mismatches
    | - Malformed headers and duplicate keys
    | - Indentation errors and blank lines inside array blocks
    |
    | Set to false for lenient parsing that ignores these issues.
    |
    */
    'strict' => true,

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
    | Note: In tabular format, these values keep their cell (null, "",
    | false) to maintain column alignment.
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
    */
    'key_aliases' => [
        // 'created_at' => 'c@',
        // 'updated_at' => 'u@',
        // 'description' => 'desc',
    ],

    /*
    |--------------------------------------------------------------------------
    | Date Format
    |--------------------------------------------------------------------------
    |
    | Format DateTime objects and ISO date strings using this format. When null,
    | dates are passed through as-is. Uses PHP date format syntax.
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
    | Maximum decimal places for float values. Rounded values keep canonical
    | form, so 99.5 stays 99.5, not 99.50. When null, floats pass through as-is.
    |
    */
    'number_precision' => null,

];
