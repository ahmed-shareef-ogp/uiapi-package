<?php

namespace Ogp\UiApi\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ComponentConfigService
{
    protected bool $includeHiddenColumnsInHeaders = false;

    protected bool $includeTopLevelHeaders = false;

    protected bool $includeTopLevelFilters = false;

    protected bool $includeTopLevelPagination = false;

    protected bool $loggingEnabled = false;

    protected bool $allowCustomComponentKeys = false;

    protected int $debugLevel = 1;

    /**
     * Keys consumed internally by CCS and stripped from the final payload.
     *
     * @var array<int, string>
     */
    protected array $internalKeys = [
        'columnCustomizations',
        'columns',
        'per_page',
        'filters',
        'lang',
    ];

    /**
     * Payload keys that may be defined as {dv,en} and should be collapsed
     * down to the request language.
     *
     * @var array<int, string>
     */
    protected array $localizedPayloadKeys = [
        'createTitle',
        'editTitle',
        'title',
        'label',
    ];

    public function __construct()
    {
        // Read logging flag safely without triggering missing-config exceptions
        $cfg = config('uiapi');
        $this->loggingEnabled = is_array($cfg) && array_key_exists('logging_enabled', $cfg)
            ? (bool) $cfg['logging_enabled']
            : false;
        $this->allowCustomComponentKeys = is_array($cfg) && array_key_exists('allow_custom_component_keys', $cfg)
            ? (bool) $cfg['allow_custom_component_keys']
            : false;
        $this->debugLevel = is_array($cfg) && array_key_exists('debug_level', $cfg)
            ? (int) $cfg['debug_level']
            : 1;
        $this->logDebug('ComponentConfigService initialized', ['method' => __METHOD__]);
    }

    public function setIncludeHiddenColumnsInHeaders(bool $value): void
    {
        $this->includeHiddenColumnsInHeaders = $value;
    }

    public function getIncludeTopLevelHeaders(): bool
    {
        return $this->includeTopLevelHeaders;
    }

    public function getIncludeTopLevelFilters(): bool
    {
        return $this->includeTopLevelFilters;
    }

    public function getIncludeTopLevelPagination(): bool
    {
        return $this->includeTopLevelPagination;
    }

    public function setLoggingEnabled(bool $enabled): void
    {
        $this->loggingEnabled = $enabled;
    }

    protected function isLoggingEnabled(): bool
    {
        return $this->loggingEnabled === true;
    }

    public function setAllowCustomComponentKeys(bool $value): void
    {
        $this->allowCustomComponentKeys = $value;
    }

    public function getAllowCustomComponentKeys(): bool
    {
        return $this->allowCustomComponentKeys;
    }

    protected function logDebug(string $message, array $context = []): void
    {
        if ($this->isLoggingEnabled()) {
            Log::debug($message, $context);
        }
    }

    protected function collapseLocalizedValue(mixed $value, string $lang): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $hasDv = array_key_exists('dv', $value);
        $hasEn = array_key_exists('en', $value);

        if (! $hasDv && ! $hasEn) {
            return $value;
        }

        $selected = $lang === 'en'
            ? ($value['en'] ?? $value['dv'] ?? null)
            : ($value['dv'] ?? $value['en'] ?? null);

