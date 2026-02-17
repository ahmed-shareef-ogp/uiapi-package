<?php

namespace Ogp\UiApi\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ViewConfigValidator
{
    /**
     * Known component config files that ship with the package.
     *
     * @var array<int, string>
     */
    protected array $knownComponentConfigs = [
        'table',
        'form',
        'toolbar',
        'filterSection',
        'meta',
    ];

    /**
     * Validation results.
     *
     * @var array{errors: array<int, array{path: string, rule: string, message: string}>, warnings: array<int, array{path: string, rule: string, message: string}>}
     */
    protected array $results = [
        'errors' => [],
        'warnings' => [],
    ];

    /**
     * The columns schema resolved from the model's apiSchema() or from noModel columnsSchema.
     *
     * @var array<string, array>|null
     */
    protected ?array $columnsSchema = null;

    /**
     * Validate a full view config array for a given model name.
     *
     * @return array{errors: array, warnings: array}
     */
    public function validate(array $viewConfig, string $modelName): array
    {
        $this->results = ['errors' => [], 'warnings' => []];
        $this->columnsSchema = null;

        if (empty($viewConfig)) {
            $this->addError('(root)', 'not_empty', "View config for '{$modelName}' is empty.");

            return $this->results;
        }

        foreach ($viewConfig as $componentKey => $compBlock) {
            if (! is_array($compBlock)) {
                continue;
            }

            $prefix = $componentKey;
            $this->resolveColumnsSchemaForBlock($compBlock, $modelName);
            $this->validateCompBlock($compBlock, $prefix, $modelName);
        }

        return $this->results;
    }

    /**
     * Try to resolve the columnsSchema for the current compBlock.
     * Model-backed: uses apiSchema(). noModel: uses inline columnsSchema.
     */
    protected function resolveColumnsSchemaForBlock(array $compBlock, string $modelName): void
    {
        $isNoModel = (bool) ($compBlock['noModel'] ?? false);

        if ($isNoModel) {
            $this->columnsSchema = is_array($compBlock['columnsSchema'] ?? null) ? $compBlock['columnsSchema'] : null;

            return;
        }

        // Try to resolve model and get its apiSchema columns
        $this->columnsSchema = $this->resolveModelColumnsSchema($modelName);
    }

