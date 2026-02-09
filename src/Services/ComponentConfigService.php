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

    public function __construct()
    {
        // Read logging flag safely without triggering missing-config exceptions
        $cfg = config('uiapi');
        $this->loggingEnabled = is_array($cfg) && array_key_exists('logging_enabled', $cfg)
            ? (bool) $cfg['logging_enabled']
            : false;
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

    protected function logDebug(string $message, array $context = []): void
    {
        if ($this->isLoggingEnabled()) {
            Log::debug($message, $context);
        }
    }

    protected function canonicalComponentName(string $key): string
    {
        $this->logDebug('Entering canonicalComponentName', ['method' => __METHOD__, 'key' => $key]);
        $canonical = (string) preg_replace('/\d+$/', '', $key);

        return $canonical !== '' ? $canonical : $key;
    }

    protected function resolveModel(string $modelName): ?array
    {
        $this->logDebug('Entering resolveModel', ['method' => __METHOD__, 'model' => $modelName]);

        // Try multiple normalized variants to support multi-word models via '-', '_', spaces, '.'
        $names = array_values(array_unique([
            ucfirst(strtolower($modelName)),
            Str::studly($modelName),
            Str::studly(str_replace(['-', ' ', '.'], '_', $modelName)),
        ]));

        $fqcn = null;
        foreach ($names as $name) {
            $packageFqcn = 'Ogp\\UiApi\\Models\\'.$name;
            $appFqcn = 'App\\Models\\'.$name;

            // Avoid noisy autoload warnings by preferring existing files when possible
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
            // As a fallback, accept if autoloader can resolve the class without explicit file checks
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

    protected function normalizeColumnsSubset(Model $model, ?string $columns, array $columnsSchema): array
    {
        $columnsSubsetNormalized = null;
        $relationsFromColumns = [];
        if ($columns) {
            $tokens = array_filter(array_map('trim', explode(',', $columns)));
            $columnsSubsetNormalized = [];
            foreach ($tokens as $token) {
                if (Str::contains($token, '.')) {
                    [$first, $rest] = array_pad(explode('.', $token, 2), 2, null);
                    if (! $rest) {
                        throw new \InvalidArgumentException("Invalid columns segment '$token'");
                    }
                    $this->logDebug('Entering normalizeColumnsSubset', ['method' => __METHOD__]);
                    $relationName = null;
                    if (method_exists($model, $first)) {
                        try {
                            $relTest = $model->{$first}();
                        } catch (\Throwable $e) {
                            $relTest = null;
                        }
                        if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relationName = $first;
                        }
                    }
                    if (! $relationName) {
                        $camel = Str::camel($first);
                        if (method_exists($model, $camel)) {
                            try {
                                $relTest = $model->{$camel}();
                            } catch (\Throwable $e) {
                                $relTest = null;
                            }
                            if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                                $relationName = $camel;
                            }
                        }
                    }
                    if (! $relationName && Str::endsWith($first, '_id')) {
                        $guess = Str::camel(substr($first, 0, -3));
                        if (method_exists($model, $guess)) {
                            try {
                                $relTest = $model->{$guess}();
                            } catch (\Throwable $e) {
                                $relTest = null;
                            }
                            if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                                $relationName = $guess;
                            }
                        }
                    }
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
                    if (! array_key_exists($token, $columnsSchema)) {
                        throw new \InvalidArgumentException("Column '$token' is not defined in apiSchema");
                    }
                    $columnsSubsetNormalized[] = $token;
                }
            }
        }

        return [$columnsSubsetNormalized, array_unique($relationsFromColumns)];
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

    public function index(Request $request, string $modelName)
    {
        $this->logDebug('Entering index', ['method' => __METHOD__, 'model' => $modelName]);
        // First resolve the view component to inspect for noModel mode
        try {
            $resolvedComp = $this->resolveViewComponent(
                $modelName,
                $request->query('component'),
                $request->query('columns')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $compBlock = $resolvedComp['compBlock'];
        $columnsParam = $resolvedComp['columnsParam'];

        // Branch: noModel flow -> do not resolve model/apiSchema; use columnsSchema from view config
        $isNoModel = (bool) ($compBlock['noModel'] ?? false);
        if ($isNoModel) {
            $columnsSchema = is_array($compBlock['columnsSchema'] ?? null) ? $compBlock['columnsSchema'] : [];
            if (empty($columnsSchema)) {
                return response()->json([
                    'error' => 'noModel mode requires columnsSchema in view config',
                ], 422);
            }

            $lang = (string) ($request->query('lang') ?? 'dv');
            if (! $this->isLangAllowedForComponent($compBlock, $lang)) {
                return response()->json([
                    'message' => "Language '$lang' not supported by view config",
                    'data' => [],
                ]);
            }

            $perPage = (int) ($request->query('per_page') ?? ($compBlock['per_page'] ?? 25));

            [$columnsSubsetNormalized, $relationsFromColumns] = $this->normalizeColumnsSubsetNoModel($columnsParam, $columnsSchema);
            $effectiveTokens = $this->filterTokensByLangSupportNoModel($columnsSchema, $columnsSubsetNormalized ?? [], $lang);

            $component = (string) ($resolvedComp['componentKey'] ?? '');
            $columnCustomizations = $this->getColumnCustomizationsFromComponent($compBlock);
            $allowedFilters = $this->getAllowedFiltersFromComponent($compBlock);

            $componentSettingsQuery = $request->query('componentSettings');
            if (is_string($componentSettingsQuery) && $componentSettingsQuery !== '') {
                $cfg = $this->loadComponentConfig($componentSettingsQuery);
                if (empty($cfg)) {
                    return response()->json([
                        'error' => "Component config '{$componentSettingsQuery}' not found",
                    ], 422);
                }

                $componentSettings = $this->buildComponentSettingsNoModel(
                    $componentSettingsQuery,
                    $columnsSchema,
                    $effectiveTokens,
                    $lang,
                    $perPage,
                    $modelName,
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

                $componentSettings = $this->buildComponentSettingsForComponentsNoModel(
                    $componentKeys,
                    $columnsSchema,
                    $effectiveTokens,
                    $lang,
                    $perPage,
                    $modelName,
                    $columnCustomizations,
                    $allowedFilters,
                    is_array($componentsMap) ? $componentsMap : null
                );
            }

            // Include meta only when declared and not already built; apply per-view overrides
            $componentsMap = $compBlock['components'] ?? [];
            $shouldAppendMeta = is_array($componentsMap)
                && array_key_exists('meta', $componentsMap)
                && ! array_key_exists('meta', $componentSettings);
            if ($shouldAppendMeta) {
                $metaCfg = $this->loadComponentConfig('meta');
                if (! empty($metaCfg) && isset($metaCfg['meta']) && is_array($metaCfg['meta'])) {
                    $metaPayload = $this->buildSectionPayloadNoModel(
                        $metaCfg['meta'],
                        $columnsSchema,
                        $effectiveTokens,
                        $lang,
                        $perPage,
                        $modelName,
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

            $topLevelHeaders = null;
            if ($this->getIncludeTopLevelHeaders()) {
                $topLevelHeaders = $this->buildTopLevelHeadersNoModel($columnsSchema, $effectiveTokens, $lang, $columnCustomizations);
            }

            $topLevelFilters = null;
            if ($this->getIncludeTopLevelFilters()) {
                $topLevelFilters = $this->buildFilters($columnsSchema, $modelName, $lang, $allowedFilters);
            }

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

            return response()->json($response);
        }

        // ----- Existing model-backed flow -----
        $resolved = $this->resolveModel($modelName);

        if (! $resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }

        [$fqcn, $modelInstance, $schema] = $resolved;
        $columnsSchema = $schema['columns'] ?? [];
        $searchable = $schema['searchable'] ?? [];

        try {
            [$columnsSubsetNormalized, $relationsFromColumns] = $this->normalizeColumnsSubset($modelInstance, $columnsParam, $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $with = $request->query('with');
        $relations = $this->parseWithRelations($fqcn, $modelInstance, $with);
        if (! empty($relationsFromColumns)) {
            foreach ($relationsFromColumns as $rel) {
                if (! in_array($rel, $relations, true)) {
                    $relations[] = $rel;
                }
            }
        }

        $lang = (string) ($request->query('lang') ?? 'dv');

        if (! $this->isLangAllowedForComponent($compBlock, $lang)) {
            return response()->json([
                'message' => "Language '$lang' not supported by view config",
                'data' => [],
            ]);
        }

        $q = $request->query('q');
        $perPage = (int) ($request->query('per_page') ?? ($compBlock['per_page'] ?? 25));

        $effectiveTokens = $this->filterTokensByLangSupport($modelInstance, $columnsSchema, $columnsSubsetNormalized ?? [], $lang);
        $records = [];

        $component = (string) ($resolvedComp['componentKey'] ?? '');
        $columnCustomizations = $this->getColumnCustomizationsFromComponent($compBlock);
        $allowedFilters = $this->getAllowedFiltersFromComponent($compBlock);
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

        // Include meta only when declared and not already built; apply per-view overrides
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

        $topLevelHeaders = null;
        if ($this->getIncludeTopLevelHeaders()) {
            $columnCustomizations = $this->getColumnCustomizationsFromComponent($compBlock);
            $topLevelHeaders = $this->buildTopLevelHeaders($modelInstance, $columnsSchema, $effectiveTokens, $lang, $columnCustomizations);
        }

        $topLevelFilters = null;
        if ($this->getIncludeTopLevelFilters()) {
            $allowedFilters = $this->getAllowedFiltersFromComponent($compBlock);
            $topLevelFilters = $this->buildTopLevelFilters(
                $fqcn,
                $modelInstance,
                $columnsSchema,
                $columnsSubsetNormalized,
                [],
                $q,
                $searchable,
                $lang,
                $allowedFilters
            );
        }

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

        return response()->json($response);
    }

    /**
     * Normalize tokens for noModel flow; collect relation names from dot tokens.
     */
    protected function normalizeColumnsSubsetNoModel(?string $columns, array $columnsSchema): array
    {
        $columnsSubsetNormalized = null;
        $relationsFromColumns = [];
        if ($columns) {
            $tokens = array_filter(array_map('trim', explode(',', $columns)));
            $columnsSubsetNormalized = [];
            foreach ($tokens as $token) {
                if (Str::contains($token, '.')) {
                    [$first, $rest] = array_pad(explode('.', $token, 2), 2, null);
                    if (! $rest) {
                        throw new \InvalidArgumentException("Invalid columns segment '$token'");
                    }
                    $columnsSubsetNormalized[] = $first.'.'.$rest;
                    $relationsFromColumns[] = $first;
                } else {
                    // Validate bare token exists in provided columnsSchema when possible
                    if (! array_key_exists($token, $columnsSchema)) {
                        // Allow passthrough for unknown tokens to support flexible views
                        $columnsSubsetNormalized[] = $token;

                        continue;
                    }
                    $columnsSubsetNormalized[] = $token;
                }
            }
        }

        return [$columnsSubsetNormalized, array_values(array_unique($relationsFromColumns))];
    }

    /**
     * Filter tokens by language support for noModel mode.
     * Dot tokens are passed through; bare tokens checked against columnsSchema lang.
     */
    protected function filterTokensByLangSupportNoModel(array $columnsSchema, array $tokens, string $lang): array
    {
        $out = [];
        foreach ($tokens as $token) {
            if (Str::contains($token, '.')) {
                // If a schema entry exists for the dot token, respect its language support
                $def = $columnsSchema[$token] ?? null;
                if ($def && $this->columnSupportsLang($def, $lang)) {
                    $out[] = $token;
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

    /**
     * Build headers without relying on model relations/apiSchema.
     */
    protected function buildTopLevelHeadersNoModel(
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        ?array $columnCustomizations = null
    ): array {
        $fields = [];
        if (is_array($columnsSubsetNormalized) && ! empty($columnsSubsetNormalized)) {
            $fields = array_values(array_unique(array_filter(array_map(fn ($t) => is_string($t) ? $t : null, $columnsSubsetNormalized))));
        } else {
            $fields = array_keys($columnsSchema);
        }

        // Respect column language support when building headers in noModel mode
        $fields = $this->filterTokensByLangSupportNoModel($columnsSchema, $fields, $lang);

        $headers = [];
        foreach ($fields as $token) {
            $overrideTitle = $this->resolveCustomizedTitle($columnCustomizations, $token, $lang);

            if (Str::contains($token, '.')) {
                [$first, $rest] = array_pad(explode('.', $token, 2), 2, null);
                if (! $rest) {
                    continue;
                }

                // If a schema entry exists for the relation token, use it
                $relDef = $columnsSchema[$token] ?? null;
                if ($relDef) {
                    if (! $this->includeHiddenColumnsInHeaders && (bool) ($relDef['hidden'] ?? false) === true) {
                        continue;
                    }

                    $title = $overrideTitle;
                    if ($title === null) {
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

                    $header = [
                        'title' => $title,
                        'value' => $token,
                        'sortable' => (bool) ($relDef['sortable'] ?? false),
                        'hidden' => (bool) ($relDef['hidden'] ?? false),
                    ];
                    if (array_key_exists('type', $relDef)) {
                        $header['type'] = (string) $relDef['type'];
                    }
                    if (array_key_exists('displayType', $relDef)) {
                        $header['displayType'] = (string) $relDef['displayType'];
                    }
                    // New structure: attach config under the displayType key (e.g., 'chip')
                    if (array_key_exists('displayType', $relDef)) {
                        $dt = (string) $relDef['displayType'];
                        $cfg = $relDef[$dt] ?? ($relDef['displayProps'] ?? null); // legacy fallback
                        if (is_array($cfg)) {
                            $header[$dt] = $this->normalizeDisplayConfig($dt, $cfg, $lang);
                        }
                    }
                    if (array_key_exists('inlineEditable', $relDef)) {
                        $header['inlineEditable'] = (bool) $relDef['inlineEditable'];
                    }
                    $override = $this->pickHeaderLangOverride($relDef, $lang);
                    if ($override !== null) {
                        $header['lang'] = $override;
                    }
                } else {
                    // Fallback when no explicit schema exists for the relation token
                    $header = [
                        'title' => $overrideTitle ?? Str::title(str_replace('_', ' ', $rest)),
                        'value' => $token,
                        'sortable' => false,
                        'hidden' => false,
                    ];
                }

                // Apply column customizations overrides last
                $custom = is_array($columnCustomizations) ? ($columnCustomizations[$token] ?? null) : null;
                if (is_array($custom)) {
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
                    }
                    if (array_key_exists('displayType', $custom)) {
                        $cdt = (string) $custom['displayType'];
                        $ccfg = $custom[$cdt] ?? ($custom['displayProps'] ?? null);
                        if (is_array($ccfg)) {
                            $header[$cdt] = $this->normalizeDisplayConfig($cdt, $ccfg, $lang);
                        }
                    }
                    if (array_key_exists('inlineEditable', $custom)) {
                        $header['inlineEditable'] = (bool) $custom['inlineEditable'];
                    }
                    if (array_key_exists('editable', $custom)) {
                        $header['inlineEditable'] = (bool) $custom['editable'];
                    }
                    foreach ($custom as $k => $v) {
                        if ($k === 'title' || $k === 'value') {
                            continue;
                        }
                        if (! array_key_exists($k, $header)) {
                            $header[$k] = $v;
                        }
                    }
                }

                $headers[] = $header;

                continue;
            }

            $def = $columnsSchema[$token] ?? null;
            if (! $def) {
                continue;
            }
            if (! $this->includeHiddenColumnsInHeaders && (bool) ($def['hidden'] ?? false) === true) {
                continue;
            }
            $header = [
                'title' => $overrideTitle ?? $this->labelFor($def, $token, $lang),
                'value' => $this->keyFor($def, $token),
                'sortable' => (bool) ($def['sortable'] ?? false),
                'hidden' => (bool) ($def['hidden'] ?? false),
            ];
            if (array_key_exists('type', $def)) {
                $header['type'] = (string) $def['type'];
            }
            if (array_key_exists('displayType', $def)) {
                $header['displayType'] = (string) $def['displayType'];
            }
            if (array_key_exists('displayType', $def)) {
                $dt = (string) $def['displayType'];
                $cfg = $def[$dt] ?? ($def['displayProps'] ?? null); // legacy fallback
                if (is_array($cfg)) {
                    $header[$dt] = $this->normalizeDisplayConfig($dt, $cfg, $lang);
                }
            }
            if (array_key_exists('inlineEditable', $def)) {
                $header['inlineEditable'] = (bool) $def['inlineEditable'];
            }
            $override = $this->pickHeaderLangOverride($def, $lang);
            if ($override !== null) {
                $header['lang'] = $override;
            }
            $custom = is_array($columnCustomizations) ? ($columnCustomizations[$token] ?? null) : null;
            if (is_array($custom)) {
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
                }
                if (array_key_exists('displayType', $custom)) {
                    $cdt = (string) $custom['displayType'];
                    $ccfg = $custom[$cdt] ?? ($custom['displayProps'] ?? null);
                    if (is_array($ccfg)) {
                        $header[$cdt] = $this->normalizeDisplayConfig($cdt, $ccfg, $lang);
                    }
                }
                if (array_key_exists('inlineEditable', $custom)) {
                    $header['inlineEditable'] = (bool) $custom['inlineEditable'];
                }
                if (array_key_exists('editable', $custom)) {
                    $header['inlineEditable'] = (bool) $custom['editable'];
                }
                foreach ($custom as $k => $v) {
                    if ($k === 'title' || $k === 'value') {
                        continue;
                    }
                    if (! array_key_exists($k, $header)) {
                        $header[$k] = $v;
                    }
                }
            }
            $headers[] = $header;
        }

        return $headers;
    }

    /**
     * Build data link URL without model relations (derive "with" from dot tokens only).
     */
    protected function buildDataLinkNoModel(
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        string $modelName,
        int $perPage
    ): string {
        $baseTokens = [];
        if (is_array($columnsSubsetNormalized) && ! empty($columnsSubsetNormalized)) {
            $baseTokens = array_values(array_unique(array_filter(array_map(fn ($t) => is_string($t) ? $t : null, $columnsSubsetNormalized))));
        } else {
            $baseTokens = array_keys($columnsSchema);
        }
        $tokens = $this->filterTokensByLangSupportNoModel($columnsSchema, $baseTokens, $lang);

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
        $base = url("/{$prefix}/gapi/{$modelName}");

        $query = 'columns='.implode(',', $tokens);
        if (! empty($withSegments)) {
            $query .= '&with='.implode(',', $withSegments);
        }
        $query .= '&per_page='.$perPage;

        return $base.'?'.$query;
    }

    /**
     * Build create link (relative) for POST create endpoint.
     * Per requirements: plain string, relative path starting with gapi/ and no params.
     */
    protected function buildCreateLink(string $modelName): string
    {
        return 'gapi/'.$modelName;
    }

    /**
     * Build form fields array from schema (works for both model and noModel flows).
     * - Includes all non-hidden base fields from schema
     * - Also includes relation dot-tokens present in columnsSubsetNormalized
     * - Does not filter by language (explicitly includes irrespective of lang)
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
            // Model-backed: include only if formField === true. Missing -> excluded.
            // noModel: include all columns regardless of formField/hidden.
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
            $langsRaw = $def['lang'] ?? null;
            $langValue = '';
            if (is_array($langsRaw)) {
                $normalized = array_values(array_unique(array_map(fn ($l) => (string) $l, $langsRaw)));
                if (count($normalized) === 1) {
                    $langValue = (string) $normalized[0];
                } elseif (! empty($normalized)) {
                    $langValue = in_array($lang, $normalized, true) ? (string) $lang : (string) $normalized[0];
                }
            }
            $fieldOut = [
                'key' => $key,
                'label' => $label,
                'lang' => $langValue,
                'inputType' => $inputType,
            ];

            // For select inputType, include itemTitle/itemValue and items or url
            if (strtolower($inputType) === 'select') {
                $cfg = $def['select'] ?? ($def['filterable'] ?? null); // legacy fallback
                if (is_array($cfg)) {
                    $mode = strtolower((string) ($cfg['mode'] ?? 'self'));
                    $rawItemTitle = $cfg['itemTitle'] ?? $key;
                    $itemTitle = is_array($rawItemTitle)
                        ? (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle) ?? $key)
                        : (string) $rawItemTitle;
                    $itemValue = (string) ($cfg['itemValue'] ?? $key);
                    $fieldOut['itemTitle'] = $itemTitle;
                    $fieldOut['itemValue'] = $itemValue;

                    if ($mode === 'self') {
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
                        $fieldOut['items'] = $outItems;
                    } else {
                        // relation mode: build URL for fetching options
                        $relationship = (string) ($cfg['relationship'] ?? '');
                        $related = null;
                        if ($modelInstance instanceof Model && $relationship !== '') {
                            $related = $this->resolveRelatedModel($modelInstance, $relationship);
                        }
                        $relatedName = $related ? class_basename($related) : null;
                        if (! $relatedName) {
                            // Try to infer from key
                            $base = $key;
                            if (\Illuminate\Support\Str::endsWith($base, '_id')) {
                                $base = \Illuminate\Support\Str::beforeLast($base, '_id');
                            }
                            $relatedName = \Illuminate\Support\Str::studly($base);
                        }
                        $prefix = config('uiapi.route_prefix', 'api');
                        $columnsParam = $itemValue.','.$itemTitle;
                        $sortParam = $itemTitle;
                        $fieldOut['url'] = url('/'.$prefix.'/gapi/'.$relatedName).'?columns='.$columnsParam.'&sort='.$sortParam.'&pagination=off&wrap=data';
                    }
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
                if ($modelInstance instanceof Model) {
                    // Model-backed: resolve nested related model and use leaf field schema for lang/type/label
                    $segments = explode('.', $token);
                    $leaf = array_pop($segments);
                    $chain = implode('.', $segments);
                    $related = $this->resolveNestedRelatedModel($modelInstance, $chain);
                    $leafDef = null;
                    if ($related instanceof Model && method_exists($related, 'apiSchema')) {
                        $schema = $related->apiSchema();
                        $relCols = is_array($schema['columns'] ?? null) ? $schema['columns'] : [];
                        $leafDef = $relCols[$leaf] ?? null;
                    }
                    // Relations: display only if leaf schema has formField === true
                    if (! (is_array($leafDef) && ((bool) ($leafDef['formField'] ?? false) === true))) {
                        continue;
                    }
                    $key = is_array($leafDef) ? $this->keyFor($leafDef, $leaf) : $token;
                    $label = is_array($leafDef) ? $this->labelFor($leafDef, $leaf, $lang) : Str::title(str_replace('_', ' ', (string) $leaf));
                    $inputType = is_array($leafDef) ? (string) ($leafDef['inputType'] ?? '') : '';
                    if ($inputType === '') {
                        $inputType = $this->defaultInputTypeForType(is_array($leafDef) ? ($leafDef['type'] ?? null) : null);
                    }
                    $langsRaw = is_array($leafDef) ? ($leafDef['lang'] ?? null) : null;
                    $langValue = '';
                    if (is_array($langsRaw)) {
                        $normalized = array_values(array_unique(array_map(fn ($l) => (string) $l, $langsRaw)));
                        if (count($normalized) === 1) {
                            $langValue = (string) $normalized[0];
                        } elseif (! empty($normalized)) {
                            $langValue = in_array($lang, $normalized, true) ? (string) $lang : (string) $normalized[0];
                        }
                    }

                    $fieldOut = [
                        'key' => $key,
                        'label' => $label,
                        'lang' => $langValue,
                        'inputType' => $inputType,
                    ];
                    if (strtolower($inputType) === 'select' && is_array($leafDef)) {
                        $cfg = $leafDef['select'] ?? ($leafDef['filterable'] ?? null);
                        if (is_array($cfg)) {
                            $mode = strtolower((string) ($cfg['mode'] ?? 'self'));
                            $rawItemTitle = $cfg['itemTitle'] ?? $key;
                            $itemTitle = is_array($rawItemTitle)
                                ? (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle) ?? $key)
                                : (string) $rawItemTitle;
                            $itemValue = (string) ($cfg['itemValue'] ?? $key);
                            $fieldOut['itemTitle'] = $itemTitle;
                            $fieldOut['itemValue'] = $itemValue;
                            if ($mode === 'self') {
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
                                $fieldOut['items'] = $outItems;
                            } else {
                                $relationship = (string) ($cfg['relationship'] ?? '');
                                $related = null;
                                if ($modelInstance instanceof Model && $relationship !== '') {
                                    $related = $this->resolveRelatedModel($modelInstance, $relationship);
                                }
                                $relatedName = $related ? class_basename($related) : null;
                                if (! $relatedName) {
                                    $base = $key;
                                    if (\Illuminate\Support\Str::endsWith($base, '_id')) {
                                        $base = \Illuminate\Support\Str::beforeLast($base, '_id');
                                    }
                                    $relatedName = \Illuminate\Support\Str::studly($base);
                                }
                                $prefix = config('uiapi.route_prefix', 'api');
                                $columnsParam = $itemValue.','.$itemTitle;
                                $sortParam = $itemTitle;
                                $fieldOut['url'] = url('/'.$prefix.'/gapi/'.$relatedName).'?columns='.$columnsParam.'&sort='.$sortParam.'&pagination=off&wrap=data';
                            }
                        }
                    }

                    $fields[] = $fieldOut;
                } else {
                    // noModel: include all columns; use explicit dot-token schema if present; otherwise include with empty lang
                    $def = $columnsSchema[$token] ?? [];
                    $key = $this->keyFor(is_array($def) ? $def : [], $token);
                    $label = is_array($def)
                        ? $this->labelFor($def, $rest, $lang)
                        : Str::title(str_replace('_', ' ', (string) $rest));
                    $inputType = is_array($def) ? (string) ($def['inputType'] ?? '') : '';
                    if ($inputType === '' && is_array($def)) {
                        $inputType = $this->defaultInputTypeForType($def['type'] ?? null);
                    }
                    $langsRaw = is_array($def) ? ($def['lang'] ?? null) : null;
                    $langValue = '';
                    if (is_array($langsRaw)) {
                        $normalized = array_values(array_unique(array_map(fn ($l) => (string) $l, $langsRaw)));
                        if (count($normalized) === 1) {
                            $langValue = (string) $normalized[0];
                        } elseif (! empty($normalized)) {
                            $langValue = in_array($lang, $normalized, true) ? (string) $lang : (string) $normalized[0];
                        }
                    }

                    $fieldOut = [
                        'key' => $key,
                        'label' => $label,
                        'lang' => $langValue,
                        'inputType' => $inputType,
                    ];
                    if (strtolower($inputType) === 'select' && is_array($def)) {
                        $cfg = $def['select'] ?? ($def['filterable'] ?? null);
                        if (is_array($cfg)) {
                            $mode = strtolower((string) ($cfg['mode'] ?? 'self'));
                            $rawItemTitle = $cfg['itemTitle'] ?? $key;
                            $itemTitle = is_array($rawItemTitle)
                                ? (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle) ?? $key)
                                : (string) $rawItemTitle;
                            $itemValue = (string) ($cfg['itemValue'] ?? $key);
                            $fieldOut['itemTitle'] = $itemTitle;
                            $fieldOut['itemValue'] = $itemValue;
                            if ($mode === 'self') {
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
                                $fieldOut['items'] = $outItems;
                            } else {
                                // noModel relation: infer related model name from relationship or token
                                $relationship = (string) ($cfg['relationship'] ?? '');
                                $relatedName = $relationship !== '' ? \Illuminate\Support\Str::studly($relationship) : null;
                                if (! $relatedName) {
                                    $base = $key;
                                    if (\Illuminate\Support\Str::contains($token, '.')) {
                                        $base = \Illuminate\Support\Str::before($token, '.');
                                    } elseif (\Illuminate\Support\Str::endsWith($base, '_id')) {
                                        $base = \Illuminate\Support\Str::beforeLast($base, '_id');
                                    }
                                    $relatedName = \Illuminate\Support\Str::studly($base);
                                }
                                $prefix = config('uiapi.route_prefix', 'api');
                                $columnsParam = $itemValue.','.$itemTitle;
                                $sortParam = $itemTitle;
                                $fieldOut['url'] = url('/'.$prefix.'/gapi/'.$relatedName).'?columns='.$columnsParam.'&sort='.$sortParam.'&pagination=off&wrap=data';
                            }
                        }
                    }

                    $fields[] = $fieldOut;
                }
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

    /**
     * Section payload builder for noModel mode.
     */
    protected function buildSectionPayloadNoModel(
        array $node,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        int $perPage,
        string $modelName,
        ?array $columnCustomizations = null,
        ?array $allowedFilters = null
    ): array {
        $out = [];
        foreach ($node as $key => $val) {
            if ($key === 'crudLink') {
                if ($val === 'on') {
                    $out['crudLink'] = $this->buildCreateLink($modelName);
                } elseif ($val === 'off') {
                    // omit
                } else {
                    $out['crudLink'] = $val;
                }

                continue;
            }
            if ($key === 'fields') {
                if ($val === 'on') {
                    $out['fields'] = $this->buildFormFieldsFromSchema($columnsSchema, $columnsSubsetNormalized, $lang);
                } elseif ($val === 'off') {
                } else {
                    // Pass-through custom fields definition
                    $out['fields'] = $val;
                }

                continue;
            }
            if ($key === 'createLink') {
                if ($val === 'on') {
                    $out['createLink'] = $this->buildCreateLink($modelName);
                } elseif ($val === 'off') {
                } else {
                    // Pass-through custom value (string|object)
                    $out['createLink'] = $val;
                }

                continue;
            }
            if ($key === 'headers') {
                if ($val === 'on') {
                    $out['headers'] = $this->buildTopLevelHeadersNoModel($columnsSchema, $columnsSubsetNormalized, $lang, $columnCustomizations);
                } elseif ($val === 'off') {
                } else {
                    $out['headers'] = $val;
                }

                continue;
            }
            if ($key === 'filters') {
                if ($val === 'on') {
                    $out['filters'] = $this->buildFilters($columnsSchema, $modelName, $lang, $allowedFilters);
                } elseif ($val === 'off') {
                } else {
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
                } elseif ($val === 'off') {
                } else {
                    $out['pagination'] = $val;
                }

                continue;
            }
            if ($key === 'datalink') {
                if ($val === 'on') {
                    $out['datalink'] = $this->buildDataLinkNoModel(
                        $columnsSchema,
                        $columnsSubsetNormalized,
                        $lang,
                        $modelName,
                        $perPage
                    );
                } elseif ($val === 'off') {
                } else {
                    $out['datalink'] = $val;
                }

                continue;
            }
            if (is_array($val)) {
                $out[$key] = $this->buildSectionPayloadNoModel($val, $columnsSchema, $columnsSubsetNormalized, $lang, $perPage, $modelName, $columnCustomizations, $allowedFilters);
            } else {
                $out[$key] = $val;
            }
        }

        return $out;
    }

    /**
     * Build component settings for a single component key (noModel mode).
     */
    protected function buildComponentSettingsNoModel(
        string $componentSettingsKey,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        int $perPage,
        string $modelName,
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
            $componentSettings[$componentSettingsKey] = $this->buildSectionPayloadNoModel(
                $sectionCfg,
                $columnsSchema,
                $columnsSubsetNormalized,
                $lang,
                $perPage,
                $modelName,
                $columnCustomizations,
                $allowedFilters
            );
        }

        foreach ($configFile as $sectionName => $sectionVal) {
            if ($sectionName === $componentSettingsKey) {
                continue;
            }

            if (is_array($sectionVal)) {
                $componentSettings[$sectionName] = $this->buildSectionPayloadNoModel(
                    $sectionVal,
                    $columnsSchema,
                    $columnsSubsetNormalized,
                    $lang,
                    $perPage,
                    $modelName,
                    $columnCustomizations,
                    $allowedFilters
                );
            }
        }

        return $componentSettings;
    }

    /**
     * Build component settings for multiple component keys declared in view config (noModel mode).
     */
    protected function buildComponentSettingsForComponentsNoModel(
        array $componentKeys,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        int $perPage,
        string $modelName,
        ?array $columnCustomizations = null,
        ?array $allowedFilters = null,
        ?array $componentsOverrides = null
    ): array {
        $componentSettings = [];

        foreach ($componentKeys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $configFile = $this->loadComponentConfig($key);
            if (empty($configFile)) {
                continue;
            }

            if (isset($configFile[$key]) && is_array($configFile[$key])) {
                $sectionCfg = $configFile[$key];
                $payload = $this->buildSectionPayloadNoModel(
                    $sectionCfg,
                    $columnsSchema,
                    $columnsSubsetNormalized,
                    $lang,
                    $perPage,
                    $modelName,
                    $columnCustomizations,
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

    public function loadViewConfig(string $modelName): array
    {
        $base = base_path(config('uiapi.view_configs_path', 'app/Services/viewConfigs'));
        $normalized = str_replace(['-', '_', ' '], '', Str::lower($modelName));
        $path = rtrim($base, '/').'/'.$normalized.'.json';
        if (! File::exists($path)) {
            return [];
        }
        $json = File::get($path);
        $cfg = json_decode($json, true) ?: [];

        return $cfg;
    }

    public function resolveViewComponent(string $modelName, ?string $componentKey, ?string $columnsParam): array
    {
        if (! $componentKey || ! is_string($componentKey) || $componentKey === '') {
            throw new \InvalidArgumentException('component parameter is required');
        }

        $viewCfg = $this->loadViewConfig($modelName);
        if (empty($viewCfg)) {
            throw new \InvalidArgumentException('view config file missing for model');
        }
        if (! array_key_exists($componentKey, $viewCfg)) {
            throw new \InvalidArgumentException('component key not found in view config');
        }
        $compBlock = $viewCfg[$componentKey] ?? [];
        if (! $columnsParam) {
            $compColumns = $compBlock['columns'] ?? [];
            if (! is_array($compColumns) || empty($compColumns)) {
                throw new \InvalidArgumentException('columns not defined in view config for component');
            }
            $columnsParam = implode(',', array_map('trim', $compColumns));
        }

        return [
            'componentKey' => (string) $componentKey,
            'compBlock' => $compBlock,
            'columnsParam' => (string) $columnsParam,
        ];
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

    protected function labelFor(array $columnDef, string $field, string $lang): string
    {
        $this->logDebug('Entering labelFor', ['method' => __METHOD__, 'field' => $field]);
        /**
         * Label selection rules:
         * - Single-language support in `lang` → use that label.
         * - Multi-language support → use label matching request `lang` (defaults to `dv`).
         * - Plain string label → return it.
         * - Fallbacks → try 'en', then 'dv', then first non-empty, else title-cased field.
         */
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
        $this->logDebug('Entering keyFor', ['method' => __METHOD__, 'field' => $field]);

        return (string) ($columnDef['key'] ?? $field);
    }

    protected function defaultFilterTypeForDef(array $columnDef): string
    {
        $this->logDebug('Entering defaultFilterTypeForDef', ['method' => __METHOD__]);
        $colType = strtolower((string) ($columnDef['type'] ?? 'string'));
        switch ($colType) {
            case 'date':
                return 'Date';
            case 'datetime':
            case 'timestamp':
                return 'Date';
            default:
                return 'Text';
        }
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
     * For 'chip', resolve per-option label using the request lang (fallback to 'dv', then 'en').
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

    public function buildFilters(array $columnsSchema, string $modelName, string $lang, ?array $allowedFilters = null): array
    {
        $this->logDebug('Entering buildFilters', ['method' => __METHOD__, 'model' => $modelName]);
        $filters = [];
        foreach ($columnsSchema as $field => $def) {
            if (is_array($allowedFilters) && ! in_array($field, $allowedFilters, true)) {
                continue;
            }
            if (! is_array($def) || ! $this->columnSupportsLang($def, $lang)) {
                continue;
            }
            // New structure: use inputType and config under a key with the same name
            $inputType = (string) ($def['inputType'] ?? $this->defaultInputTypeForType($def['type'] ?? null));
            $cfg = is_string($inputType) && $inputType !== '' ? ($def[$inputType] ?? null) : null;

            // Backward-compatibility: fall back to legacy 'filterable'
            $legacy = $def['filterable'] ?? null;

            $label = $this->labelFor($def, $field, $lang);
            $key = $this->keyFor($def, $field);

            // Determine filter type token for UI
            $typeToken = match (strtolower($inputType)) {
                'select' => 'Select',
                'text', 'textfield' => 'Text',
                'number', 'numberfield' => 'Number',
                'checkbox' => 'Checkbox',
                'date', 'datepicker' => 'Date',
                default => $this->defaultFilterTypeForDef($def),
            };

            // Allow overrides from cfg or legacy
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

            // For select-type filters, build items or relation URL
            if (strtolower($typeToken) === 'select') {
                $source = is_array($cfg) ? $cfg : (is_array($legacy) ? $legacy : []);
                $mode = strtolower((string) ($source['mode'] ?? 'self'));

                $rawItemTitle = $source['itemTitle'] ?? $this->keyFor($def, $field);
                $itemTitle = is_array($rawItemTitle)
                    ? (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle) ?? $this->keyFor($def, $field))
                    : (string) $rawItemTitle;
                $itemValue = (string) ($source['itemValue'] ?? $this->keyFor($def, $field));

                $filter['itemTitle'] = $itemTitle;
                $filter['itemValue'] = $itemValue;

                if ($mode === 'self') {
                    $items = $source['items'] ?? [];
                    $pruned = [];
                    if (is_array($items)) {
                        foreach (array_values($items) as $it) {
                            if (is_array($it)) {
                                $pruned[] = [
                                    $itemTitle => (string) ($it[$itemTitle] ?? ''),
                                    $itemValue => (string) ($it[$itemValue] ?? ''),
                                ];
                            } else {
                                $pruned[] = [
                                    $itemTitle => (string) $it,
                                    $itemValue => (string) $it,
                                ];
                            }
                        }
                    }
                    $filter['items'] = $pruned;
                } else {
                    $relationship = (string) ($source['relationship'] ?? '');
                    $relatedModelName = $relationship !== '' ? Str::studly($relationship) : null;
                    if (! $relatedModelName) {
                        $base = $key;
                        if (Str::endsWith($base, '_id')) {
                            $base = Str::beforeLast($base, '_id');
                        }
                        $relatedModelName = Str::studly($base);
                    }
                    $columnsParam = $itemValue.','.$itemTitle;
                    $sortParam = $itemTitle;
                    $prefix = config('uiapi.route_prefix', 'api');
                    $filter['url'] = url("/{$prefix}/gapi/{$relatedModelName}").'?columns='.$columnsParam.'&sort='.$sortParam.'&pagination=off&wrap=data';
                }
            }

            $filters[] = $filter;
        }

        return $filters;
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

    public function buildSectionPayload(
        array $node,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        int $perPage,
        string $modelName,
        Model $modelInstance,
        ?array $columnCustomizations = null,
        ?array $allowedFilters = null
    ): array {
        $out = [];
        foreach ($node as $key => $val) {
            if ($key === 'crudLink') {
                if ($val === 'on') {
                    $out['crudLink'] = $this->buildCreateLink($modelName);
                } elseif ($val === 'off') {
                    // omit
                } else {
                    $out['crudLink'] = $val;
                }

                continue;
            }
            if ($key === 'fields') {
                if ($val === 'on') {
                    $out['fields'] = $this->buildFormFieldsFromSchema($columnsSchema, $columnsSubsetNormalized, $lang, $modelInstance);
                } elseif ($val === 'off') {
                } else {
                    // Pass-through custom fields definition
                    $out['fields'] = $val;
                }

                continue;
            }
            if ($key === 'createLink') {
                if ($val === 'on') {
                    $out['createLink'] = $this->buildCreateLink($modelName);
                } elseif ($val === 'off') {
                } else {
                    // Pass-through custom value (string|object)
                    $out['createLink'] = $val;
                }

                continue;
            }
            if ($key === 'headers') {
                if ($val === 'on') {
                    $out['headers'] = $this->buildTopLevelHeaders($modelInstance, $columnsSchema, $columnsSubsetNormalized, $lang, $columnCustomizations);
                } elseif ($val === 'off') {
                } else {
                    $out['headers'] = $val;
                }

                continue;
            }
            if ($key === 'filters') {
                if ($val === 'on') {
                    $out['filters'] = $this->buildFilters($columnsSchema, $modelName, $lang, $allowedFilters);
                } elseif ($val === 'off') {
                } else {
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
                } elseif ($val === 'off') {
                } else {
                    $out['pagination'] = $val;
                }

                continue;
            }
            if ($key === 'datalink') {
                if ($val === 'on') {
                    $out['datalink'] = $this->buildDataLink(
                        $modelInstance,
                        $columnsSchema,
                        $columnsSubsetNormalized,
                        $lang,
                        $modelName,
                        $perPage
                    );
                } elseif ($val === 'off') {
                } else {
                    $out['datalink'] = $val;
                }

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

    public function buildComponentSettings(
        string $componentSettingsKey,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        int $perPage,
        string $modelName,
        Model $modelInstance,
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

    public function buildComponentSettingsForComponents(
        array $componentKeys,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        int $perPage,
        string $modelName,
        Model $modelInstance,
        ?array $columnCustomizations = null,
        ?array $allowedFilters = null,
        ?array $componentsOverrides = null
    ): array {
        $componentSettings = [];

        foreach ($componentKeys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $configFile = $this->loadComponentConfig($key);
            if (empty($configFile)) {
                continue;
            }

            if (isset($configFile[$key]) && is_array($configFile[$key])) {
                $sectionCfg = $configFile[$key];
                $payload = $this->buildSectionPayload(
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

    protected function applyOverridesToSection(array $sectionPayload, array $overrides, string $lang): array
    {
        foreach ($overrides as $overrideKey => $overrideVal) {
            // 1) Scalars: set value, or "off" string to remove
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
                $sectionPayload[$targetKey] = $overrideVal;

                continue;
            }

            // 2) Non-array values are ignored
            if (! is_array($overrideVal)) {
                continue;
            }

            // Resolve the target key (supports plural/singular mapping)
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
                // No existing target: set directly and continue
                if (! array_key_exists($targetKey, $sectionPayload)) {
                    $sectionPayload[$targetKey] = $overrideVal;

                    continue;
                }
            }

            $original = $sectionPayload[$targetKey];
            if (! is_array($original)) {
                // Replace non-array target with override array
                $sectionPayload[$targetKey] = $overrideVal;

                continue;
            }

            // Determine override form: array of strings (filter) or array of objects (merge)
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
                // Filter list to only wanted items by key/name/type/label
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
                // For fields overrides in noModel, restrict to provided keys and merge properties
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

                // Default behavior: merge override items with existing list using 'key' match; append new items
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

            // Default: replace target with override array
            $sectionPayload[$targetKey] = $overrideVal;
        }

        return $sectionPayload;
    }

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

    public function buildTopLevelFilters(
        string $fqcn,
        Model $modelInstance,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        array $appliedFilters,
        ?string $q,
        array $searchable,
        string $lang,
        ?array $allowedFilters = null
    ): array {
        $filters = [];

        $fields = is_array($allowedFilters)
            ? $allowedFilters
            : array_keys($columnsSchema);

        foreach ($fields as $field) {
            $def = $columnsSchema[$field] ?? null;
            $f = is_array($def) ? ($def['filterable'] ?? null) : null; // legacy fallback

            if (! is_array($def) || ! $this->columnSupportsLang($def, $lang)) {
                continue;
            }

            if (is_array($columnsSubsetNormalized) && ! in_array($field, $columnsSubsetNormalized, true)) {
                continue;
            }

            $label = $def
                ? $this->labelFor($def, $field, $lang)
                : Str::title(str_replace('_', ' ', $field));

            $key = $def
                ? $this->keyFor($def, $field)
                : $field;

            // New structure: prefer 'inputType' config under matching key
            $inputType = (string) ($def['inputType'] ?? $this->defaultInputTypeForType($def['type'] ?? null));
            $cfg = is_string($inputType) && $inputType !== '' ? ($def[$inputType] ?? null) : null;

            $typeToken = match (strtolower($inputType)) {
                'select' => 'Select',
                'text', 'textfield' => 'Text',
                'number', 'numberfield' => 'Number',
                'checkbox' => 'Checkbox',
                'date', 'datepicker' => 'Date',
                default => ($def ? $this->defaultFilterTypeForDef($def) : 'Search'),
            };

            // Overrides
            $overrideLabel = is_array($cfg) ? ($cfg['label'] ?? null) : (is_array($f) ? ($f['label'] ?? null) : null);
            if (is_array($overrideLabel)) {
                $label = (string) ($overrideLabel[$lang] ?? $overrideLabel['en'] ?? $label);
            } elseif (is_string($overrideLabel) && $overrideLabel !== '') {
                $label = $overrideLabel;
            }
            $key = (string) ((is_array($cfg) ? ($cfg['value'] ?? null) : null) ?? (is_array($f) ? ($f['value'] ?? null) : null) ?? $key);

            $out = [
                'type' => $typeToken,
                'key' => $key,
                'label' => $label,
            ];

            // Select specifics
            if (strtolower($typeToken) === 'select') {
                $source = is_array($cfg) ? $cfg : (is_array($f) ? $f : []);
                $mode = strtolower((string) ($source['mode'] ?? 'self'));

                $rawItemTitle = $source['itemTitle'] ?? $key;
                $itemTitle = is_array($rawItemTitle)
                    ? (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle))
                    : (string) $rawItemTitle;

                $itemValue = (string) ($source['itemValue'] ?? $key);

                $out['itemTitle'] = $itemTitle;
                $out['itemValue'] = $itemValue;

                if ($mode === 'self') {
                    $items = $source['items'] ?? [];
                    $out['items'] = [];
                    if (is_array($items)) {
                        foreach (array_values($items) as $it) {
                            if (is_array($it)) {
                                $out['items'][] = [
                                    $itemTitle => (string) ($it[$itemTitle] ?? ''),
                                    $itemValue => (string) ($it[$itemValue] ?? ''),
                                ];
                            } else {
                                $out['items'][] = [
                                    $itemTitle => (string) $it,
                                    $itemValue => (string) $it,
                                ];
                            }
                        }
                    }
                } else {
                    $relationship = (string) ($source['relationship'] ?? '');
                    $related = $relationship
                        ? $this->resolveRelatedModel($modelInstance, $relationship)
                        : null;
                    if ($related) {
                        $prefix = config('uiapi.route_prefix', 'api');
                        $base = url('/'.$prefix.'/'.class_basename($related));
                        $query = "columns={$itemValue},{$itemTitle}&sort={$itemTitle}&pagination=off&wrap=data";
                        $out['url'] = $base.'?'.$query;
                    }
                }
            }

            $filters[] = $out;
        }

        return $filters;
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

    public function filterTokensByLangSupport(Model $modelInstance, array $columnsSchema, array $tokens, string $lang): array
    {
        $out = [];
        foreach ($tokens as $token) {
            if (Str::contains($token, '.')) {
                [$first, $rest] = array_pad(explode('.', $token, 2), 2, null);
                if (! $rest) {
                    continue;
                }
                $relationName = null;
                if (method_exists($modelInstance, $first)) {
                    try {
                        $relTest = $modelInstance->{$first}();
                    } catch (\Throwable $e) {
                        $relTest = null;
                    }
                    if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                        $relationName = $first;
                    }
                }
                if (! $relationName) {
                    $camel = Str::camel($first);
                    if (method_exists($modelInstance, $camel)) {
                        try {
                            $relTest = $modelInstance->{$camel}();
                        } catch (\Throwable $e) {
                            $relTest = null;
                        }
                        if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relationName = $camel;
                        }
                    }
                }
                if (! $relationName && Str::endsWith($first, '_id')) {
                    $guess = Str::camel(substr($first, 0, -3));
                    if (method_exists($modelInstance, $guess)) {
                        try {
                            $relTest = $modelInstance->{$guess}();
                        } catch (\Throwable $e) {
                            $relTest = null;
                        }
                        if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relationName = $guess;
                        }
                    }
                }

                if ($relationName) {
                    $related = $modelInstance->{$relationName}()->getRelated();
                    $relSchema = method_exists($related, 'apiSchema') ? $related->apiSchema() : [];
                    $relColumns = $relSchema['columns'] ?? [];
                    $relDef = $relColumns[$rest] ?? null;
                    if ($relDef && $this->columnSupportsLang($relDef, $lang)) {
                        $out[] = $token;
                    }

                    continue;
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

    public function buildTopLevelHeaders(
        Model $modelInstance,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        ?array $columnCustomizations = null
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

                $relationName = null;
                if (method_exists($modelInstance, $first)) {
                    try {
                        $relTest = $modelInstance->{$first}();
                    } catch (\Throwable $e) {
                        $relTest = null;
                    }
                    if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                        $relationName = $first;
                    }
                }
                if (! $relationName) {
                    $camel = Str::camel($first);
                    if (method_exists($modelInstance, $camel)) {
                        try {
                            $relTest = $modelInstance->{$camel}();
                        } catch (\Throwable $e) {
                            $relTest = null;
                        }
                        if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relationName = $camel;
                        }
                    }
                }
                if (! $relationName && Str::endsWith($first, '_id')) {
                    $guess = Str::camel(substr($first, 0, -3));
                    if (method_exists($modelInstance, $guess)) {
                        try {
                            $relTest = $modelInstance->{$guess}();
                        } catch (\Throwable $e) {
                            $relTest = null;
                        }
                        if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relationName = $guess;
                        }
                    }
                }

                $relDef = null;
                if ($relationName) {
                    $related = $modelInstance->{$relationName}()->getRelated();
                    $relSchema = method_exists($related, 'apiSchema') ? $related->apiSchema() : [];
                    $relColumns = $relSchema['columns'] ?? [];
                    $relDef = $relColumns[$rest] ?? null;
                }

                if (! $this->includeHiddenColumnsInHeaders && $relDef && (bool) ($relDef['hidden'] ?? false) === true) {
                    continue;
                }

                $title = $overrideTitle;
                if ($title === null) {
                    if ($relDef) {
                        $relLabel = $relDef['relationLabel'] ?? null;
                        if (is_array($relLabel)) {
                            $title = (string) ($relLabel[$lang] ?? $relLabel['en'] ?? $this->labelFor($relDef, $rest, $lang));
                        } elseif (is_string($relLabel) && $relLabel !== '') {
                            $title = $relLabel;
                        } else {
                            $title = $this->labelFor($relDef, $rest, $lang);
                        }
                    }
                }
                if ($title === null) {
                    $title = Str::title(str_replace('_', ' ', $rest));
                }

                $header = [
                    'title' => $title,
                    'value' => $token,
                    'sortable' => (bool) ($relDef['sortable'] ?? false),
                    'hidden' => (bool) ($relDef['hidden'] ?? false),
                ];
                if ($relDef && array_key_exists('type', $relDef)) {
                    $header['type'] = (string) $relDef['type'];
                }
                if ($relDef && array_key_exists('displayType', $relDef)) {
                    $header['displayType'] = (string) $relDef['displayType'];
                }
                // New structure: attach config under the displayType key (e.g., 'chip')
                if ($relDef && array_key_exists('displayType', $relDef)) {
                    $dt = (string) $relDef['displayType'];
                    $cfg = $relDef[$dt] ?? ($relDef['displayProps'] ?? null); // legacy fallback
                    if (is_array($cfg)) {
                        $header[$dt] = $this->normalizeDisplayConfig($dt, $cfg, $lang);
                    }
                }
                if ($relDef && array_key_exists('inlineEditable', $relDef)) {
                    $header['inlineEditable'] = (bool) $relDef['inlineEditable'];
                }
                if ($relDef) {
                    $override = $this->pickHeaderLangOverride($relDef, $lang);
                    if ($override !== null) {
                        $header['lang'] = $override;
                    }
                }
                $custom = is_array($columnCustomizations) ? ($columnCustomizations[$token] ?? null) : null;
                if (is_array($custom)) {
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
                    }
                    if (array_key_exists('displayProps', $custom) && is_array($custom['displayProps'])) {
                        $header['displayProps'] = $custom['displayProps'];
                    }
                    if (array_key_exists('inlineEditable', $custom)) {
                        $header['inlineEditable'] = (bool) $custom['inlineEditable'];
                    }
                    if (array_key_exists('editable', $custom)) {
                        $header['inlineEditable'] = (bool) $custom['editable'];
                    }
                    foreach ($custom as $k => $v) {
                        if ($k === 'title' || $k === 'value') {
                            continue;
                        }
                        if (! array_key_exists($k, $header)) {
                            $header[$k] = $v;
                        }
                    }
                }
                $headers[] = $header;

                continue;
            }

            $def = $columnsSchema[$token] ?? null;
            if (! $def) {
                continue;
            }
            if (! $this->includeHiddenColumnsInHeaders && (bool) ($def['hidden'] ?? false) === true) {
                continue;
            }
            $header = [
                'title' => $overrideTitle ?? $this->labelFor($def, $token, $lang),
                'value' => $this->keyFor($def, $token),
                'sortable' => (bool) ($def['sortable'] ?? false),
                'hidden' => (bool) ($def['hidden'] ?? false),
            ];
            if (array_key_exists('type', $def)) {
                $header['type'] = (string) $def['type'];
            }
            if (array_key_exists('displayType', $def)) {
                $header['displayType'] = (string) $def['displayType'];
            }
            // New structure: attach config under the displayType key (e.g., 'chip')
            if (array_key_exists('displayType', $def)) {
                $dt = (string) $def['displayType'];
                $cfg = $def[$dt] ?? ($def['displayProps'] ?? null); // legacy fallback
                if (is_array($cfg)) {
                    $header[$dt] = $this->normalizeDisplayConfig($dt, $cfg, $lang);
                }
            }
            if (array_key_exists('inlineEditable', $def)) {
                $header['inlineEditable'] = (bool) $def['inlineEditable'];
            }
            $override = $this->pickHeaderLangOverride($def, $lang);
            if ($override !== null) {
                $header['lang'] = $override;
            }
            $custom = is_array($columnCustomizations) ? ($columnCustomizations[$token] ?? null) : null;
            if (is_array($custom)) {
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
                }
                // Custom overrides may still include legacy 'displayProps'
                if (array_key_exists('displayType', $custom)) {
                    $cdt = (string) $custom['displayType'];
                    $ccfg = $custom[$cdt] ?? ($custom['displayProps'] ?? null);
                    if (is_array($ccfg)) {
                        $header[$cdt] = $this->normalizeDisplayConfig($cdt, $ccfg, $lang);
                    }
                }
                if (array_key_exists('inlineEditable', $custom)) {
                    $header['inlineEditable'] = (bool) $custom['inlineEditable'];
                }
                if (array_key_exists('editable', $custom)) {
                    $header['inlineEditable'] = (bool) $custom['editable'];
                }
                foreach ($custom as $k => $v) {
                    if ($k === 'title' || $k === 'value') {
                        continue;
                    }
                    if (! array_key_exists($k, $header)) {
                        $header[$k] = $v;
                    }
                }
            }
            $headers[] = $header;
        }

        return $headers;
    }

    protected function buildDataLink(
        Model $modelInstance,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        string $modelName,
        int $perPage
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
        $base = url("/{$prefix}/gapi/{$modelName}");
        $base = "gapi/{$modelName}";

        $query = 'columns='.implode(',', $tokens);
        if (! empty($withSegments)) {
            $query .= '&with='.implode(',', $withSegments);
        }
        $query .= '&per_page='.$perPage;

        return $base.'?'.$query;
    }
}