        return $selected ?? $value;
    }

    protected function collapseLocalizedKeys(mixed $value, string $lang): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, $this->localizedPayloadKeys, true)) {
                $result[$key] = $this->collapseLocalizedValue($item, $lang);

                continue;
            }

            $result[$key] = $this->collapseLocalizedKeys($item, $lang);
        }

        return $result;
    }

    protected function canonicalComponentName(string $key): string
    {
        $this->logDebug('Entering canonicalComponentName', ['method' => __METHOD__, 'key' => $key]);
        $canonical = (string) preg_replace('/\d+$/', '', $key);

        return $canonical !== '' ? $canonical : $key;
    }

    // ──────────────────────────────────────────────
    //  Small private helpers (extracted duplicates)
    // ──────────────────────────────────────────────

    /**
     * Resolve the lang value string for a column definition.
     */
    protected function resolveLangValue(array $def, string $lang): string
    {
        $langsRaw = $def['lang'] ?? null;
        if (! is_array($langsRaw)) {
            return '';
        }

        $normalized = array_values(array_unique(array_map(fn ($l) => (string) $l, $langsRaw)));
        if (count($normalized) === 1) {
            return (string) $normalized[0];
        }
        if (! empty($normalized)) {
            return in_array($lang, $normalized, true) ? (string) $lang : (string) $normalized[0];
        }

        return '';
    }

    /**
     * Build select options (items or url) from a select/filterable config block.
     *
     * @return array{itemTitle: string, itemValue: string, items?: array, url?: string}
     */
    protected function buildSelectOptions(
        array $cfg,
        string $key,
        string $lang,
        ?Model $modelInstance = null,
        ?string $dotToken = null
    ): array {
        $mode = strtolower((string) ($cfg['mode'] ?? 'self'));
        $rawItemTitle = $cfg['itemTitle'] ?? $key;
        $itemTitle = is_array($rawItemTitle)
            ? (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle) ?? $key)
            : (string) $rawItemTitle;
        $itemValue = (string) ($cfg['itemValue'] ?? $key);

        $out = [
            'itemTitle' => $itemTitle,
            'itemValue' => $itemValue,
        ];

        if ($mode === 'self') {
            // Self mode: use items array directly from config
            $items = $cfg['items'] ?? [];
            $outItems = [];
            if (is_array($items)) {
                foreach (array_values($items) as $it) {
                    if (is_array($it)) {
                        $outItems[] = [
                            $itemTitle => (string) ($it[$itemTitle] ?? ''),
                            $itemValue => (string) ($it[$itemValue] ?? ''),
                        ];
                    } else {
                        $outItems[] = [
                            $itemTitle => (string) $it,
                            $itemValue => (string) $it,
                        ];
                    }
                }
            }
            $out['items'] = $outItems;
        } elseif ($mode === 'url') {
            // URL mode: use the URL exactly as defined in config
            $url = $cfg['url'] ?? null;

            if (! $url || ! is_string($url) || trim($url) === '') {
                throw new \InvalidArgumentException(
                    "Select mode 'url' requires a valid 'url' key for field '{$key}'"
                );
            }

            // Use URL as-is without modification
            $out['url'] = $url;
        } elseif ($mode === 'relation') {
            // Relation mode: build URL for fetching options from related model
            $relationship = (string) ($cfg['relationship'] ?? '');
            $related = null;
            if ($modelInstance instanceof Model && $relationship !== '') {
                $related = $this->resolveRelatedModel($modelInstance, $relationship);
            }
            $relatedName = $related ? class_basename($related) : null;
            if (! $relatedName) {
                if ($relationship !== '') {
                    $relatedName = Str::studly($relationship);
                } elseif ($dotToken !== null && Str::contains($dotToken, '.')) {
                    $relatedName = Str::studly(Str::before($dotToken, '.'));
                } else {
                    $base = $key;
                    if (Str::endsWith($base, '_id')) {
                        $base = Str::beforeLast($base, '_id');
                    }
                    $relatedName = Str::studly($base);
                }
            }
            $prefix = config('uiapi.route_prefix', 'api');
            $columnsParam = $itemValue.','.$itemTitle;
            $sortParam = $itemTitle;
            $out['url'] = url('/'.$prefix.'/gapi/'.$relatedName).'?columns='.$columnsParam.'&sort='.$sortParam.'&pagination=off&wrap=data';
        } else {
            // Fallback to relation mode for backward compatibility
            $relationship = (string) ($cfg['relationship'] ?? '');
            $related = null;
            if ($modelInstance instanceof Model && $relationship !== '') {
                $related = $this->resolveRelatedModel($modelInstance, $relationship);
            }
            $relatedName = $related ? class_basename($related) : null;
            if (! $relatedName) {
                if ($relationship !== '') {
                    $relatedName = Str::studly($relationship);
                } elseif ($dotToken !== null && Str::contains($dotToken, '.')) {
                    $relatedName = Str::studly(Str::before($dotToken, '.'));
                } else {
                    $base = $key;
                    if (Str::endsWith($base, '_id')) {
                        $base = Str::beforeLast($base, '_id');
                    }
                    $relatedName = Str::studly($base);
                }
            }
            $prefix = config('uiapi.route_prefix', 'api');
            $columnsParam = $itemValue.','.$itemTitle;
            $sortParam = $itemTitle;
            $out['url'] = url('/'.$prefix.'/gapi/'.$relatedName).'?columns='.$columnsParam.'&sort='.$sortParam.'&pagination=off&wrap=data';
        }

        return $out;
    }

    /**
     * Apply column customization overrides to a single header array.
     */
    protected function applyCustomizationsToHeader(array $header, ?array $columnCustomizations, string $token, string $lang): array
    {
        $custom = is_array($columnCustomizations) ? ($columnCustomizations[$token] ?? null) : null;
        if (! is_array($custom)) {
            return $header;
        }

        if (array_key_exists('sortable', $custom)) {
            $header['sortable'] = (bool) $custom['sortable'];
        }
        if (array_key_exists('hidden', $custom)) {
            $header['hidden'] = (bool) $custom['hidden'];
        }
        if (array_key_exists('type', $custom)) {
            $header['type'] = (string) $custom['type'];
        }
        if (array_key_exists('displayType', $custom)) {
            $header['displayType'] = (string) $custom['displayType'];
            $cdt = (string) $custom['displayType'];
            $ccfg = $custom[$cdt] ?? ($custom['displayProps'] ?? null);
            if (is_array($ccfg)) {
                $header[$cdt] = $this->normalizeDisplayConfig($cdt, $ccfg, $lang);
            }
        }
        if (array_key_exists('displayProps', $custom) && is_array($custom['displayProps']) && ! array_key_exists('displayType', $custom)) {
            $header['displayProps'] = $custom['displayProps'];
        }
        if (array_key_exists('inlineEditable', $custom)) {
            $header['inlineEditable'] = (bool) $custom['inlineEditable'];
        }
        if (array_key_exists('editable', $custom)) {
            $header['inlineEditable'] = (bool) $custom['editable'];
        }

        foreach ($custom as $k => $v) {
            if ($k === 'title' || $k === 'value' || $k === 'order') {
                continue;
            }
            if (! array_key_exists($k, $header)) {
                $header[$k] = $v;
            }
        }

        return $header;
    }

    /**
     * Build a header array from a column definition.
     */
    protected function buildHeaderFromDef(?array $def, string $token, string $lang, ?string $overrideTitle = null): array
    {
        $header = [
            'title' => $overrideTitle ?? ($def ? $this->labelFor($def, $token, $lang) : Str::title(str_replace('_', ' ', $token))),
            'value' => $def ? $this->keyFor($def, $token) : $token,
            'sortable' => (bool) ($def['sortable'] ?? false),
            'hidden' => (bool) ($def['hidden'] ?? false),
        ];

        if ($def && array_key_exists('type', $def)) {
            $header['type'] = (string) $def['type'];
        }
        if ($def && array_key_exists('displayType', $def)) {
            $header['displayType'] = (string) $def['displayType'];
            $dt = (string) $def['displayType'];
            $cfg = $def[$dt] ?? ($def['displayProps'] ?? null);
            if (is_array($cfg)) {
                $header[$dt] = $this->normalizeDisplayConfig($dt, $cfg, $lang);
            }
        }
        if ($def && array_key_exists('inlineEditable', $def)) {
            $header['inlineEditable'] = (bool) $def['inlineEditable'];
        }
        if ($def) {
            $override = $this->pickHeaderLangOverride($def, $lang);
            if ($override !== null) {
                $header['lang'] = $override;
            }
        }

        return $header;
    }

    /**
     * Try to resolve a relation name from a dot-token's first segment using a model instance.
     * Tries: exact, camelCase, and _id-stripped guessing.
     */
    protected function resolveRelationName(Model $model, string $segment): ?string
    {
        // Try exact
        if (method_exists($model, $segment)) {
            try {
                $relTest = $model->{$segment}();
            } catch (\Throwable $e) {
                $relTest = null;
            }
            if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                return $segment;
            }
        }

        // Try camelCase
        $camel = Str::camel($segment);
        if ($camel !== $segment && method_exists($model, $camel)) {
            try {
                $relTest = $model->{$camel}();
            } catch (\Throwable $e) {
                $relTest = null;
            }
            if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                return $camel;
            }
        }

        // Try _id guess
        if (Str::endsWith($segment, '_id')) {
            $guess = Str::camel(substr($segment, 0, -3));
            if (method_exists($model, $guess)) {
                try {
                    $relTest = $model->{$guess}();
                } catch (\Throwable $e) {
                    $relTest = null;
                }
                if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                    return $guess;
                }
            }
        }

        return null;
    }

    /**
     * Resolve the column definition for a dot-token (e.g. "author.name").
     * Model mode: resolves relation -> related apiSchema -> column def.
     * NoModel mode: looks up directly in columnsSchema.
     *
     * @return array{relDef: ?array, relationName: ?string}
     */
    protected function resolveRelationColumnDef(
        string $token,
        array $columnsSchema,
        ?Model $modelInstance = null
    ): array {
        [$first, $rest] = array_pad(explode('.', $token, 2), 2, null);
        if (! $rest) {
            return ['relDef' => null, 'relationName' => null];
        }

        if ($modelInstance instanceof Model) {
            $relationName = $this->resolveRelationName($modelInstance, $first);
            if ($relationName) {
                $related = $modelInstance->{$relationName}()->getRelated();
                $relSchema = method_exists($related, 'apiSchema') ? $related->apiSchema() : [];
                $relColumns = $relSchema['columns'] ?? [];

                return ['relDef' => $relColumns[$rest] ?? null, 'relationName' => $relationName];
            }

            return ['relDef' => null, 'relationName' => null];
        }

        // NoModel: check if schema has an entry for the full dot-token
        return ['relDef' => $columnsSchema[$token] ?? null, 'relationName' => $first];
    }

    // ──────────────────────────────────────────────
    //  Column & component resolution
    // ──────────────────────────────────────────────

    /**
     * @return array<int, string>|null
     */
    protected function getColumnsForComponent(array $compBlock, string $componentName): ?array
    {
        $components = is_array($compBlock['components'] ?? null) ? $compBlock['components'] : [];
        $component = is_array($components[$componentName] ?? null) ? $components[$componentName] : [];

        $componentColumns = $component['columns'] ?? null;
        if (is_array($componentColumns) && ! empty($componentColumns)) {
            return array_values($componentColumns);
        }

        $rootColumns = $compBlock['columns'] ?? null;
        if (is_array($rootColumns) && ! empty($rootColumns)) {
            return array_values($rootColumns);
        }

        return null;
    }

    /**
     * Root column customizations merged with component-specific overrides (component wins).
     */
    protected function getColumnCustomizationsForComponent(array $compBlock, string $componentName): ?array
    {
        $root = is_array($compBlock['columnCustomizations'] ?? null) ? $compBlock['columnCustomizations'] : null;

        $components = is_array($compBlock['components'] ?? null) ? $compBlock['components'] : [];
        $component = is_array($components[$componentName] ?? null) ? $components[$componentName] : [];
        $override = is_array($component['columnCustomizations'] ?? null) ? $component['columnCustomizations'] : null;

        if ($root === null && $override === null) {
            return null;
        }

        if ($root === null) {
            return $override;
        }

        if ($override === null) {
            return $root;
        }

        return array_replace_recursive($root, $override);
    }

    protected function resolveModel(string $modelName): ?array
    {
        $this->logDebug('Entering resolveModel', ['method' => __METHOD__, 'model' => $modelName]);

        $names = array_values(array_unique([
            ucfirst(strtolower($modelName)),
            Str::studly($modelName),
            Str::studly(str_replace(['-', ' ', '.'], '_', $modelName)),
        ]));

        $fqcn = null;
        foreach ($names as $name) {
            $packageFqcn = 'Ogp\\UiApi\\Models\\'.$name;
            $appFqcn = 'App\\Models\\'.$name;

            $packagePath = base_path('vendor/ogp/uiapi/src/Models/'.$name.'.php');
            $appPath = base_path('app/Models/'.$name.'.php');

            if (file_exists($packagePath) && class_exists($packageFqcn)) {
                $fqcn = $packageFqcn;
                break;
            }
            if (file_exists($appPath) && class_exists($appFqcn)) {
                $fqcn = $appFqcn;
                break;
            }
            if (class_exists($packageFqcn)) {
                $fqcn = $packageFqcn;
                break;
            }
            if (class_exists($appFqcn)) {
                $fqcn = $appFqcn;
                break;
            }
        }

        if (! $fqcn) {
            return null;
        }

        $instance = new $fqcn;
        if (! method_exists($instance, 'apiSchema')) {
            return null;
        }

        return [$fqcn, $instance, $instance->apiSchema()];
    }

    // ──────────────────────────────────────────────
    //  Unified core methods (model + noModel)
    // ──────────────────────────────────────────────

    /**
     * Normalize column tokens. Model mode validates relations; noModel is lenient.
     */
    protected function normalizeColumnsSubset(?Model $model, ?string $columns, array $columnsSchema): array
    {
        $columnsSubsetNormalized = null;
        $relationsFromColumns = [];
        if (! $columns) {
            return [$columnsSubsetNormalized, $relationsFromColumns];
        }

        $tokens = array_filter(array_map('trim', explode(',', $columns)));
        $columnsSubsetNormalized = [];

        foreach ($tokens as $token) {
            if (Str::contains($token, '.')) {
                [$first, $rest] = array_pad(explode('.', $token, 2), 2, null);
                if (! $rest) {
                    throw new \InvalidArgumentException("Invalid columns segment '$token'");
                }

                if ($model instanceof Model) {
                    $relationName = $this->resolveRelationName($model, $first);
                    if (! $relationName) {
                        throw new \InvalidArgumentException("Unknown relation reference '$first' in columns");
                    }
                    $related = $model->{$relationName}()->getRelated();
                    if (! method_exists($related, 'apiSchema')) {
                        throw new \InvalidArgumentException("Related model for '$relationName' lacks apiSchema()");
                    }
                    $relSchema = $related->apiSchema();
                    $relColumns = $relSchema['columns'] ?? [];
                    if (! array_key_exists($rest, $relColumns)) {
                        throw new \InvalidArgumentException("Column '$rest' is not defined in $relationName apiSchema");
                    }
                    $columnsSubsetNormalized[] = $first.'.'.$rest;
                    $relationsFromColumns[] = $relationName;
                } else {
                    // NoModel: passthrough dot tokens
                    $columnsSubsetNormalized[] = $first.'.'.$rest;
                    $relationsFromColumns[] = $first;
                }
            } else {
                if ($model instanceof Model) {
                    if (! array_key_exists($token, $columnsSchema)) {
                        throw new \InvalidArgumentException("Column '$token' is not defined in apiSchema");
                    }
                } elseif (! array_key_exists($token, $columnsSchema)) {
                    // NoModel: allow passthrough for unknown tokens
                    $columnsSubsetNormalized[] = $token;

                    continue;
                }
                $columnsSubsetNormalized[] = $token;
            }
        }

        return [$columnsSubsetNormalized, array_values(array_unique($relationsFromColumns))];
    }

    protected function parseWithRelations(string $fqcn, Model $model, ?string $with): array
    {
        $this->logDebug('Entering parseWithRelations', ['method' => __METHOD__, 'with' => $with]);

        return $fqcn::parseWithRelations($model, $with);
    }

    protected function boolQuery(Request $req, string $key, bool $default = true): bool
    {
        $this->logDebug('Entering boolQuery', ['method' => __METHOD__, 'key' => $key]);
        $val = $req->query($key);
        if ($val === null) {
            return $default;
        }

        return filter_var($val, FILTER_VALIDATE_BOOL);
    }

    /**
     * Filter column tokens by language support.
     * Model mode resolves relation schemas via the model; noModel checks columnsSchema directly.
     */
    protected function filterTokensByLangSupport(?Model $modelInstance, array $columnsSchema, array $tokens, string $lang): array
    {
        $out = [];
        foreach ($tokens as $token) {
            if (Str::contains($token, '.')) {
                if ($modelInstance instanceof Model) {
                    $resolved = $this->resolveRelationColumnDef($token, $columnsSchema, $modelInstance);
                    $relDef = $resolved['relDef'];
                    if ($relDef && $this->columnSupportsLang($relDef, $lang)) {
                        $out[] = $token;
                    }
                } else {
                    $def = $columnsSchema[$token] ?? null;
                    if ($def && $this->columnSupportsLang($def, $lang)) {
                        $out[] = $token;
                    }
                }

                continue;
            }

            $def = $columnsSchema[$token] ?? null;
            if ($def && $this->columnSupportsLang($def, $lang)) {
                $out[] = $token;
            }
        }

        return $out;
    }

    // ──────────────────────────────────────────────
    //  Main entry point
    // ──────────────────────────────────────────────

    public function index(Request $request, string $modelName)
    {
        $this->logDebug('Entering index', ['method' => __METHOD__, 'model' => $modelName]);

        // Determine if this is a view request or component request
        $viewParam = $request->query('view');
        $componentParam = $request->query('component');

        // Handle view request - return componentSettings only
        if ($viewParam && ! $componentParam) {
            return $this->handleViewRequest($request, $modelName, $viewParam);
        }

        // Handle component request - return full component payload
        try {
            $resolvedComp = $this->resolveViewComponent(
                $modelName,
                $componentParam,
                $request->query('columns')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $compBlock = $resolvedComp['compBlock'];
        $columnsParam = $resolvedComp['columnsParam'];
        $targetModel = $resolvedComp['targetModel'] ?? $modelName;

        // Determine noModel vs model-backed flow
        $isNoModel = (bool) ($compBlock['noModel'] ?? false);
        $modelInstance = null;
        $fqcn = null;

        if ($isNoModel) {
            $columnsSchema = is_array($compBlock['columnsSchema'] ?? null) ? $compBlock['columnsSchema'] : [];
            if (empty($columnsSchema)) {
                return response()->json([
                    'error' => 'noModel mode requires columnsSchema in view config',
                ], 422);
            }
        } else {
            $resolved = $this->resolveModel($targetModel);
            if (! $resolved) {
                return response()->json(['error' => "Model '$targetModel' not found or missing apiSchema()"], 422);
            }
            [$fqcn, $modelInstance, $schema] = $resolved;
            $columnsSchema = $schema['columns'] ?? [];
        }

        // Run view config validation at debug_level 2+
        $validationWarnings = null;
        if ($this->debugLevel >= 2) {
            $fullViewCfg = $this->loadViewConfig($targetModel);
            $validator = new ViewConfigValidator;
            $validationResults = $validator->validate($fullViewCfg, $targetModel);
            if ($validator->hasErrors()) {
                return response()->json([
                    'error' => 'View config validation failed',
                    'validation' => $validationResults,
                ], 422);
            }
            if ($validator->hasWarnings()) {
                $validationWarnings = $validationResults['warnings'];
            }
        }

        $lang = (string) ($request->query('lang') ?? 'dv');
        if (! $this->isLangAllowedForComponent($compBlock, $lang)) {
            return response()->json([
                'message' => "Language '$lang' not supported by view config",
                'data' => [],
            ]);
        }

        $perPage = (int) ($request->query('per_page') ?? ($compBlock['per_page'] ?? 25));

        try {
            [$columnsSubsetNormalized, $relationsFromColumns] = $this->normalizeColumnsSubset($modelInstance, $columnsParam, $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Model-backed: merge relation columns into with-relations
        if ($modelInstance instanceof Model && $fqcn) {
            $with = $request->query('with');
            $relations = $this->parseWithRelations($fqcn, $modelInstance, $with);
            foreach ($relationsFromColumns as $rel) {
                if (! in_array($rel, $relations, true)) {
                    $relations[] = $rel;
                }
            }
        }

        $effectiveTokens = $this->filterTokensByLangSupport($modelInstance, $columnsSchema, $columnsSubsetNormalized ?? [], $lang);

        $component = (string) ($resolvedComp['componentKey'] ?? '');
        $columnCustomizations = $this->getColumnCustomizationsFromComponent($compBlock);
        $allowedFilters = $this->getAllowedFiltersFromComponent($compBlock);

        // If this is a direct component request (not a view), build and return the component payload
        if ($componentParam && ! $viewParam) {
            $componentKey = $resolvedComp['componentKey'] ?? $componentParam;
            $componentConfig = $this->loadComponentConfig($componentKey);

            if (empty($componentConfig)) {
                return response()->json([
                    'error' => "Component config '{$componentKey}' not found",
                ], 422);
            }

            // Build the section payload from base component config first
            $baseConfig = $componentConfig[$componentKey] ?? [];
            $componentPayload = $this->buildSectionPayload(
                $baseConfig,
                $columnsSchema,
                $effectiveTokens,
                $lang,
                $perPage,
                $targetModel,
                $modelInstance,
                $columnCustomizations,
                $allowedFilters
            );

            // Apply view config overrides (e.g., buttons: ["search", "clear"] filters the full button definitions)
            if (is_array($compBlock) && !empty($compBlock)) {
                $componentPayload = $this->applyOverridesToSection($componentPayload, $compBlock, $lang);
            }

            // Collapse localized keys (e.g., {en, dv} → single lang value)
            $componentPayload = $this->collapseLocalizedKeys($componentPayload, $lang);

            return response()->json($componentPayload);
        }

        // Build component settings
        $componentSettingsQuery = $request->query('componentSettings');
        if (is_string($componentSettingsQuery) && $componentSettingsQuery !== '') {
            $cfg = $this->loadComponentConfig($componentSettingsQuery);
            if (empty($cfg)) {
                return response()->json([
                    'error' => "Component config '{$componentSettingsQuery}' not found",
                ], 422);
            }

            $componentSettings = $this->buildComponentSettings(
                $componentSettingsQuery,
                $columnsSchema,
                $effectiveTokens,
                $lang,
                $perPage,
                $modelName,
                $modelInstance,
                $columnCustomizations,
                $allowedFilters
            );
        } else {
            $componentsMap = $compBlock['components'] ?? [];
            $componentKeys = is_array($componentsMap) ? array_keys($componentsMap) : [];

            $missing = [];
            foreach ($componentKeys as $k) {
                $cfg = $this->loadComponentConfig($k);
                if (empty($cfg)) {
                    $missing[] = $k;
                }
            }
            if (! empty($missing)) {
                return response()->json([
                    'error' => 'Component config(s) not found',
                    'missingComponents' => $missing,
                ], 422);
            }

            $componentSettings = $this->buildComponentSettingsForComponents(
                $componentKeys,
                $columnsSchema,
                $effectiveTokens,
                $lang,
                $perPage,
                $modelName,
                $modelInstance,
                $columnCustomizations,
                $allowedFilters,
                is_array($componentsMap) ? $componentsMap : null
            );
        }

        // Include meta only when declared and not already built
        $componentsMap = $compBlock['components'] ?? [];
        $shouldAppendMeta = is_array($componentsMap)
            && array_key_exists('meta', $componentsMap)
            && ! array_key_exists('meta', $componentSettings);
        if ($shouldAppendMeta) {
            $metaCfg = $this->loadComponentConfig('meta');
            if (! empty($metaCfg) && isset($metaCfg['meta']) && is_array($metaCfg['meta'])) {
                $metaPayload = $this->buildSectionPayload(
                    $metaCfg['meta'],
                    $columnsSchema,
                    $effectiveTokens,
                    $lang,
                    $perPage,
                    $modelName,
                    $modelInstance,
                    $columnCustomizations,
                    $allowedFilters
                );

                $metaOverrides = is_array($componentsMap) ? ($componentsMap['meta'] ?? null) : null;
                if (is_array($metaOverrides)) {
                    $metaPayload = $this->applyOverridesToSection($metaPayload, $metaOverrides, $lang);
                }

                $componentSettings['meta'] = $metaPayload;
            }
        }

        // Top-level headers
        $topLevelHeaders = null;
        if ($this->getIncludeTopLevelHeaders()) {
            $topLevelHeaders = $this->buildTopLevelHeaders($columnsSchema, $effectiveTokens, $lang, $columnCustomizations, $modelInstance);
        }

        // Top-level filters
        $topLevelFilters = null;
        if ($this->getIncludeTopLevelFilters()) {
            $topLevelFilters = $this->buildFilters(
                $columnsSchema,
                $modelName,
                $lang,
                $allowedFilters,
                $modelInstance,
                $columnsSubsetNormalized
            );
        }

        // Build response
        $response = [];
        if ($this->getIncludeTopLevelPagination()) {
            $response['pagination'] = [
                'current_page' => 1,
                'per_page' => $perPage,
            ];
        }
        $response['component'] = $this->canonicalComponentName($component);
        $response['componentSettings'] = $componentSettings;
        if ($topLevelHeaders !== null) {
            $response['headers'] = $topLevelHeaders;
        }
        if ($topLevelFilters !== null) {
            $response['filters'] = $topLevelFilters;
        }

        $response = $this->collapseLocalizedKeys($response, $lang);

        if ($validationWarnings !== null) {
            $response['_validation_warnings'] = $validationWarnings;
        }

        return response()->json($response);
    }

    // ──────────────────────────────────────────────
    //  Headers
    // ──────────────────────────────────────────────

    /**
     * Build top-level headers. Works for both model-backed and noModel flows.
     */
    public function buildTopLevelHeaders(
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        ?array $columnCustomizations = null,
        ?Model $modelInstance = null
    ): array {
        $fields = [];
        if (is_array($columnsSubsetNormalized) && ! empty($columnsSubsetNormalized)) {
            $fields = array_values(array_unique(array_filter(array_map(fn ($t) => is_string($t) ? $t : null, $columnsSubsetNormalized))));
        } else {
            $fields = array_keys($columnsSchema);
        }

        $fields = $this->filterTokensByLangSupport($modelInstance, $columnsSchema, $fields, $lang);

        $headers = [];
        foreach ($fields as $token) {
            $overrideTitle = $this->resolveCustomizedTitle($columnCustomizations, $token, $lang);

            if (Str::contains($token, '.')) {
                [$first, $rest] = array_pad(explode('.', $token, 2), 2, null);
                if (! $rest) {
                    continue;
                }

                $resolved = $this->resolveRelationColumnDef($token, $columnsSchema, $modelInstance);
                $relDef = $resolved['relDef'];

                if (! $this->includeHiddenColumnsInHeaders && $relDef && (bool) ($relDef['hidden'] ?? false) === true) {
                    continue;
                }

                // Build title
                $title = $overrideTitle;
                if ($title === null && $relDef) {
                    $relLabel = $relDef['relationLabel'] ?? null;
                    if (is_array($relLabel)) {
                        $title = (string) ($relLabel[$lang] ?? $relLabel['en'] ?? $this->labelFor($relDef, $rest, $lang));
                    } elseif (is_string($relLabel) && $relLabel !== '') {
                        $title = $relLabel;
                    } else {
                        $title = $this->labelFor($relDef, $rest, $lang);
                    }
                }
                if ($title === null) {
                    $title = Str::title(str_replace('_', ' ', $rest));
                }

                if ($relDef) {
                    $header = $this->buildHeaderFromDef($relDef, $rest, $lang, $title);
                    $header['value'] = $token;
                } else {
                    $header = [
                        'title' => $title,
                        'value' => $token,
                        'sortable' => false,
                        'hidden' => false,
                    ];
                }

                $header = $this->applyCustomizationsToHeader($header, $columnCustomizations, $token, $lang);
                $headers[] = $header;

                continue;
            }

            // Bare column
            $def = $columnsSchema[$token] ?? null;
            if (! $def) {
                continue;
            }
            if (! $this->includeHiddenColumnsInHeaders && (bool) ($def['hidden'] ?? false) === true) {
                continue;
            }

            $header = $this->buildHeaderFromDef($def, $token, $lang, $overrideTitle);
            $header = $this->applyCustomizationsToHeader($header, $columnCustomizations, $token, $lang);
            $headers[] = $header;
        }

        // Append custom columns from columnCustomizations that are not in the schema
        if (is_array($columnCustomizations)) {
            $headers = $this->appendCustomHeaders($headers, $columnCustomizations, $lang);
        }

        $headers = $this->reorderHeadersByCustomOrder($headers, $columnCustomizations);

        return $headers;
    }

    /**
     * Append custom header columns defined in columnCustomizations but not in the schema.
     */
    protected function appendCustomHeaders(array $headers, array $columnCustomizations, string $lang): array
    {
        $existingKeys = array_map(fn (array $h) => $h['value'] ?? '', $headers);

        foreach ($columnCustomizations as $custKey => $custProps) {
            if (in_array($custKey, $existingKeys, true)) {
                continue;
            }
            if (! is_array($custProps)) {
                continue;
            }

            $label = $custProps['label'] ?? null;
            $title = null;
            $lang_override = null;
            if (is_array($label)) {
                $title = (string) ($label[$lang] ?? $label['en'] ?? reset($label) ?? '');
                $otherLangs = array_values(array_filter(
                    array_keys($label),
                    fn ($l) => strtolower((string) $l) !== strtolower($lang)
                ));
                if (! empty($otherLangs)) {
                    $lang_override = strtolower($otherLangs[0]);
                }
            } elseif (is_string($label) && $label !== '') {
                $title = $label;
            }
            if ($title === null) {
                $title = Str::title(str_replace('_', ' ', $custKey));
            }

            $header = [
                'title' => $title,
                'value' => $custKey,
            ];
            if ($lang_override !== null) {
                $header['lang'] = $lang_override;
            }

            foreach ($custProps as $k => $v) {
                if ($k === 'label' || $k === 'title' || $k === 'value' || $k === 'order') {
                    continue;
                }
                if ($k === 'displayType' && is_string($v)) {
                    $header['displayType'] = $v;
                    $dtCfg = $custProps[$v] ?? ($custProps['displayProps'] ?? null);
                    if (is_array($dtCfg)) {
                        $header[$v] = $this->normalizeDisplayConfig($v, $dtCfg, $lang);
                    }

                    continue;
                }
                $dt = $custProps['displayType'] ?? null;
                if (is_string($dt) && $k === $dt) {
                    continue;
                }
                if ($k === 'sortable') {
                    $header['sortable'] = (bool) $v;
                } elseif ($k === 'hidden') {
                    $header['hidden'] = (bool) $v;
                } elseif ($k === 'inlineEditable' || $k === 'editable') {
                    $header['inlineEditable'] = (bool) $v;
                } else {
                    $header[$k] = $v;
                }
            }

            $headers[] = $header;
        }

        return $headers;
    }

    // ──────────────────────────────────────────────
    //  Data links
    // ──────────────────────────────────────────────

    /**
     * Build data link URL. Works for both model-backed and noModel flows.
     */
    protected function buildDataLink(
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        string $modelName,
        int $perPage,
        ?Model $modelInstance = null
    ): string {
        $baseTokens = [];
        if (is_array($columnsSubsetNormalized) && ! empty($columnsSubsetNormalized)) {
            $baseTokens = array_values(array_unique(array_filter(array_map(fn ($t) => is_string($t) ? $t : null, $columnsSubsetNormalized))));
        } else {
            $baseTokens = array_keys($columnsSchema);
        }
        $tokens = $this->filterTokensByLangSupport($modelInstance, $columnsSchema, $baseTokens, $lang);

        $relationFields = [];
        foreach ($tokens as $token) {
            if (! Str::contains($token, '.')) {
                continue;
            }
            [$rel, $field] = array_pad(explode('.', $token, 2), 2, null);
            if (! $field) {
                continue;
            }
            if (! isset($relationFields[$rel])) {
                $relationFields[$rel] = [];
            }
            if (! in_array($field, $relationFields[$rel], true)) {
                $relationFields[$rel][] = $field;
            }
        }

        $withSegments = [];
        foreach ($relationFields as $rel => $fields) {
            $withSegments[] = $rel.':'.implode(',', $fields);
        }

        $prefix = config('uiapi.route_prefix', 'api');
        $base = $modelInstance instanceof Model
            ? "gapi/{$modelName}"
            : url("/{$prefix}/gapi/{$modelName}");

        $query = 'columns='.implode(',', $tokens);
        if (! empty($withSegments)) {
            $query .= '&with='.implode(',', $withSegments);
        }
        $query .= '&per_page='.$perPage;

        return $base.'?'.$query;
    }

    /**
     * Build create link (relative) for POST create endpoint.
     */
    protected function buildCreateLink(string $modelName): string
    {
        return 'gapi/'.$modelName;
    }

    // ──────────────────────────────────────────────
    //  Form fields
    // ──────────────────────────────────────────────

    /**
     * Build form fields array from schema (works for both model and noModel flows).
     */
    protected function buildFormFieldsFromSchema(
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        ?Model $modelInstance = null
    ): array {
        $fields = [];

        // Include base (non-dot) fields from schema
        foreach ($columnsSchema as $field => $def) {
            if (! is_array($def)) {
                $def = [];
            }
            if ($modelInstance instanceof Model) {
                if (! ((bool) ($def['formField'] ?? false) === true)) {
                    continue;
                }
            }
            $key = $this->keyFor($def, $field);
            $label = $this->labelFor($def, $field, $lang);
            $inputType = (string) ($def['inputType'] ?? '');
            if ($inputType === '') {
                $inputType = $this->defaultInputTypeForType($def['type'] ?? null);
            }
            $langValue = $this->resolveLangValue($def, $lang);
            $type = (string) ($def['type'] ?? 'string');
            $fieldOut = [
                'key' => $key,
                'label' => $label,
                'lang' => $langValue,
                'type' => $type,
                'inputType' => $inputType,
            ];

            if (strtolower($inputType) === 'select') {
                $cfg = $def['select'] ?? ($def['filterable'] ?? null);
                if (is_array($cfg)) {
                    $selectOpts = $this->buildSelectOptions($cfg, $key, $lang, $modelInstance);
                    $fieldOut = array_merge($fieldOut, $selectOpts);
                }
            }

            $fields[] = $fieldOut;
        }

        // Include relation dot-tokens from columns subset
        if (is_array($columnsSubsetNormalized) && ! empty($columnsSubsetNormalized)) {
            foreach ($columnsSubsetNormalized as $token) {
                if (! is_string($token) || ! Str::contains($token, '.')) {
                    continue;
                }
                [$rel, $rest] = array_pad(explode('.', $token, 2), 2, null);
                if (! $rest) {
                    continue;
                }

                $leafDef = null;
                if ($modelInstance instanceof Model) {
                    $segments = explode('.', $token);
                    $leaf = array_pop($segments);
                    $chain = implode('.', $segments);
                    $related = $this->resolveNestedRelatedModel($modelInstance, $chain);
                    if ($related instanceof Model && method_exists($related, 'apiSchema')) {
                        $relSchema = $related->apiSchema();
                        $relCols = is_array($relSchema['columns'] ?? null) ? $relSchema['columns'] : [];
                        $leafDef = $relCols[$leaf] ?? null;
                    }
                    if (! (is_array($leafDef) && ((bool) ($leafDef['formField'] ?? false) === true))) {
                        continue;
                    }
                } else {
                    $leafDef = $columnsSchema[$token] ?? null;
                    if (! is_array($leafDef)) {
                        $leafDef = [];
                    }
                }

                $key = is_array($leafDef) && ! empty($leafDef) ? $this->keyFor($leafDef, $rest) : $token;
                $label = is_array($leafDef) && ! empty($leafDef)
                    ? $this->labelFor($leafDef, $rest, $lang)
                    : Str::title(str_replace('_', ' ', (string) $rest));
                $inputType = is_array($leafDef) ? (string) ($leafDef['inputType'] ?? '') : '';
                if ($inputType === '') {
                    $inputType = $this->defaultInputTypeForType(is_array($leafDef) ? ($leafDef['type'] ?? null) : null);
                }
                $langValue = is_array($leafDef) ? $this->resolveLangValue($leafDef, $lang) : '';
                $type = is_array($leafDef) ? (string) ($leafDef['type'] ?? 'string') : 'string';

                $fieldOut = [
                    'key' => $key,
                    'label' => $label,
                    'lang' => $langValue,
                    'type' => $type,
                    'inputType' => $inputType,
                ];

                if (strtolower($inputType) === 'select' && is_array($leafDef)) {
                    $cfg = $leafDef['select'] ?? ($leafDef['filterable'] ?? null);
                    if (is_array($cfg)) {
                        $selectOpts = $this->buildSelectOptions($cfg, $key, $lang, $modelInstance, $token);
                        $fieldOut = array_merge($fieldOut, $selectOpts);
                    }
                }

                $fields[] = $fieldOut;
            }
        }

        return $fields;
    }

    /**
     * Resolve a nested relation chain like "author.country.capital" to the terminal related model.
     */
    protected function resolveNestedRelatedModel(Model $model, string $chain): ?Model
    {
        $current = $model;
        $parts = array_filter(array_map('trim', explode('.', $chain)));
        foreach ($parts as $relation) {
            $next = $this->resolveRelatedModel($current, $relation);
            if (! $next instanceof Model) {
                return null;
            }
            $current = $next;
        }

        return $current;
    }

    // ──────────────────────────────────────────────
    //  Section payload & component settings (unified)
    // ──────────────────────────────────────────────

    /**
     * Build section payload. Works for both model-backed and noModel flows.
     */
    public function buildSectionPayload(
        array $node,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        int $perPage,
        string $modelName,
        ?Model $modelInstance = null,
        ?array $columnCustomizations = null,
        ?array $allowedFilters = null
    ): array {
        $out = [];
        foreach ($node as $key => $val) {
            if ($key === 'crudLink') {
                if ($val === 'on') {
                    $out['crudLink'] = $this->buildCreateLink($modelName);
                } elseif ($val !== 'off') {
                    $out['crudLink'] = $val;
                }

                continue;
            }
            if ($key === 'fields') {
                if ($val === 'on') {
                    $out['fields'] = $this->buildFormFieldsFromSchema($columnsSchema, $columnsSubsetNormalized, $lang, $modelInstance);
                } elseif ($val !== 'off') {
                    $out['fields'] = $val;
                }

                continue;
            }
            if ($key === 'createLink') {
                if ($val === 'on') {
                    $out['createLink'] = $this->buildCreateLink($modelName);
                } elseif ($val !== 'off') {
                    $out['createLink'] = $val;
                }

                continue;
            }
            if ($key === 'headers') {
                if ($val === 'on') {
                    $out['headers'] = $this->buildTopLevelHeaders($columnsSchema, $columnsSubsetNormalized, $lang, $columnCustomizations, $modelInstance);
                } elseif ($val !== 'off') {
                    $out['headers'] = $val;
                }

                continue;
            }
            if ($key === 'filters') {
                if ($val === 'on') {
                    // Merge columnCustomizations into columnsSchema for filters
                    $effectiveSchema = $columnsSchema;
                    if (is_array($columnCustomizations) && !empty($columnCustomizations)) {
                        foreach ($columnCustomizations as $colKey => $customization) {
                            if (isset($effectiveSchema[$colKey]) && is_array($effectiveSchema[$colKey])) {
                                $effectiveSchema[$colKey] = array_merge($effectiveSchema[$colKey], $customization);
                            } elseif (!isset($effectiveSchema[$colKey])) {
                                $effectiveSchema[$colKey] = $customization;
                            }
                        }
                    }
                    $out['filters'] = $this->buildFilters($effectiveSchema, $modelName, $lang, $allowedFilters, $modelInstance);
                } elseif ($val !== 'off') {
                    $out['filters'] = $val;
                }

                continue;
            }
            if ($key === 'pagination') {
                if ($val === 'on') {
                    $out['pagination'] = [
                        'current_page' => 1,
                        'per_page' => $perPage,
                    ];
                } elseif ($val !== 'off') {
                    $out['pagination'] = $val;
                }

                continue;
            }
            if ($key === 'datalink') {
                if ($val === 'on') {
                    $out['datalink'] = $this->buildDataLink(
                        $columnsSchema,
                        $columnsSubsetNormalized,
                        $lang,
                        $modelName,
                        $perPage,
                        $modelInstance
                    );
                } elseif ($val !== 'off') {
                    $out['datalink'] = $val;
                }

                continue;
            }
            if ($key === 'functions') {
                if (is_array($val)) {
                    $out['functions'] = $this->resolveExternalFunctions($val);
                } else {
                    $out['functions'] = $val;
                }

                continue;
            }
            // Skip keys consumed internally by CCS
            if (in_array($key, $this->internalKeys, true)) {
                continue;
            }
            if (is_array($val)) {
                $out[$key] = $this->buildSectionPayload($val, $columnsSchema, $columnsSubsetNormalized, $lang, $perPage, $modelName, $modelInstance, $columnCustomizations, $allowedFilters);
            } else {
                $out[$key] = $val;
            }
        }

        return $out;
    }

    /**
     * Build component settings for a single component key. Unified for model + noModel.
     */
    public function buildComponentSettings(
        string $componentSettingsKey,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        int $perPage,
        string $modelName,
        ?Model $modelInstance = null,
        ?array $columnCustomizations = null,
        ?array $allowedFilters = null
    ): array {
        $configFile = $this->loadComponentConfig($componentSettingsKey);
        if (empty($configFile)) {
            return [];
        }

        $componentSettings = [];

        if (isset($configFile[$componentSettingsKey]) && is_array($configFile[$componentSettingsKey])) {
            $sectionCfg = $configFile[$componentSettingsKey];
            $componentSettings[$componentSettingsKey] = $this->buildSectionPayload(
                $sectionCfg,
                $columnsSchema,
                $columnsSubsetNormalized,
                $lang,
                $perPage,
                $modelName,
                $modelInstance,
                $columnCustomizations,
                $allowedFilters
            );
        }

        foreach ($configFile as $sectionName => $sectionVal) {
            if ($sectionName === $componentSettingsKey) {
                continue;
            }

            if (is_array($sectionVal)) {
                $componentSettings[$sectionName] = $this->buildSectionPayload(
                    $sectionVal,
                    $columnsSchema,
                    $columnsSubsetNormalized,
                    $lang,
                    $perPage,
                    $modelName,
                    $modelInstance,
                    $columnCustomizations,
                    $allowedFilters
                );
            }
        }

        return $componentSettings;
    }

    /**
     * Build component settings for multiple component keys. Unified for model + noModel.
     */
    public function buildComponentSettingsForComponents(
        array $componentKeys,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        int $perPage,
        string $modelName,
        ?Model $modelInstance = null,
        ?array $columnCustomizations = null,
        ?array $allowedFilters = null,
        ?array $componentsOverrides = null
    ): array {
        $componentSettings = [];

        foreach ($componentKeys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $customizationsForComponent = $this->getColumnCustomizationsForComponent(
                ['components' => $componentsOverrides ?? []],
                $key
            ) ?? $columnCustomizations;
            $columnsForComponent = $this->getColumnsForComponent(
                ['components' => $componentsOverrides ?? []],
                $key
            );
            $columnsSubsetForComponent = $columnsForComponent ?? $columnsSubsetNormalized;

            $configFile = $this->loadComponentConfig($key);
            if (empty($configFile)) {
                continue;
            }

            if (isset($configFile[$key]) && is_array($configFile[$key])) {
                $sectionCfg = $configFile[$key];
                $payload = $this->buildSectionPayload(
                    $sectionCfg,
                    $columnsSchema,
                    $columnsSubsetForComponent,
                    $lang,
                    $perPage,
                    $modelName,
                    $modelInstance,
                    $customizationsForComponent,
                    $allowedFilters
                );

                if (is_array($componentsOverrides) && array_key_exists($key, $componentsOverrides)) {
                    $override = $componentsOverrides[$key];
                    if (is_array($override)) {
                        $payload = $this->applyOverridesToSection($payload, $override, $lang);
                    }
                }

                $componentSettings[$key] = $payload;
            }
        }

        return $componentSettings;
    }

    // ──────────────────────────────────────────────
    //  Filters (unified)
    // ──────────────────────────────────────────────

    /**
     * Build filters array. Unified for model-backed and noModel flows.
     */
    public function buildFilters(
        array $columnsSchema,
        string $modelName,
        string $lang,
        ?array $allowedFilters = null,
        ?Model $modelInstance = null,
        ?array $columnsSubsetNormalized = null
    ): array {
        $this->logDebug('Entering buildFilters', ['method' => __METHOD__, 'model' => $modelName]);

        $fields = is_array($allowedFilters)
            ? $allowedFilters
            : array_keys($columnsSchema);

        $filters = [];
        foreach ($fields as $field) {
            $def = $columnsSchema[$field] ?? null;
            if (! is_array($def) || ! $this->columnSupportsLang($def, $lang)) {
                continue;
            }

            if (is_array($columnsSubsetNormalized) && ! in_array($field, $columnsSubsetNormalized, true)) {
                continue;
            }

            $label = $this->labelFor($def, $field, $lang);
            $key = $this->keyFor($def, $field);

            $inputType = (string) ($def['inputType'] ?? $this->defaultInputTypeForType($def['type'] ?? null));
            $cfg = is_string($inputType) && $inputType !== '' ? ($def[$inputType] ?? null) : null;

            $legacy = $def['filterable'] ?? null;

            $typeToken = match (strtolower($inputType)) {
                'select' => 'Select',
                'text', 'textfield' => 'Text',
                'number', 'numberfield' => 'Number',
                'checkbox' => 'Checkbox',
                'date', 'datepicker' => 'Date',
                default => $this->defaultFilterTypeForDef($def),
            };

            $overrideLabel = is_array($cfg) ? ($cfg['label'] ?? null) : (is_array($legacy) ? ($legacy['label'] ?? null) : null);
            if (is_array($overrideLabel)) {
                $label = (string) ($overrideLabel[$lang] ?? $overrideLabel['en'] ?? reset($overrideLabel) ?? $label);
            } elseif (is_string($overrideLabel) && $overrideLabel !== '') {
                $label = $overrideLabel;
            }
            $key = (string) ((is_array($cfg) ? ($cfg['value'] ?? null) : null) ?? (is_array($legacy) ? ($legacy['value'] ?? null) : null) ?? $key);

            $filter = [
                'type' => $typeToken,
                'key' => $key,
                'label' => $label,
            ];

            if (strtolower($typeToken) === 'select') {
                $source = is_array($cfg) ? $cfg : (is_array($legacy) ? $legacy : []);
                $mode = strtolower((string) ($source['mode'] ?? 'self'));

                // Determine itemTitle and itemValue keys
                // Check for language-specific keys first (itemTitleEn, itemTitleDv)
                $rawItemTitle = $source['itemTitle'] ?? null;
                $itemTitleKey = null;
                $itemValueKey = $source['itemValue'] ?? 'itemValue';

                if ($rawItemTitle === null && is_array($source['items'] ?? null) && !empty($source['items'])) {
                    // Check first item for language-specific keys
                    $firstItem = reset($source['items']);
                    if (is_array($firstItem)) {
                        $langKey = 'itemTitle' . ucfirst($lang);
                        if (array_key_exists($langKey, $firstItem)) {
                            $itemTitleKey = $langKey;
                        } elseif (array_key_exists('itemTitleEn', $firstItem)) {
                            $itemTitleKey = 'itemTitleEn';
                        } elseif (array_key_exists('itemTitleDv', $firstItem)) {
                            $itemTitleKey = 'itemTitleDv';
                        }
                    }
                }

                if ($itemTitleKey === null) {
                    $itemTitle = is_array($rawItemTitle)
                        ? (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle) ?? $key)
                        : (string) ($rawItemTitle ?? $key);
                    $itemTitleKey = $itemTitle;
                }

                $filter['itemTitle'] = $itemTitleKey;
                $filter['itemValue'] = $itemValueKey;

                if ($mode === 'self') {
                    // Self mode: use items array directly from config
                    $items = $source['items'] ?? [];
                    $pruned = [];
                    if (is_array($items)) {
                        foreach (array_values($items) as $it) {
                            if (is_array($it)) {
                                $pruned[] = [
                                    $itemTitleKey => (string) ($it[$itemTitleKey] ?? ''),
                                    $itemValueKey => (string) ($it[$itemValueKey] ?? ''),
                                ];
                            } else {
                                $pruned[] = [
                                    $itemTitleKey => (string) $it,
                                    $itemValueKey => (string) $it,
                                ];
                            }
                        }
                    }
                    $filter['items'] = $pruned;
                } elseif ($mode === 'url') {
                    // URL mode: use the URL exactly as defined in config
                    $url = $source['url'] ?? null;

                    if (! $url || ! is_string($url) || trim($url) === '') {
                        throw new \InvalidArgumentException(
                            "Filter mode 'url' requires a valid 'url' key for field '{$key}'"
                        );
                    }

                    // Use URL as-is without modification
                    $filter['url'] = $url;
                } elseif ($mode === 'relation') {
                    // Relation mode: build URL for fetching options from related model
                    $relationship = (string) ($source['relationship'] ?? '');
                    if ($modelInstance instanceof Model && $relationship !== '') {
                        $related = $this->resolveRelatedModel($modelInstance, $relationship);
                        if ($related) {
                            $prefix = config('uiapi.route_prefix', 'api');
                            $base = url('/'.$prefix.'/'.class_basename($related));
                            $queryStr = "columns={$itemValue},{$itemTitle}&sort={$itemTitle}&pagination=off&wrap=data";
                            $filter['url'] = $base.'?'.$queryStr;
                        }
                    } else {
                        $relatedModelName = $relationship !== '' ? Str::studly($relationship) : null;
                        if (! $relatedModelName) {
                            $base = $key;
                            if (Str::endsWith($base, '_id')) {
                                $base = Str::beforeLast($base, '_id');
                            }
                            $relatedModelName = Str::studly($base);
                        }
                        $columnsParamStr = $itemValue.','.$itemTitle;
                        $sortParam = $itemTitle;
                        $prefix = config('uiapi.route_prefix', 'api');
                        $filter['url'] = url("/{$prefix}/gapi/{$relatedModelName}").'?columns='.$columnsParamStr.'&sort='.$sortParam.'&pagination=off&wrap=data';
                    }
                } else {
                    // Fallback to relation mode for backward compatibility
                    $relationship = (string) ($source['relationship'] ?? '');
                    if ($modelInstance instanceof Model && $relationship !== '') {
                        $related = $this->resolveRelatedModel($modelInstance, $relationship);
                        if ($related) {
                            $prefix = config('uiapi.route_prefix', 'api');
                            $base = url('/'.$prefix.'/'.class_basename($related));
                            $queryStr = "columns={$itemValue},{$itemTitle}&sort={$itemTitle}&pagination=off&wrap=data";
                            $filter['url'] = $base.'?'.$queryStr;
                        }
                    } else {
                        $relatedModelName = $relationship !== '' ? Str::studly($relationship) : null;
                        if (! $relatedModelName) {
                            $base = $key;
                            if (Str::endsWith($base, '_id')) {
                                $base = Str::beforeLast($base, '_id');
                            }
                            $relatedModelName = Str::studly($base);
                        }
                        $columnsParamStr = $itemValue.','.$itemTitle;
                        $sortParam = $itemTitle;
                        $prefix = config('uiapi.route_prefix', 'api');
                        $filter['url'] = url("/{$prefix}/gapi/{$relatedModelName}").'?columns='.$columnsParamStr.'&sort='.$sortParam.'&pagination=off&wrap=data';
                    }
                }
            }

            $filters[] = $filter;
        }

        return $filters;
    }

    // ──────────────────────────────────────────────
    //  View config & component config loading
    // ──────────────────────────────────────────────

    public function loadViewConfig(string $modelName): array
    {
        $base = base_path(config('uiapi.view_configs_path', 'app/Services/viewConfigs'));
        $normalized = str_replace(['-', '_', ' '], '', Str::lower($modelName));
        $path = rtrim($base, '/').'/'.$normalized.'.json';
        if (! File::exists($path)) {
            return [];
        }
        $json = File::get($path);
        $cfg = json_decode($json, true);

        if ($cfg === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(
                $this->buildJsonErrorMessage($modelName, $path, $json)
            );
        }

        return $cfg ?: [];
    }

    /**
     * Build a descriptive JSON parse error message based on the configured debug level.
     *
     * Level 0: Generic error only.
     * Level 1: Error type and file path.
     * Level 2: Line number, column, and surrounding context snippet.
     */
    protected function buildJsonErrorMessage(string $modelName, string $path, string $json): string
    {
        $errorMsg = json_last_error_msg();

        if ($this->debugLevel <= 0) {
            return "JSON syntax error in view config for '{$modelName}'.";
        }

        if ($this->debugLevel === 1) {
            return "JSON syntax error in view config for '{$modelName}': {$errorMsg}. File: {$path}";
        }

        // Level 2+: find the exact line and column of the error
        $errorInfo = $this->locateJsonError($json);

        $message = "JSON syntax error in view config for '{$modelName}': {$errorMsg}.";
        $message .= " File: {$path}";

        if ($errorInfo) {
            $message .= " | Line {$errorInfo['line']}, column {$errorInfo['column']}";

            // if ($errorInfo['context'] !== '') {
            //     $message .= " | Near: {$errorInfo['context']}";
            // }
        }

        return $message;
    }

    /**
     * Locate the line and column of a JSON parse error by progressively parsing.
     *
     * @return array{line: int, column: int, context: string}|null
     */
    protected function locateJsonError(string $json): ?array
    {
        // Strategy: incrementally parse by adding one line at a time.
        // The last line that introduces a new error is likely the problem.
        $lines = explode("\n", $json);
        $totalLines = count($lines);
        $errorLine = $totalLines;
        $errorColumn = 0;

        // Try a binary-ish approach: parse first N lines to find where error starts
        $accumulated = '';
        for ($i = 0; $i < $totalLines; $i++) {
            $accumulated .= ($i > 0 ? "\n" : '').$lines[$i];

            // Close any open structures to make partial JSON parseable
            $testJson = $this->closeJsonStructures($accumulated);
            json_decode($testJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorLine = $i + 1;

                // Estimate column: find the problematic character in this line
                $errorColumn = $this->estimateErrorColumn($lines[$i]);

                break;
            }
        }

        // Build context: show the error line and one line above/below
        $contextLines = [];
        $start = max(0, $errorLine - 2);
        $end = min($totalLines - 1, $errorLine);
        for ($i = $start; $i <= $end; $i++) {
            $lineNum = $i + 1;
            $marker = ($lineNum === $errorLine) ? ' >>>' : '    ';
            $contextLines[] = "{$marker} L{$lineNum}: ".rtrim($lines[$i]);
        }

        return [
            'line' => $errorLine,
            'column' => $errorColumn,
            'context' => implode(' | ', $contextLines),
        ];
    }

    /**
     * Close any open JSON structures so partial JSON can be tested for parse validity.
     */
    protected function closeJsonStructures(string $partialJson): string
    {
        $openBraces = 0;
        $openBrackets = 0;
        $inString = false;
        $escaped = false;
        $len = strlen($partialJson);

        for ($i = 0; $i < $len; $i++) {
            $char = $partialJson[$i];

            if ($escaped) {
                $escaped = false;

                continue;
            }
            if ($char === '\\' && $inString) {
                $escaped = true;

                continue;
            }
            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }
            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $openBraces++;
            } elseif ($char === '}') {
                $openBraces--;
            } elseif ($char === '[') {
                $openBrackets++;
            } elseif ($char === ']') {
                $openBrackets--;
            }
        }

        // If we're inside a string, close it
        if ($inString) {
            $partialJson .= '"';
        }

        // Remove any trailing comma before closing
        $partialJson = preg_replace('/,\s*$/', '', $partialJson);

        // Close open structures
        $partialJson .= str_repeat(']', max(0, $openBrackets));
        $partialJson .= str_repeat('}', max(0, $openBraces));

        return $partialJson;
    }

    /**
     * Estimate the column position of an error within a single JSON line.
     */
    protected function estimateErrorColumn(string $line): int
    {
        $trimmed = rtrim($line);
        $len = strlen($trimmed);

        // Common JSON issues: trailing comma, missing comma, unquoted key
        // Check for trailing comma before } or ]
        if (preg_match('/,\s*$/', $trimmed)) {
            return $len;
        }

        // Return end of meaningful content as best guess
        return max(1, $len);
    }

    /**
     * Handle view request - returns only componentSettings
     */
    protected function handleViewRequest(Request $request, string $modelName, string $viewKey): mixed
    {
        $viewCfg = $this->loadViewConfig($modelName);
        if (empty($viewCfg)) {
            return response()->json(['error' => "View config file missing for model '{$modelName}'"], 422);
        }

        // Find the view (must contain "View" in the key name)
        if (! str_contains($viewKey, 'View')) {
            return response()->json(['error' => "Invalid view key '{$viewKey}'. View keys must contain 'View' in the name."], 422);
        }

        if (! array_key_exists($viewKey, $viewCfg)) {
            // Try to find first view as default
            $firstView = $this->getFirstView($viewCfg);
            if ($firstView) {
                $viewKey = $firstView;
            } else {
                return response()->json(['error' => "View '{$viewKey}' not found in config"], 422);
            }
        }

        $viewBlock = $viewCfg[$viewKey] ?? [];
        $components = $viewBlock['components'] ?? null;

        if (! is_array($components)) {
            return response()->json(['error' => "View '{$viewKey}' does not have a valid 'components' configuration"], 422);
        }

        // Return only componentSettings
        return response()->json([
            'componentSettings' => $components,
        ]);
    }

    /**
     * Get the first view key from view config (keys containing "View")
     */
    protected function getFirstView(array $viewCfg): ?string
    {
        foreach (array_keys($viewCfg) as $key) {
            if (str_contains($key, 'View')) {
                return $key;
            }
        }

        return null;
    }

    public function resolveViewComponent(string $modelName, ?string $componentKey, ?string $columnsParam): array
    {
        if (! $componentKey || ! is_string($componentKey) || $componentKey === '') {
            throw new \InvalidArgumentException('component parameter is required');
        }

        // Parse component reference: could be "table" or "cform/table" or "person/table"
        $targetModel = $modelName;
        $targetComponent = $componentKey;

        if (str_contains($componentKey, '/')) {
            [$targetModel, $targetComponent] = explode('/', $componentKey, 2);
        }

        $viewCfg = $this->loadViewConfig($targetModel);
        if (empty($viewCfg)) {
            throw new \InvalidArgumentException("View config file missing for model '{$targetModel}'");
        }
        if (! array_key_exists($targetComponent, $viewCfg)) {
            throw new \InvalidArgumentException("Component key '{$targetComponent}' not found in view config for model '{$targetModel}'");
        }
        $compBlock = $viewCfg[$targetComponent] ?? [];

        // Inherit lang from view context if component doesn't have it
        if (! isset($compBlock['lang']) || ! is_array($compBlock['lang']) || empty($compBlock['lang'])) {
            $inheritedLang = $this->findLangFromViews($viewCfg, $targetModel, $targetComponent);
            if ($inheritedLang) {
                $compBlock['lang'] = $inheritedLang;
            }
        }

        if (! $columnsParam) {
            $compColumns = $this->getColumnsForComponent($compBlock, 'table');
            if (is_array($compColumns) && ! empty($compColumns)) {
                $columnsParam = implode(',', array_map('trim', $compColumns));
            } else {
                // Components like 'meta' may not have columns - that's okay
                $columnsParam = '';
            }
        }

        return [
            'componentKey' => (string) $targetComponent,
            'compBlock' => $compBlock,
            'columnsParam' => (string) $columnsParam,
            'targetModel' => (string) $targetModel,
        ];
    }

    /**
     * Find lang configuration from views that reference this component
     */
    protected function findLangFromViews(array $viewCfg, string $targetModel, string $targetComponent): ?array
    {
        // Look for views that reference this component
        foreach ($viewCfg as $key => $block) {
            // Check if this is a view
            if (! str_contains($key, 'View') || ! is_array($block)) {
                continue;
            }

            $components = $block['components'] ?? null;
            if (! is_array($components)) {
                continue;
            }

            // Check if this view references our component
            foreach ($components as $alias => $reference) {
                // Parse reference to check if it matches our target
                $refTarget = $reference;
                if (str_contains($reference, '/')) {
                    [$refModel, $refComponent] = explode('/', $reference, 2);
                    // Check if it's the same model and component
                    if (strtolower($refModel) === strtolower($targetModel) && $refComponent === $targetComponent) {
                        $refTarget = $targetComponent;
                    } else {
                        continue;
                    }
                } else {
                    $refTarget = $reference;
                }

                // If this view references our component, return its lang
                if ($refTarget === $targetComponent && isset($block['lang']) && is_array($block['lang'])) {
                    return $block['lang'];
                }
            }
        }

        return null;
    }

    public function isLangAllowedForComponent(array $compBlock, string $lang): bool
    {
        $this->logDebug('Entering isLangAllowedForComponent', ['method' => __METHOD__, 'lang' => $lang]);
        $allowedLangs = $compBlock['lang'] ?? null;
        if (! is_array($allowedLangs) || empty($allowedLangs)) {
            return false;
        }
        $allowedNormalized = array_values(array_unique(array_map(fn ($l) => strtolower((string) $l), $allowedLangs)));

        return in_array(strtolower($lang), $allowedNormalized, true);
    }

    public function getAllowedFiltersFromComponent(array $compBlock): ?array
    {
        $this->logDebug('Entering getAllowedFiltersFromComponent', ['method' => __METHOD__]);

        return is_array(($compBlock['filters'] ?? null)) ? array_values($compBlock['filters']) : null;
    }

    public function getColumnCustomizationsFromComponent(array $compBlock): ?array
    {
        $this->logDebug('Entering getColumnCustomizationsFromComponent', ['method' => __METHOD__]);
        $columnCustomizations = $compBlock['columnCustomizations'] ?? null;

        return is_array($columnCustomizations) ? $columnCustomizations : null;
    }

    public function loadComponentConfig(string $componentSettingsKey): array
    {
        $path = __DIR__.'/ComponentConfigs/'.basename($componentSettingsKey).'.json';
        if (! File::exists($path)) {
            return [];
        }
        $this->logDebug('Entering loadComponentConfig', ['method' => __METHOD__, 'key' => $componentSettingsKey]);
        $json = File::get($path);
        $cfg = json_decode($json, true) ?: [];

        return $cfg;
    }

    // ──────────────────────────────────────────────
    //  External JS functions
    // ──────────────────────────────────────────────

    /**
     * Resolve external JS function references in a functions map.
     *
     * @param  array<string, mixed>  $functions
     * @return array<string, string>
     */
    protected function resolveExternalFunctions(array $functions): array
    {
        $resolved = [];

        foreach ($functions as $name => $definition) {
            if (is_string($definition)) {
                $resolved[$name] = $definition;

                continue;
            }

            if (is_array($definition) && isset($definition['file'], $definition['function'])) {
                $body = $this->extractFunctionBody(
                    (string) $definition['file'],
                    (string) $definition['function']
                );
                $resolved[$name] = $body;

                continue;
            }

            $resolved[$name] = $definition;
        }

        return $resolved;
    }

    /**
     * Extract the body of a named JS function from a file in the js_scripts_path directory.
     *
     * @return string The function body, or an error string if not found.
     */
    protected function extractFunctionBody(string $fileName, string $functionName): string
    {
        $base = base_path(config('uiapi.js_scripts_path', 'app/Services/jsScripts'));
        $path = rtrim($base, '/').'/'.basename($fileName);

        if (! File::exists($path)) {
            $error = "[UiApi] JS file not found: {$fileName}";
            $this->logDebug($error, ['method' => __METHOD__, 'file' => $fileName]);
            Log::warning($error);

            return "/* ERROR: {$error} */";
        }

        $content = File::get($path);

        $pattern = '/\bfunction\s+'.preg_quote($functionName, '/').'\s*\([^)]*\)\s*\{/';
        if (! preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            $error = "[UiApi] Function '{$functionName}' not found in {$fileName}";
            $this->logDebug($error, ['method' => __METHOD__, 'file' => $fileName, 'function' => $functionName]);
            Log::warning($error);

            return "/* ERROR: {$error} */";
        }

        $openBracePos = strpos($content, '{', $matches[0][1]);
        if ($openBracePos === false) {
            $error = "[UiApi] Could not parse function '{$functionName}' in {$fileName}";
            Log::warning($error);

            return "/* ERROR: {$error} */";
        }

        $depth = 0;
        $len = strlen($content);
        $bodyStart = $openBracePos + 1;
        $bodyEnd = null;

        for ($i = $openBracePos; $i < $len; $i++) {
            if ($content[$i] === '{') {
                $depth++;
            } elseif ($content[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $bodyEnd = $i;
                    break;
                }
            }
        }

        if ($bodyEnd === null) {
            $error = "[UiApi] Unmatched braces in function '{$functionName}' in {$fileName}";
            Log::warning($error);

            return "/* ERROR: {$error} */";
        }

        $body = substr($content, $bodyStart, $bodyEnd - $bodyStart);

        $lines = explode("\n", $body);
        $trimmed = array_map('trim', $lines);
        $trimmed = array_filter($trimmed, fn ($line) => $line !== '');

        return implode(' ', $trimmed);
    }

    // ──────────────────────────────────────────────
    //  Label, key, and display helpers
    // ──────────────────────────────────────────────

    protected function labelFor(array $columnDef, string $field, string $lang): string
    {
        $supportedLangs = $columnDef['lang'] ?? [];
        $supportedLangs = is_array($supportedLangs)
            ? array_values(array_unique(array_map(fn ($l) => strtolower((string) $l), $supportedLangs)))
            : [];

        $preferredLang = null;
        if (count($supportedLangs) === 1) {
            $preferredLang = $supportedLangs[0];
        } else {
            $preferredLang = strtolower((string) ($lang ?: 'dv'));
        }

        $label = $columnDef['label'] ?? null;
        if (is_string($label) && $label !== '') {
            return $label;
        }

        if (is_array($label)) {
            if ($preferredLang && array_key_exists($preferredLang, $label) && is_string($label[$preferredLang]) && $label[$preferredLang] !== '') {
                return (string) $label[$preferredLang];
            }
            if (count($supportedLangs) === 1) {
                foreach ($label as $val) {
                    if (is_string($val) && $val !== '') {
                        return (string) $val;
                    }
                }
            }
            foreach (['en', 'dv'] as $fallback) {
                if (array_key_exists($fallback, $label) && is_string($label[$fallback]) && $label[$fallback] !== '') {
                    return (string) $label[$fallback];
                }
            }
            foreach ($label as $val) {
                if (is_string($val) && $val !== '') {
                    return (string) $val;
                }
            }
        }

        return Str::title(str_replace('_', ' ', $field));
    }

    protected function keyFor(array $columnDef, string $field): string
    {
        return (string) ($columnDef['key'] ?? $field);
    }

    /**
     * Reorder headers based on the 'order' key in columnCustomizations.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @param  array<string, mixed>|null  $columnCustomizations
     * @return array<int, array<string, mixed>>
     */
    protected function reorderHeadersByCustomOrder(array $headers, ?array $columnCustomizations): array
    {
        if (! is_array($columnCustomizations) || empty($headers)) {
            return $headers;
        }

        $orderMap = [];
        foreach ($columnCustomizations as $key => $props) {
            if (is_array($props) && array_key_exists('order', $props)) {
                $orderMap[$key] = (int) $props['order'];
            }
        }

        if (empty($orderMap)) {
            return $headers;
        }

        $ordered = [];
        $unordered = [];
        foreach ($headers as $header) {
            $val = $header['value'] ?? '';
            if (array_key_exists($val, $orderMap)) {
                $ordered[] = ['header' => $header, 'order' => $orderMap[$val]];
            } else {
                $unordered[] = $header;
            }
        }

        $result = $unordered;
        usort($ordered, fn ($a, $b) => $a['order'] <=> $b['order']);

        foreach ($ordered as $item) {
            $pos = max(0, min($item['order'], count($result)));
            array_splice($result, $pos, 0, [$item['header']]);
        }

        return array_values($result);
    }

    protected function defaultFilterTypeForDef(array $columnDef): string
    {
        $this->logDebug('Entering defaultFilterTypeForDef', ['method' => __METHOD__]);
        $colType = strtolower((string) ($columnDef['type'] ?? 'string'));

        return match ($colType) {
            'date', 'datetime', 'timestamp' => 'Date',
            default => 'Text',
        };
    }

    /**
     * Map schema 'type' to a default 'inputType' for form fields when not explicitly provided.
     */
    protected function defaultInputTypeForType(?string $type): string
    {
        $t = strtolower((string) ($type ?? ''));

        return match ($t) {
            'string' => 'textField',
            'number' => 'numberField',
            'boolean' => 'checkbox',
            'date' => 'datepicker',
            default => ''
        };
    }

    protected function pickHeaderLangOverride(array $columnDef, string $requestLang): ?string
    {
        $this->logDebug('Entering pickHeaderLangOverride', ['method' => __METHOD__, 'requestLang' => $requestLang]);
        $langs = $columnDef['lang'] ?? [];
        if (! is_array($langs)) {
            return null;
        }
        $normalized = array_values(array_unique(array_map(fn ($l) => strtolower((string) $l), $langs)));
        if (empty($normalized)) {
            return null;
        }
        $current = strtolower($requestLang);
        $candidates = array_values(array_filter($normalized, fn ($l) => $l !== $current));
        if (in_array($current, $normalized, true)) {
            if (! empty($candidates)) {
                if ($current === 'en' && in_array('dv', $candidates, true)) {
                    return 'dv';
                }
                if ($current === 'dv' && in_array('en', $candidates, true)) {
                    return 'en';
                }

                return $candidates[0];
            }

            return null;
        }
        if (in_array('en', $normalized, true)) {
            return 'en';
        }
        if (in_array('dv', $normalized, true)) {
            return 'dv';
        }

        return $normalized[0];
    }

    /**
     * Normalize display-type specific configs for headers.
     */
    protected function normalizeDisplayConfig(string $displayType, array $config, string $lang): array
    {
        $type = strtolower($displayType);
        if ($type === 'chip') {
            $out = [];
            foreach ($config as $key => $opt) {
                if (! is_array($opt)) {
                    $out[$key] = $opt;

                    continue;
                }
                $optOut = $opt;
                $label = $opt['label'] ?? null;
                if (is_array($label)) {
                    $optOut['label'] = (string) ($label[$lang] ?? $label['dv'] ?? $label['en'] ?? reset($label) ?? '');
                } elseif (is_string($label) && $label !== '') {
                    $optOut['label'] = $label;
                }
                $out[$key] = $optOut;
            }

            return $out;
        }

        return $config;
    }

    protected function resolveCustomizedTitle(?array $columnCustomizations, string $token, string $lang): ?string
    {
        if (! is_array($columnCustomizations)) {
            return null;
        }
        $custom = $columnCustomizations[$token] ?? null;
        if (! is_array($custom)) {
            return null;
        }
        $title = $custom['title'] ?? null;
        if ($title === null) {
            return null;
        }
        if (is_array($title)) {
            return (string) ($title[$lang] ?? $title['en'] ?? reset($title) ?? '');
        }
        if (is_string($title) && $title !== '') {
            return $title;
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  Overrides
    // ──────────────────────────────────────────────

    protected function applyOverridesToSection(array $sectionPayload, array $overrides, string $lang): array
    {
        foreach ($overrides as $overrideKey => $overrideVal) {
            if (in_array($overrideKey, $this->internalKeys, true)) {
                continue;
            }
            if ($overrideKey === 'functions' && is_array($overrideVal)) {
                $sectionPayload['functions'] = $this->resolveExternalFunctions($overrideVal);

                continue;
            }
            if (is_scalar($overrideVal)) {
                $targetKey = $overrideKey;
                if (! array_key_exists($targetKey, $sectionPayload)) {
                    $plural = Str::plural($overrideKey);
                    $singular = Str::singular($overrideKey);
                    foreach ([$plural, $singular] as $cand) {
                        if (array_key_exists($cand, $sectionPayload)) {
                            $targetKey = $cand;
                            break;
                        }
                    }
                }
                if (is_string($overrideVal) && strtolower($overrideVal) === 'off') {
                    if (array_key_exists($targetKey, $sectionPayload)) {
                        unset($sectionPayload[$targetKey]);
                    }

                    continue;
                }
                if (! array_key_exists($targetKey, $sectionPayload)) {
                    if ($this->getAllowCustomComponentKeys()) {
                        $sectionPayload[$overrideKey] = $overrideVal;
                    }

                    continue;
                }
                $sectionPayload[$targetKey] = $overrideVal;

                continue;
            }

            if (! is_array($overrideVal)) {
                continue;
            }

            $targetKey = $overrideKey;
            if (! array_key_exists($targetKey, $sectionPayload)) {
                $plural = Str::plural($overrideKey);
                $singular = Str::singular($overrideKey);
                foreach ([$plural, $singular] as $cand) {
                    if (array_key_exists($cand, $sectionPayload)) {
                        $targetKey = $cand;
                        break;
                    }
                }
                if (! array_key_exists($targetKey, $sectionPayload)) {
                    if ($this->getAllowCustomComponentKeys()) {
                        $sectionPayload[$overrideKey] = $overrideVal;
                    }

                    continue;
                }
            }

            $original = $sectionPayload[$targetKey];
            if (! is_array($original)) {
                $sectionPayload[$targetKey] = $overrideVal;

                continue;
            }

            $allStrings = count($overrideVal) > 0;
            $hasAssoc = false;
            foreach ($overrideVal as $v) {
                if (is_array($v)) {
                    $hasAssoc = true;
                    $allStrings = false;
                    break;
                }
                if (! is_string($v)) {
                    $allStrings = false;
                }
            }

            if ($allStrings) {
                $wanted = array_values(array_unique(array_map('strval', $overrideVal)));
                $wantedLower = array_map('strtolower', $wanted);
                $filtered = [];
                foreach ($original as $it) {
                    if (is_array($it)) {
                        $candidate = (string) ($it['key'] ?? $it['name'] ?? $it['type'] ?? $it['label'] ?? '');
                        $candidateLower = strtolower($candidate);
                        if ($candidate !== '' && in_array($candidateLower, $wantedLower, true)) {
                            $filtered[] = $it;
                        }
                    } elseif (is_string($it)) {
                        if (in_array(strtolower($it), $wantedLower, true)) {
                            $filtered[] = $it;
                        }
                    }
                }
                $sectionPayload[$targetKey] = $filtered;

                continue;
            }

            if ($hasAssoc) {
                if ($targetKey === 'fields') {
                    $merged = [];
                    foreach ($overrideVal as $ov) {
                        if (! is_array($ov)) {
                            continue;
                        }
                        $ovKey = (string) ($ov['key'] ?? '');
                        $existing = null;
                        if ($ovKey !== '') {
                            foreach ($original as $item) {
                                if (is_array($item) && array_key_exists('key', $item) && (string) $item['key'] === $ovKey) {
                                    $existing = $item;
                                    break;
                                }
                            }
                        }
                        $merged[] = $existing ? array_merge($existing, $ov) : $ov;
                    }
                    $sectionPayload[$targetKey] = $merged;

                    continue;
                }

                $merged = $original;
                foreach ($overrideVal as $ov) {
                    if (! is_array($ov)) {
                        continue;
                    }
                    $ovKey = (string) ($ov['key'] ?? '');
                    $matchedIndex = null;
                    if ($ovKey !== '') {
                        foreach ($merged as $idx => $item) {
                            if (is_array($item) && array_key_exists('key', $item) && (string) $item['key'] === $ovKey) {
                                $matchedIndex = $idx;
                                break;
                            }
                        }
                    }
                    if ($matchedIndex !== null) {
                        $merged[$matchedIndex] = array_merge($merged[$matchedIndex], $ov);
                    } else {
                        $merged[] = $ov;
                    }
                }
                $sectionPayload[$targetKey] = $merged;

                continue;
            }

            $sectionPayload[$targetKey] = $overrideVal;
        }

        return $sectionPayload;
    }

    // ──────────────────────────────────────────────
    //  Relation helpers
    // ──────────────────────────────────────────────

    protected function resolveRelatedModel(Model $model, string $relation): ?Model
    {
        if (! method_exists($model, $relation)) {
            return null;
        }
        try {
            $rel = $model->{$relation}();
        } catch (\Throwable $e) {
            $rel = null;
        }
        if ($rel instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
            return $rel->getRelated();
        }

        return null;
    }

    protected function pickDefaultTitleField(Model $related): string
    {
        $schema = method_exists($related, 'apiSchema') ? $related->apiSchema() : [];
        $columns = $schema['columns'] ?? [];
        foreach (['name', 'name_eng', 'title'] as $preferred) {
            if (array_key_exists($preferred, $columns) && ($columns[$preferred]['hidden'] ?? false) === false) {
                return $preferred;
            }
        }
        foreach ($columns as $field => $def) {
            if (($def['hidden'] ?? false) === false && ($def['type'] ?? '') === 'string') {
                return $field;
            }
        }

        return 'id';
    }

    public function columnSupportsLang(array $def, string $lang): bool
    {
        $langs = $def['lang'] ?? null;
        if (! is_array($langs)) {
            return true;
        }
        $normalized = array_values(array_unique(array_map(fn ($l) => strtolower((string) $l), $langs)));

        return in_array(strtolower($lang), $normalized, true);
    }
}
