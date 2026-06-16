# i18n PHP 8 Modernization Plan

## Goal

Rebuild the legacy `i18n.so` PHP extension for BlueOnyx 5212R so it works natively with:

```text
/home/solarspeed/admserv-php/bin/php
PHP 8.3.x (NTS)
```

The target is to restore the old low-overhead native i18n path and replace the current slow PHP userland fallback used by the GUI.

This should be treated as a rewrite of the Zend binding layer, not as a line-by-line port of the historic PHP 4/5 code.

## Why This Is Worth Doing

Compared with the old `cce.so` wrapper, this module is a much better modernization candidate:

- smaller public function surface
- mostly string-in, string-out behavior
- very little protocol or transaction state
- much thinner wrapping around native `i18n_*()` library calls
- likely higher impact on GUI responsiveness because localization calls happen frequently

If the current PHP fallback is significantly slower, restoring `i18n.so` as a modern extension is likely one of the most cost-effective performance wins available.

## Scope

The target function surface is:

```c
zend_function_entry i18n_functions[] = {
	PHP_FE(i18n_new,              NULL)
	PHP_FE(i18n_get,              NULL)
	PHP_FE(i18n_get_js,           NULL)
	PHP_FE(i18n_get_html,         NULL)
	PHP_FE(i18n_get_property,     NULL)
	PHP_FE(i18n_get_file,         NULL)
	PHP_FE(i18n_availlocales,     NULL)
	PHP_FE(i18n_locales,          NULL)
	PHP_FE(i18n_strftime,         NULL)
	PHP_FE(i18n_interpolate,      NULL)
	PHP_FE(i18n_interpolate_js,   NULL)
	PHP_FE(i18n_interpolate_html, NULL)
	{NULL, NULL, NULL}
};
```

## Recommendation

Do not try to make the existing `i18n.c` compile by incremental patching.

Instead:

- keep the old file as behavioral reference
- write a new PHP 8 implementation with the same exported function names
- preserve userland compatibility where practical
- build only against the AdmServ PHP installation

## Why A Rewrite Is Better Than A Port

The old module depends on long-obsolete Zend APIs:

- `ARG_COUNT(ht)`
- `zend_get_parameters()`
- direct `zval->value.str.val` and `zval->value.lval`
- `register_list_destructors`
- `zend_list_insert`, `zend_list_find`
- old HashTable iteration APIs

The underlying i18n logic is simple enough that rebuilding the wrapper is cheaper and safer than dragging legacy Zend mechanics forward.

## Target Architecture

### Recommended File Layout

```text
platform/i18n/php/
  config.m4
  php_i18n.h
  i18n-php8-modernization.md
  src/
    i18n_module.c
    i18n_handle.c
    i18n_handle.h
    i18n_convert.c
    i18n_convert.h
    i18n_functions.c
```

### Responsibilities

`i18n_module.c`

- module entry
- exported function table
- MINIT/MINFO
- resource registration

`i18n_handle.c`

- `i18n_handle *` resource lifecycle
- destructor
- safe helper to fetch handle from PHP resource

`i18n_convert.c`

- PHP array -> `i18n_vars`
- `GSList` -> PHP indexed arrays

`i18n_functions.c`

- all `PHP_FUNCTION(...)` entry points
- parameter parsing
- calls to native i18n library

This keeps the code straightforward and avoids dumping everything into one giant file again.

## Build Strategy

### Use AdmServ PHP Only

Build specifically against:

```text
/home/solarspeed/admserv-php/bin/phpize
/home/solarspeed/admserv-php/bin/php-config
```

Do not depend on system PHP headers or system `php-config`.

### Recommended Build Flow

```bash
/home/solarspeed/admserv-php/bin/phpize
./configure --with-php-config=/home/solarspeed/admserv-php/bin/php-config
make
make install
```

### Extension Output Directory

Current AdmServ PHP extension dir:

```text
/home/solarspeed/admserv-php/lib/php/20230831
```

### Library Dependencies

The extension will need:

- i18n headers and library from BlueOnyx platform
- glib, if still required by the i18n library

The old hand-written Makefile should not be the primary build path for the new implementation.

## Compatibility Goal

Compatibility target:

- same PHP function names
- same argument order
- same general return types
- same semantics for optional empty-string arguments

Examples:

- `i18n_new(domain, locale)` should continue to accept empty strings as `NULL` equivalents where legacy behavior does so
- `i18n_get*()` family should keep returning strings
- `i18n_availlocales()` and `i18n_locales()` should return indexed PHP arrays of strings
- `i18n_interpolate*()` should continue to accept optional vars arrays

## Resource Model

The historic module returned a numeric list index from `i18n_new()`.

Important compatibility question:

- does userland code treat the return value as a plain integer handle
- or only as an opaque token passed back to the extension

If existing code relies only on opacity, modern PHP resources may be acceptable.
If code explicitly expects an integer, compatibility handling will be needed.

This should be verified before implementation.

## Difficulty Assessment

### Low Complexity

- `i18n_new`
- `i18n_get_property`
- `i18n_get_file`
- `i18n_strftime`
- `i18n_availlocales`
- `i18n_locales`

These are almost direct wrappers.

### Medium Complexity

- `i18n_get`
- `i18n_get_js`
- `i18n_get_html`

These mainly need optional-argument compatibility and string return handling.