    /**
     * Attempt to resolve a model's apiSchema columns by class name.
     *
     * @return array<string, array>|null
     */
    protected function resolveModelColumnsSchema(string $modelName): ?array
    {
        $names = array_values(array_unique([
            ucfirst(strtolower($modelName)),
            Str::studly($modelName),
            Str::studly(str_replace(['-', ' ', '.'], '_', $modelName)),
        ]));

        foreach ($names as $name) {
            foreach (["Ogp\\UiApi\\Models\\{$name}", "App\\Models\\{$name}"] as $fqcn) {
                if (class_exists($fqcn)) {
                    $instance = new $fqcn;
                    if (method_exists($instance, 'apiSchema')) {
                        $schema = $instance->apiSchema();

                        return is_array($schema['columns'] ?? null) ? $schema['columns'] : null;
                    }
                }
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  Rule definitions
    // ──────────────────────────────────────────────
    //
    //  Each rule is a separate protected method prefixed with "rule".
    //  To add a new rule, create a new method and call it from the
    //  appropriate validate* orchestrator below.
    //
    //  To remove a rule, simply comment out or delete its call.
    // ──────────────────────────────────────────────

    /**
     * Validate a single component block (e.g. "listView" or "listView2").
     */
    protected function validateCompBlock(array $compBlock, string $prefix, string $modelName): void
    {
        // ── Structural rules ──
        $this->ruleLangRequired($compBlock, $prefix);
        $this->rulePerPagePositive($compBlock, $prefix);
        $this->ruleNoModelRequiresColumnsSchema($compBlock, $prefix);
        $this->ruleColumnsRequired($compBlock, $prefix);
        $this->ruleComponentsHaveConfigFiles($compBlock, $prefix);

        // ── Column reference rules ──
        $this->ruleColumnsReferenceValidKeys($compBlock, $prefix);
        $this->ruleFiltersReferenceValidKeys($compBlock, $prefix);
        $this->ruleColumnCustomizationsReferenceValidKeys($compBlock, $prefix);

        // ── Component-specific rules ──
        $components = is_array($compBlock['components'] ?? null) ? $compBlock['components'] : [];
        foreach ($components as $compName => $compConfig) {
            if (! is_array($compConfig)) {
                continue;
            }
            $compPrefix = "{$prefix}.components.{$compName}";

            if ($compName === 'form') {
                // Form has its own comprehensive validation (includes functions)
                $this->validateFormComponent($compConfig, $compPrefix);
            } else {
                // Validate functions inside non-form components
                if (is_array($compConfig['functions'] ?? null)) {
                    $this->ruleFunctionsRequireFileAndFunction($compConfig['functions'], $compPrefix);
                }
            }

            // Validate columnCustomizations inside any component
            if (is_array($compConfig['columnCustomizations'] ?? null)) {
                $this->validateColumnCustomizations($compConfig['columnCustomizations'], $compPrefix);
            }
        }

        // ── Root-level columnCustomizations ──
        if (is_array($compBlock['columnCustomizations'] ?? null)) {
            $this->validateColumnCustomizations($compBlock['columnCustomizations'], $prefix);
        }

        // ── Root-level columnsSchema select/filterable dependencies ──
        $this->validateColumnsSchemaSelectDependencies($compBlock, $prefix);
    }

    /**
     * Validate the form component block specifically.
     */
    protected function validateFormComponent(array $formConfig, string $prefix): void
    {
        $this->ruleFormFieldsGroupMustMatchGroupsName($formConfig, $prefix);
        $this->ruleGroupsNameUnique($formConfig, $prefix);
        $this->ruleGroupsTitleLocalized($formConfig, $prefix);
        $this->ruleSearchFieldRequiresSubmitUrl($formConfig, $prefix);
        $this->ruleFieldEventsMatchFunctions($formConfig, $prefix);

        // Validate functions inside form
        if (is_array($formConfig['functions'] ?? null)) {
            $this->ruleFunctionsRequireFileAndFunction($formConfig['functions'], $prefix);
        }
    }

    /**
     * Validate all columnCustomization entries for displayType/select dependencies.
     */
    protected function validateColumnCustomizations(array $customizations, string $prefix): void
    {
        foreach ($customizations as $colKey => $custProps) {
            if (! is_array($custProps)) {
                continue;
            }
            $custPrefix = "{$prefix}.columnCustomizations.{$colKey}";

            $this->ruleDisplayTypeRequiresMatchingSubKey($custProps, $custPrefix);
            $this->ruleSelectInputTypeRequiresSelectConfig($custProps, $custPrefix);
        }
    }

    /**
     * Validate columnsSchema entries for select/filterable dependencies
     * (applies to noModel columnsSchema only, since model schemas are in PHP).
     */
    protected function validateColumnsSchemaSelectDependencies(array $compBlock, string $prefix): void
    {
        $schema = is_array($compBlock['columnsSchema'] ?? null) ? $compBlock['columnsSchema'] : null;
        if (! $schema) {
            return;
        }

        foreach ($schema as $colKey => $colDef) {
            if (! is_array($colDef)) {
                continue;
            }
            $colPrefix = "{$prefix}.columnsSchema.{$colKey}";

            $this->ruleSelectInputTypeRequiresSelectConfig($colDef, $colPrefix);
            $this->ruleDisplayTypeRequiresMatchingSubKey($colDef, $colPrefix);
        }
    }

    // ──────────────────────────────────────────────
    //  Individual rules
    // ──────────────────────────────────────────────

    /**
     * RULE 1: "lang" must exist and be a non-empty array.
     */
    protected function ruleLangRequired(array $compBlock, string $prefix): void
    {
        $lang = $compBlock['lang'] ?? null;

        if ($lang === null) {
            $this->addError("{$prefix}.lang", 'required', '"lang" key is missing. It must be a non-empty array (e.g. ["en", "dv"]).');

            return;
        }

        if (! is_array($lang) || empty($lang)) {
            $this->addError("{$prefix}.lang", 'required_array', '"lang" must be a non-empty array (e.g. ["en", "dv"]).');
        }
    }

    /**
     * RULE 2: "columns" must exist either at root level or inside components.table.columns
     * when a "table" component is used.
     */
    protected function ruleColumnsRequired(array $compBlock, string $prefix): void
    {
        $components = is_array($compBlock['components'] ?? null) ? $compBlock['components'] : [];
        $hasTable = array_key_exists('table', $components);

        if (! $hasTable) {
            return;
        }

        $rootColumns = $compBlock['columns'] ?? null;
        $tableComponent = is_array($components['table'] ?? null) ? $components['table'] : [];
        $tableColumns = $tableComponent['columns'] ?? null;

        $hasRootColumns = is_array($rootColumns) && ! empty($rootColumns);
        $hasTableColumns = is_array($tableColumns) && ! empty($tableColumns);

        if (! $hasRootColumns && ! $hasTableColumns) {
            $this->addError(
                "{$prefix}.columns",
                'columns_required',
                '"columns" must be defined either at the root level or inside "components.table.columns" when using a table component.'
            );
        }
    }

    /**
     * RULE 3: "per_page" should be a positive integer.
     */
    protected function rulePerPagePositive(array $compBlock, string $prefix): void
    {
        if (! array_key_exists('per_page', $compBlock)) {
            return;
        }

        $perPage = $compBlock['per_page'];

        if (! is_int($perPage) || $perPage <= 0) {
            $this->addWarning("{$prefix}.per_page", 'positive_integer', '"per_page" should be a positive integer. Got: '.json_encode($perPage));
        }
    }

    /**
     * RULE 4: Filter entries must reference keys that exist in the columnsSchema or apiSchema.
     */
    protected function ruleFiltersReferenceValidKeys(array $compBlock, string $prefix): void
    {
        $filters = $compBlock['filters'] ?? null;
        if (! is_array($filters) || $this->columnsSchema === null) {
            return;
        }

        foreach ($filters as $index => $filterKey) {
            if (! is_string($filterKey)) {
                continue;
            }
            if (! array_key_exists($filterKey, $this->columnsSchema)) {
                $this->addWarning(
                    "{$prefix}.filters[{$index}]",
                    'filter_key_exists',
                    "Filter \"{$filterKey}\" does not reference a known column in the schema."
                );
            }
        }
    }

    /**
     * RULE 5: "noModel: true" requires "columnsSchema" to be a non-empty object.
     */
    protected function ruleNoModelRequiresColumnsSchema(array $compBlock, string $prefix): void
    {
        $isNoModel = (bool) ($compBlock['noModel'] ?? false);
        if (! $isNoModel) {
            return;
        }

        $schema = $compBlock['columnsSchema'] ?? null;

        if (! is_array($schema) || empty($schema)) {
            $this->addError(
                "{$prefix}.columnsSchema",
                'nomodel_requires_schema',
                '"noModel" is true but "columnsSchema" is missing or empty. A non-empty columnsSchema is required.'
            );
        }
    }

    /**
     * RULE 6: When "inputType" is "select", a "select" or "filterable" config must exist.
     */
    protected function ruleSelectInputTypeRequiresSelectConfig(array $def, string $prefix): void
    {
        $inputType = strtolower((string) ($def['inputType'] ?? ''));
        if ($inputType !== 'select') {
            return;
        }

        $hasSelect = is_array($def['select'] ?? null) && ! empty($def['select']);
        $hasFilterable = is_array($def['filterable'] ?? null) && ! empty($def['filterable']);

        if (! $hasSelect && ! $hasFilterable) {
            $this->addError(
                "{$prefix}",
                'select_requires_config',
                '"inputType" is "select" but neither "select" nor "filterable" configuration is defined. A select/filterable block with mode, items or relationship is required.'
            );

            return;
        }

        // Validate the select/filterable sub-object
        $selectCfg = $def['select'] ?? ($def['filterable'] ?? []);
        $this->ruleSelectModeRequirements($selectCfg, $prefix);
    }

    /**
     * RULE 7 & 8: Select mode-specific requirements.
     * - mode "self" requires "items" (non-empty array)
     * - mode "relation" requires "relationship" string
     */
    protected function ruleSelectModeRequirements(array $cfg, string $prefix): void
    {
        $mode = strtolower((string) ($cfg['mode'] ?? 'self'));

        if ($mode === 'self') {
            $items = $cfg['items'] ?? null;
            if (! is_array($items) || empty($items)) {
                $this->addWarning(
                    "{$prefix}.select",
                    'self_mode_requires_items',
                    'Select mode is "self" but "items" is missing or empty. The dropdown will have no options.'
                );
            }
        } else {
            // Relation mode
            $relationship = $cfg['relationship'] ?? null;
            if (! is_string($relationship) || $relationship === '') {
                $this->addWarning(
                    "{$prefix}.select",
                    'relation_mode_requires_relationship',
                    'Select mode is "relation" but "relationship" is missing. The system will attempt to guess from the key name.'
                );
            }
        }
    }

    /**
     * RULE 9 & 11: "displayType" requires a matching sub-key or "displayProps".
     * e.g. displayType: "chip" requires a "chip" sub-object.
     */
    protected function ruleDisplayTypeRequiresMatchingSubKey(array $def, string $prefix): void
    {
        $displayType = $def['displayType'] ?? null;
        if (! is_string($displayType) || $displayType === '') {
            return;
        }

        $dt = strtolower($displayType);

        // These displayTypes are expected to have a matching sub-key or displayProps
        $typesNeedingConfig = ['chip', 'select'];

        if (! in_array($dt, $typesNeedingConfig, true)) {
            return;
        }

        $hasMatchingKey = is_array($def[$displayType] ?? null) && ! empty($def[$displayType]);
        $hasDisplayProps = is_array($def['displayProps'] ?? null) && ! empty($def['displayProps']);

        if (! $hasMatchingKey && ! $hasDisplayProps) {
            $this->addWarning(
                $prefix,
                'displaytype_requires_config',
                "\"displayType\" is \"{$displayType}\" but neither a \"{$displayType}\" sub-key nor \"displayProps\" is defined. Display may fall back to plain text."
            );
        }
    }

    /**
     * RULE 12: Function definitions that are objects must have "file" and "function" keys.
     */
    protected function ruleFunctionsRequireFileAndFunction(array $functions, string $prefix): void
    {
        foreach ($functions as $funcName => $definition) {
            if (is_string($definition)) {
                continue;
            }

            if (! is_array($definition)) {
                $this->addWarning(
                    "{$prefix}.functions.{$funcName}",
                    'function_type',
                    "Function \"{$funcName}\" should be either a string or an object with \"file\" and \"function\" keys."
                );

                continue;
            }

            if (! isset($definition['file'])) {
                $this->addError(
                    "{$prefix}.functions.{$funcName}",
                    'function_requires_file',
                    "Function \"{$funcName}\" is missing the \"file\" key (e.g. \"misc.js\")."
                );
            }

            if (! isset($definition['function'])) {
                $this->addError(
                    "{$prefix}.functions.{$funcName}",
                    'function_requires_function',
                    "Function \"{$funcName}\" is missing the \"function\" key (the JS function name to extract)."
                );
            }
        }
    }

    /**
     * RULE 13: Filterable config with select type follows the same rules as inputType select.
     * This is handled via ruleSelectInputTypeRequiresSelectConfig — included for completeness.
     * Filterable blocks with type "select" inside columnsSchema are validated there.
     */

    /**
     * RULE 15: Form fields[].group must match one of the groups[].name values.
     */
    protected function ruleFormFieldsGroupMustMatchGroupsName(array $formConfig, string $prefix): void
    {
        $groups = $formConfig['groups'] ?? null;
        $fields = $formConfig['fields'] ?? null;

        if (! is_array($groups) || ! is_array($fields)) {
            return;
        }

        $groupNames = [];
        foreach ($groups as $group) {
            if (is_array($group) && isset($group['name'])) {
                $groupNames[] = (string) $group['name'];
            }
        }

        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                continue;
            }
            $fieldGroup = $field['group'] ?? null;
            if ($fieldGroup === null) {
                continue;
            }
            if (! in_array((string) $fieldGroup, $groupNames, true)) {
                $fieldKey = $field['key'] ?? "index:{$index}";
                $this->addWarning(
                    "{$prefix}.fields[{$index}]",
                    'field_group_exists',
                    "Field \"{$fieldKey}\" references group \"{$fieldGroup}\" which is not defined in \"groups\". Available groups: ".implode(', ', $groupNames).'.'
                );
            }
        }
    }

    /**
     * RULE 16: groups[].name must be unique.
     */
    protected function ruleGroupsNameUnique(array $formConfig, string $prefix): void
    {
        $groups = $formConfig['groups'] ?? null;
        if (! is_array($groups)) {
            return;
        }

        $seen = [];
        foreach ($groups as $index => $group) {
            if (! is_array($group) || ! isset($group['name'])) {
                continue;
            }
            $name = (string) $group['name'];
            if (in_array($name, $seen, true)) {
                $this->addWarning(
                    "{$prefix}.groups[{$index}]",
                    'group_name_unique',
                    "Duplicate group name \"{$name}\". Group names should be unique."
                );
            }
            $seen[] = $name;
        }
    }

    /**
     * RULE 17: groups[].title should be a localized {en, dv} object.
     */
    protected function ruleGroupsTitleLocalized(array $formConfig, string $prefix): void
    {
        $groups = $formConfig['groups'] ?? null;
        if (! is_array($groups)) {
            return;
        }

        foreach ($groups as $index => $group) {
            if (! is_array($group)) {
                continue;
            }

            $title = $group['title'] ?? null;
            $name = $group['name'] ?? "index:{$index}";

            if ($title === null) {
                $this->addWarning(
                    "{$prefix}.groups[{$index}]",
                    'group_title_required',
                    "Group \"{$name}\" is missing a \"title\". It should be a localized object like {\"en\": \"...\", \"dv\": \"...\"}."
                );

                continue;
            }

            if (! is_array($title)) {
                $this->addWarning(
                    "{$prefix}.groups[{$index}]",
                    'group_title_localized',
                    "Group \"{$name}\" title should be a localized object {\"en\": \"...\", \"dv\": \"...\"} instead of a plain string."
                );

                continue;
            }

            if (! array_key_exists('en', $title) || ! array_key_exists('dv', $title)) {
                $missing = [];
                if (! array_key_exists('en', $title)) {
                    $missing[] = 'en';
                }
                if (! array_key_exists('dv', $title)) {
                    $missing[] = 'dv';
                }
                $this->addWarning(
                    "{$prefix}.groups[{$index}]",
                    'group_title_langs',
                    "Group \"{$name}\" title is missing language(s): ".implode(', ', $missing).'.'
                );
            }
        }
    }

    /**
     * RULE 19: fields[].inputType: "search" requires "submitUrl".
     */
    protected function ruleSearchFieldRequiresSubmitUrl(array $formConfig, string $prefix): void
    {
        $fields = $formConfig['fields'] ?? null;
        if (! is_array($fields)) {
            return;
        }

        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                continue;
            }

            $inputType = strtolower((string) ($field['inputType'] ?? ''));
            if ($inputType !== 'search') {
                continue;
            }

            $submitUrl = $field['submitUrl'] ?? null;
            if (! is_string($submitUrl) || $submitUrl === '') {
                $fieldKey = $field['key'] ?? "index:{$index}";
                $this->addError(
                    "{$prefix}.fields[{$index}]",
                    'search_requires_submiturl',
                    "Field \"{$fieldKey}\" has inputType \"search\" but is missing \"submitUrl\". A search field requires a URL to submit search queries to."
                );
            }
        }
    }

    /**
     * RULE 20: fields[].events.* values should match keys in the form's "functions" block.
     */
    protected function ruleFieldEventsMatchFunctions(array $formConfig, string $prefix): void
    {
        $fields = $formConfig['fields'] ?? null;
        $functions = $formConfig['functions'] ?? null;

        if (! is_array($fields)) {
            return;
        }

        $functionNames = is_array($functions) ? array_keys($functions) : [];

        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                continue;
            }

            $events = $field['events'] ?? null;
            if (! is_array($events)) {
                continue;
            }

            foreach ($events as $eventName => $handlerName) {
                if (! is_string($handlerName)) {
                    continue;
                }
                if (! in_array($handlerName, $functionNames, true)) {
                    $fieldKey = $field['key'] ?? "index:{$index}";
                    $this->addWarning(
                        "{$prefix}.fields[{$index}].events.{$eventName}",
                        'event_handler_exists',
                        "Field \"{$fieldKey}\" event \"{$eventName}\" references handler \"{$handlerName}\" which is not defined in \"functions\"."
                    );
                }
            }
        }
    }

    /**
     * RULE 21: columnCustomizations keys should reference actual columns in the schema,
     * or be explicitly marked as custom columns (having displayType "custom" or a "columnData" key).
     */
    protected function ruleColumnCustomizationsReferenceValidKeys(array $compBlock, string $prefix): void
    {
        $customizations = $compBlock['columnCustomizations'] ?? null;
        if (! is_array($customizations) || $this->columnsSchema === null) {
            return;
        }

        // Gather all known column keys: from schema + from columns arrays
        $knownKeys = array_keys($this->columnsSchema);
        $rootColumns = $compBlock['columns'] ?? [];
        if (is_array($rootColumns)) {
            $knownKeys = array_merge($knownKeys, $rootColumns);
        }
        $components = is_array($compBlock['components'] ?? null) ? $compBlock['components'] : [];
        foreach ($components as $comp) {
            if (is_array($comp) && is_array($comp['columns'] ?? null)) {
                $knownKeys = array_merge($knownKeys, $comp['columns']);
            }
        }
        $knownKeys = array_unique($knownKeys);

        foreach ($customizations as $colKey => $custProps) {
            if (! is_array($custProps)) {
                continue;
            }

            // Skip if explicitly a custom column
            $isCustom = ($custProps['displayType'] ?? null) === 'custom'
                || array_key_exists('columnData', $custProps);
            if ($isCustom) {
                continue;
            }

            if (! in_array($colKey, $knownKeys, true)) {
                $this->addWarning(
                    "{$prefix}.columnCustomizations.{$colKey}",
                    'customization_key_exists',
                    "Column customization \"{$colKey}\" does not match any known column in the schema or columns list. If this is intentional, set displayType to \"custom\"."
                );
            }
        }
    }

    /**
     * RULE 24: Filter entries must reference columns that exist in the schema.
     * (This is the same as ruleFiltersReferenceValidKeys — included in validateCompBlock.)
     */

    /**
     * RULE 27: "columns" entries (table/root) should reference valid columnsSchema or apiSchema keys.
     * Dot-notation columns are checked for their first segment only.
     */
    protected function ruleColumnsReferenceValidKeys(array $compBlock, string $prefix): void
    {
        if ($this->columnsSchema === null) {
            return;
        }

        // Check root columns
        $rootColumns = $compBlock['columns'] ?? null;
        if (is_array($rootColumns)) {
            $this->checkColumnReferences($rootColumns, $this->columnsSchema, "{$prefix}.columns");
        }

        // Check table-specific columns
        $components = is_array($compBlock['components'] ?? null) ? $compBlock['components'] : [];
        foreach ($components as $compName => $compConfig) {
            if (! is_array($compConfig)) {
                continue;
            }
            $compColumns = $compConfig['columns'] ?? null;
            if (is_array($compColumns)) {
                $this->checkColumnReferences($compColumns, $this->columnsSchema, "{$prefix}.components.{$compName}.columns");
            }
        }
    }

    /**
     * Check an array of column references against a schema.
     */
    protected function checkColumnReferences(array $columns, array $schema, string $prefix): void
    {
        foreach ($columns as $index => $colRef) {
            if (! is_string($colRef)) {
                continue;
            }

            // Dot-notation: check only that the base column key exists in schema or is a plausible relation
            if (Str::contains($colRef, '.')) {
                // Dot columns are relation references — first segment is a relation, not a schema column
                // We skip deep validation here since the relation might not be resolvable without a model instance
                continue;
            }

            if (! array_key_exists($colRef, $schema)) {
                $this->addWarning(
                    "{$prefix}[{$index}]",
                    'column_exists_in_schema',
                    "Column \"{$colRef}\" is not defined in the schema (apiSchema or columnsSchema)."
                );
            }
        }
    }

    /**
     * RULE 30: Component keys should match available component config files.
     */
    protected function ruleComponentsHaveConfigFiles(array $compBlock, string $prefix): void
    {
        $components = $compBlock['components'] ?? null;
        if (! is_array($components)) {
            return;
        }

        $configDir = __DIR__.'/ComponentConfigs';

        foreach (array_keys($components) as $compName) {
            if (! is_string($compName)) {
                continue;
            }

            // Check if a config file exists
            $configPath = $configDir.'/'.basename($compName).'.json';
            if (! File::exists($configPath)) {
                // It's not a fatal error since components may be pass-through, but worth noting
                $this->addWarning(
                    "{$prefix}.components.{$compName}",
                    'component_config_exists',
                    "Component \"{$compName}\" does not have a matching config file in ComponentConfigs/. Expected: {$compName}.json"
                );
            }
        }
    }

    // ──────────────────────────────────────────────
    //  Result helpers
    // ──────────────────────────────────────────────

    protected function addError(string $path, string $rule, string $message): void
    {
        $this->results['errors'][] = [
            'path' => $path,
            'rule' => $rule,
            'message' => $message,
        ];
    }

    protected function addWarning(string $path, string $rule, string $message): void
    {
        $this->results['warnings'][] = [
            'path' => $path,
            'rule' => $rule,
            'message' => $message,
        ];
    }

    /**
     * Check if the last validation had any errors.
     */
    public function hasErrors(): bool
    {
        return ! empty($this->results['errors']);
    }

    /**
     * Check if the last validation had any warnings.
     */
    public function hasWarnings(): bool
    {
        return ! empty($this->results['warnings']);
    }

    /**
     * Check if the last validation had any issues at all.
     */
    public function hasIssues(): bool
    {
        return $this->hasErrors() || $this->hasWarnings();
    }

    /**
     * Get the results from the last validation.
     *
     * @return array{errors: array, warnings: array}
     */
    public function getResults(): array
    {
        return $this->results;
    }
}
