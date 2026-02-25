# View Config Validator

Validates view config JSON files for structural issues, missing keys, and interdependency problems. Catches misconfigurations early — before they surface as confusing runtime bugs.

Two entry points:
1. **Artisan command** — `php artisan uiapi:validate` for CLI validation
2. **Runtime integration** — automatic validation during CCS requests when `debug_level >= 2`

---

## Artisan Command

### Validate all view configs
```bash
php artisan uiapi:validate
```
Scans every `*.json` file in `config('uiapi.view_configs_path')` (default `app/Services/viewConfigs`). Files with " copy" in the name are skipped.

### Validate a single model
```bash
php artisan uiapi:validate person
php artisan uiapi:validate cform
```
Model name is normalized the same way as CCS — `person`, `Person`, `PERSON` all resolve to the same file.

### Output
```
  Validating all view config files...

  person ···················································· PASS
  cform ··················· 1 error(s), 3 warning(s)
    ✗ [listView.lang] "lang" key is missing. It must be a non-empty array (e.g. ["en", "dv"]).
    ⚠ [listView.per_page] "per_page" should be a positive integer. Got: -5
    ⚠ [listView.filters[0]] Filter "foo" does not reference a known column in the schema.
    ⚠ [listView.components.table] Component "table" does not have a matching config file.

  Validation Summary
  1 error(s), 3 warning(s)
```
Exit code is `1` when errors are present, `0` otherwise (warnings alone don't cause failure).

---

## Runtime Integration (CCS)

When `config('uiapi.debug_level')` is `2`, the `ComponentConfigService` runs the validator on every CCS request:

- **Errors** → immediate `422` JSON response:
  ```json
  {
    "error": "View config validation failed for 'person'.",
    "validation": { "errors": [...], "warnings": [...] }
  }
  ```
- **Warnings only** → request proceeds normally. Warnings are appended to the response under `_validation_warnings`.

Set `debug_level` to `0` or `1` in production to skip validation entirely.

---

## Programmatic Usage

```php
use Ogp\UiApi\Services\ViewConfigValidator;

$validator = new ViewConfigValidator();
$results = $validator->validate($viewConfigArray, 'person');

// $results = ['errors' => [...], 'warnings' => [...]]

$validator->hasErrors();    // bool
$validator->hasWarnings();  // bool
$validator->hasIssues();    // either errors or warnings
$validator->getResults();   // same as $results
```

Each error/warning is an array with:
| Key       | Description                                        |
|-----------|----------------------------------------------------|
| `path`    | Dot-notation location, e.g. `listView.lang`        |
| `rule`    | Machine-readable rule name, e.g. `required`         |
| `message` | Human-readable explanation                          |

---

## Validation Rules

### Errors (block the request at debug_level 2)

| #  | Rule                            | Path example                           | Trigger                                                                 |
|----|---------------------------------|----------------------------------------|-------------------------------------------------------------------------|
| 1  | `required` / `required_array`   | `listView.lang`                        | `lang` key is missing or is not a non-empty array.                      |
| 2  | `columns_required`              | `listView.columns`                     | Table component is used but `columns` is missing from both root and `components.table.columns`. |
| 5  | `nomodel_requires_schema`       | `listView.columnsSchema`               | `noModel: true` but `columnsSchema` is missing or empty.                |
| 6  | `select_requires_config`        | `listView.columnsSchema.country_id`    | `inputType: "select"` but neither `select` nor `filterable` block defined. |
| 12 | `function_requires_file`        | `listView.components.form.functions.X` | Function object is missing the `file` key.                              |
| 12 | `function_requires_function`    | `listView.components.form.functions.X` | Function object is missing the `function` key.                          |
| 19 | `search_requires_submiturl`     | `listView.components.form.fields[0]`   | Field has `inputType: "search"` but no `submitUrl`.                     |

### Warnings (included in response but don't block)

| #  | Rule                              | Path example                                         | Trigger                                                                     |
|----|-----------------------------------|------------------------------------------------------|-----------------------------------------------------------------------------|
| 3  | `positive_integer`                | `listView.per_page`                                  | `per_page` is not a positive integer.                                       |
| 4  | `filter_key_exists`               | `listView.filters[0]`                                | Filter references a column not in the schema.                               |
| 7  | `self_mode_requires_items`        | `listView.columnsSchema.X.select`                    | Select mode "self" but `items` is missing/empty.                            |
| 8  | `relation_mode_requires_relationship` | `listView.columnsSchema.X.select`                | Select mode "relation" but `relationship` is missing.                       |
| 9  | `displaytype_requires_config`     | `listView.columnCustomizations.X`                    | `displayType: "chip"` or `"select"` with no matching sub-key or `displayProps`. |
| 15 | `field_group_exists`              | `listView.components.form.fields[0]`                 | Field `group` doesn't match any `groups[].name`.                            |
| 16 | `group_name_unique`               | `listView.components.form.groups[1]`                 | Duplicate group name in `groups` array.                                     |
| 17 | `group_title_required` / `group_title_localized` / `group_title_langs` | `listView.components.form.groups[0]` | Group title is missing, is a plain string instead of `{en, dv}`, or is missing a language key. |
| 20 | `event_handler_exists`            | `listView.components.form.fields[0].events.onChange`  | Event handler references a function name not defined in `functions`.         |
| 21 | `customization_key_exists`        | `listView.columnCustomizations.foo`                  | Customization key doesn't match any known column. Set `displayType: "custom"` to suppress. |
| 27 | `column_exists_in_schema`         | `listView.columns[3]`                                | Column entry not found in the schema. Dot-notation columns are skipped.     |
| 30 | `component_config_exists`         | `listView.components.myWidget`                       | No matching `.json` config file in `ComponentConfigs/`.                     |

---

## Schema Resolution

The validator needs a columns schema to check cross-references (rules 4, 21, 27). It resolves schema in this order:

1. **noModel mode** — uses the inline `columnsSchema` from the view config block.
2. **Model mode** — instantiates the model (checking `Ogp\UiApi\Models\{Model}` then `App\Models\{Model}`) and calls `apiSchema()` to get the `columns` map.

If no schema can be resolved, cross-reference rules are silently skipped — they don't produce false positives.

---

## Adding or Removing Rules

Rules are individual `protected` methods prefixed with `rule` inside `ViewConfigValidator.php`. They're called from orchestrator methods:

| Orchestrator                            | Scope                                             |
|-----------------------------------------|---------------------------------------------------|
| `validateCompBlock()`                   | Structural, column references, component iteration |
| `validateFormComponent()`               | Form-specific: groups, fields, events, functions   |
| `validateColumnCustomizations()`        | Per-column displayType / select dependencies       |
| `validateColumnsSchemaSelectDependencies()` | noModel columnsSchema select/filterable checks |

**To add a rule:** create a new `protected function ruleYourRuleName(...)` method and call it from the appropriate orchestrator. Use `$this->addError()` or `$this->addWarning()` to record issues.

**To remove a rule:** comment out or delete its call in the orchestrator. The method can remain for reference.

---

## File Locations

| File                                                      | Purpose                    |
|-----------------------------------------------------------|----------------------------|
| `src/Services/ViewConfigValidator.php`                    | Validator service class    |
| `src/Console/Commands/ValidateViewConfigCommand.php`      | Artisan command            |
| `src/Services/ComponentConfigService.php`                 | Runtime integration (CCS)  |
| `config/uiapi.php` → `debug_level`                       | Controls runtime validation |