### Slightly Higher Complexity

- `i18n_interpolate`
- `i18n_interpolate_js`
- `i18n_interpolate_html`

These require conversion of PHP arrays into native `i18n_vars`.

Even these are still much simpler than the data structure work in `cce.so`.

## Main Technical Risks

### 1. Handle Compatibility

As with the old `cce.so`, the historic code returns numeric handle ids.

If current userland depends on integer semantics, this must be preserved or carefully adapted.

### 2. Optional Argument Semantics

The old code treats some empty strings as `NULL`.

That behavior must be preserved or the GUI may subtly break.

### 3. String Ownership

The old code assumes some returned strings are managed by the i18n library and should not be freed by PHP extension code.

This needs to be checked carefully for each wrapped function.

### 4. Userland Expectations

The current GUI may have grown around quirks of the PHP fallback implementations.

The new extension should be benchmarked and behavior-compared before it replaces userland code globally.

## Proposed Phases

## Phase 0: Compatibility Survey

Goal:

- understand exactly how the GUI uses `i18n.so` semantics today

Tasks:

- inspect `I18n.php`, `I18nNative.php`, and `I18nExtension.php`
- record which functions are actually used
- confirm whether the handle is treated as numeric
- document empty-string vs `NULL` behavior expectations

Deliverable:

- short compatibility matrix

Estimated effort:

- `0.5 day`

## Phase 1: Skeleton Extension

Goal:

- produce a loadable PHP 8.3 extension

Tasks:

- modernize `config.m4`
- add new module entry
- implement `MINIT` and `MINFO`
- register resource type and destructor
- build and load with AdmServ PHP

Deliverable:

- extension loads via `extension=i18n.so`

Estimated effort:

- `0.5 day`

## Phase 2: Core Handle Path

Goal:

- validate native handle lifecycle

Functions:

- `i18n_new`
- handle lookup helper

Deliverable:

- create/destroy path works reliably

Estimated effort:

- `0.25-0.5 day`

## Phase 3: Simple Lookup Functions

Goal:

- restore the main translation lookup path quickly

Functions:

- `i18n_get`
- `i18n_get_js`
- `i18n_get_html`
- `i18n_get_property`
- `i18n_get_file`
- `i18n_strftime`

Deliverable:

- core lookup performance path available

Estimated effort:

- `1 day`

## Phase 4: Locale List Functions

Goal:

- implement array-return helpers

Functions:

- `i18n_availlocales`
- `i18n_locales`

Deliverable:

- indexed array returns verified

Estimated effort:

- `0.5 day`

## Phase 5: Interpolation Functions

Goal:

- restore variable substitution functions

Functions:

- `i18n_interpolate`
- `i18n_interpolate_js`
- `i18n_interpolate_html`

Tasks:

- PHP array -> `i18n_vars`
- string return handling

Estimated effort:

- `0.5-1 day`

## Phase 6: Validation And Benchmarks

Goal:

- verify compatibility and measure actual speed improvement

Tasks:

- compare outputs against current PHP userland implementation
- benchmark repeated i18n lookups through:
  - current PHP classes
  - new native extension

Deliverable:

- benchmark note and rollout recommendation

Estimated effort:

- `0.5-1 day`

## Testing Plan

### CLI Load Test

```bash
/home/solarspeed/admserv-php/bin/php -d extension=i18n.so -m
```

### Functional Tests

Create tests for:

- new handle creation
- `get`, `get_js`, `get_html`
- `get_property`
- `get_file`
- `availlocales`
- `locales`
- `strftime`
- all three interpolate variants

### Compatibility Checks

For a fixed locale/domain/tag set, compare:

- current PHP fallback result
- new extension result

Comparison should include:

- exact returned string content
- escaping behavior in JS/HTML variants
- interpolation output
- array contents and ordering for locale-list functions

### Performance Tests

Benchmark representative GUI localization workloads:

- repeated tag lookup
- repeated interpolation
- mixed lookup pages with many translated elements

## Suggested Implementation Order

Recommended sequence:

1. Build skeleton
2. Handle resource management
3. `i18n_new`
4. `i18n_get`, `i18n_get_js`, `i18n_get_html`
5. `i18n_get_property`, `i18n_get_file`, `i18n_strftime`
6. `i18n_availlocales`, `i18n_locales`
7. interpolation conversion helpers
8. `i18n_interpolate`, `i18n_interpolate_js`, `i18n_interpolate_html`
9. benchmark and GUI validation

This order restores the most performance-critical lookup path early.

## What Not To Do

- do not try to preserve old Zend internals
- do not use system PHP build tools by accident
- do not optimize for multiple PHP versions first
- do not redesign the public API during the rewrite

## Estimated Overall Effort

For a clean PHP 8.3 replacement:

- best case: `2-3 days`
- realistic case: `3-5 days`
- with full GUI rollout confidence: `up to 1 week`

## Recommended Decision

If GUI localization is currently a measurable bottleneck, rebuilding `i18n.so` is a strong candidate for near-term work.

Compared with modernizing `cce.so`, this project is:

- smaller
- lower risk
- faster to complete
- likely to yield visible performance improvements quickly

## Next Step

Do Phase 0 first:

- inspect current GUI callers
- verify handle expectations
- identify which functions are actually hot

That will keep the rewrite focused and make it easier to prove the speed benefit after implementation.
