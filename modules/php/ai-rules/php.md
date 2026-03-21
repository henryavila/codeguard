# CodeGuard AI Analysis Rules — PHP

## PHP-Specific Analysis Guidelines

These rules apply to any PHP project regardless of framework.

### Analysis Priority
1. Critical: no-html-in-php, no-debug-functions, no-superglobals (security + quality fundamentals)
2. Warning: strict-typing, type-declarations, exception-handling (quality improvements)

### False Positive Prevention

- **strict-typing**: Config files that return arrays (`config/app.php`) may intentionally omit strict_types. Flag but classify as suggestion, not warning.
- **no-html-in-php**: Blade template files (`.blade.php`) are excluded — they are designed for HTML. Only flag `.php` files. Also exclude email template builders that use dedicated HTML builder classes.
- **no-debug-functions**: Test files are excluded. `sleep()` in queue workers or retry logic is acceptable — only flag in request-handling code.
- **type-declarations**: PHP 7.x legacy code may lack types. In migration scenarios, classify as suggestion. Framework-generated stubs (migrations, seeders) may omit return types — acceptable.
- **exception-handling**: Catch blocks that log and re-throw are NOT empty catches. Catch blocks in event listeners that prevent event propagation failure are acceptable.
- **no-superglobals**: `$_SERVER` access in bootstrap files (index.php, artisan) is acceptable. `$_ENV` access in config files is acceptable (though `env()` is preferred in Laravel).

### PHP Version Considerations
- PHP 8.0+: Expect constructor promotion, named arguments, union types, match expressions
- PHP 8.1+: Expect enums, readonly properties, fibers, intersection types
- PHP 8.2+: Expect readonly classes, disjunctive normal form types
- PHP 8.3+: Expect typed class constants, json_validate()
- Adjust type-declaration expectations based on the project's PHP version (from composer.json)
