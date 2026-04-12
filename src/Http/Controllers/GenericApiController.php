<?php

namespace Ogp\UiApi\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class GenericApiController extends BaseController
{
    protected function requestLang(Request $request): string
    {
        $lang = strtolower((string) $request->query('lang', 'dv'));

        return in_array($lang, ['dv', 'en'], true) ? $lang : 'dv';
    }

    /**
     * @param  array{dv:string,en:string}  $messages
     */
    protected function localizedMessage(Request $request, array $messages): string
    {
        $lang = $this->requestLang($request);

        return $messages[$lang] ?? $messages['dv'];
    }

    /**
     * @return array<string, array{dv:string,en:string}>
     */
    protected function messageCatalog(string $modelBaseName): array
    {
        return [
            'invalid_resource' => [
                'dv' => 'ރިސޯސުގެ ބާވަތް އަދި ރަނގަޅެއް ނޫން',
                'en' => 'Invalid resource type.',
            ],
            'model_not_found' => [
                'dv' => 'މޯޑަލް ނުފެނުނޭ....',
                'en' => 'Model not found.',
            ],
            'record_not_found' => [
                'dv' => 'ރެކޯޑެއް ނުފެނުނު.',
                'en' => 'Record not found.',
            ],
            'validation_failed' => [
                'dv' => 'ސޭވް ނުކުރެވުނު - ވެލިޑޭޝަން ފެއިލްވި',
                'en' => 'Validation failed.',
            ],
            'created' => [
                'dv' => 'ރެކޯޑް ސޭވްކުރެވިއްޖެ!',
                'en' => "{$modelBaseName} created successfully.",
            ],
            'updated' => [
                'dv' => 'ރެކޯޑު އަޕްޑޭޓް ކުރެވިއްޖެ!',
                'en' => "{$modelBaseName} updated successfully.",
            ],
            'deleted' => [
                'dv' => 'ރެކޯޑު ފުހެލެވިއްޖެ!',
                'en' => "{$modelBaseName} deleted successfully.",
            ],
            'unable_save' => [
                'dv' => 'މައްސަލައެއް ދިމާވެއްޖެ - ރެކޯޑު ސޭވެއް ނުކުރެވުނު!',
                'en' => 'Unable to save the record.',
            ],
            'unable_update' => [
                'dv' => 'ރެކޯޑް އަޕްޑޭޓު ކުރެވޭނެ ނުވޭ.',
                'en' => 'Unable to update the record.',
            ],
            'unexpected' => [
                'dv' => 'މައްސަލައެއް ޖެހިއްޖެ. އަލުން ބަލާލާ.',
                'en' => 'Something went wrong while processing your request.',
            ],
        ];
    }

    public function index(Request $request, string $model)
    {
        $requestParams = array_change_key_case($request->all(), CASE_LOWER);
        $modelClass = $this->resolveModelClass($model);
        if (! $modelClass) {
            $catalog = $this->messageCatalog('Resource');

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['model_not_found']),
            ], Response::HTTP_BAD_REQUEST);
        }

        $query = $modelClass::query()
            ->selectColumns($requestParams['columns'] ?? null)
            ->search($requestParams['search'] ?? null)
            ->searchAll($requestParams['searchall'] ?? null)
            ->withoutRow($requestParams['withoutrow'] ?? null)
            ->filter($requestParams['filter'] ?? null)
            ->withRelations($requestParams['with'] ?? null)
            ->withPivotRelations($requestParams['pivot'] ?? null)
            ->sort($requestParams['sort'] ?? null);

        $perPage = isset($requestParams['per_page']) ? (int) $requestParams['per_page'] : null;
        $page = isset($requestParams['page']) ? (int) $requestParams['page'] : null;
        $records = $modelClass::paginateFromRequest(
            $query,
            $requestParams['pagination'] ?? null,
            $perPage,
            $page
        );

        if (($requestParams['pagination'] ?? null) !== 'off' && $records instanceof Paginator) {
            // Build list of auto-added foreign keys (belongsTo) to hide from JSON output
            $autoHidden = [];
            // Also hide auto-included computed attribute dependencies
            $columnsParam = $requestParams['columns'] ?? null;
            $requestedCols = is_string($columnsParam) && $columnsParam !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $columnsParam)), fn ($c) => $c !== ''))
                : [];
            if (isset($requestParams['with']) && is_string($requestParams['with'])) {
                try {
                    $modelInstance = new $modelClass;
                    foreach (explode(',', $requestParams['with']) as $spec) {
                        [$path] = array_pad(explode(':', $spec, 2), 2, null);
                        $path = is_string($path) ? trim($path) : '';
                        if ($path === '') {
                            continue;
                        }
                        $topLevel = explode('.', $path)[0];
                        if (! method_exists($modelInstance, $topLevel)) {
                            continue;
                        }
                        try {
                            $rel = $modelInstance->{$topLevel}();
                            if ($rel instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                                $autoHidden[] = $rel->getForeignKeyName();
                            }
                        } catch (\Throwable $e) {
                            // ignore
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            // Compute dependencies hidden list: only hide those not explicitly requested
            try {
                $modelInstance = isset($modelInstance) ? $modelInstance : new $modelClass;
                if (method_exists($modelInstance, 'getComputedAttributeDependencies')) {
                    $depsMap = $modelInstance->getComputedAttributeDependencies();
                    foreach ($depsMap as $attr => $deps) {
                        if (in_array($attr, $requestedCols, true)) {
                            foreach ((array) $deps as $depCol) {
                                if (! in_array($depCol, $requestedCols, true)) {
                                    $autoHidden[] = $depCol;
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }

            try {
                $total = (int) (clone $query)->toBase()->getCountForPagination();
            } catch (\Throwable $e) {
                $total = (int) (clone $query)->count();
            }

            $effectivePerPage = $perPage ?? $records->perPage();
            $lastPage = (int) max(1, (int) ceil(($total > 0 ? $total : 1) / max(1, (int) $effectivePerPage)));

            // Hide auto-included foreign keys from serialized output
            $items = array_map(function ($m) use ($autoHidden) {
                if (! empty($autoHidden) && method_exists($m, 'makeHidden')) {
                    $m->makeHidden($autoHidden);
                }

                return $m;
            }, $records->items());

            $response = [
                'data' => $items,
            ];

            if (! empty($items)) {
                $response['pagination'] = [
                    'current_page' => $records->currentPage(),
                    'first_page' => 1,
                    'last_page' => $lastPage,
                    'per_page' => $effectivePerPage,
                    'total' => $total,
                ];
            }

            return response()->json($response);
        }

        // Non-paginated response; hide auto-included foreign keys if any
        $autoHidden = [];
        $columnsParam = $requestParams['columns'] ?? null;
        $requestedCols = is_string($columnsParam) && $columnsParam !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $columnsParam)), fn ($c) => $c !== ''))
            : [];
        if (isset($requestParams['with']) && is_string($requestParams['with'])) {
            try {
                $modelInstance = new $modelClass;
                foreach (explode(',', $requestParams['with']) as $spec) {
                    [$path] = array_pad(explode(':', $spec, 2), 2, null);
                    $path = is_string($path) ? trim($path) : '';
                    if ($path === '') {
                        continue;
                    }
                    $topLevel = explode('.', $path)[0];
                    if (! method_exists($modelInstance, $topLevel)) {
                        continue;
                    }
                    try {
                        $rel = $modelInstance->{$topLevel}();
                        if ($rel instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                            $autoHidden[] = $rel->getForeignKeyName();
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Compute dependencies hidden list: only hide those not explicitly requested
        try {
            $modelInstance = isset($modelInstance) ? $modelInstance : new $modelClass;
            if (method_exists($modelInstance, 'getComputedAttributeDependencies')) {
                $depsMap = $modelInstance->getComputedAttributeDependencies();
                foreach ($depsMap as $attr => $deps) {
                    if (in_array($attr, $requestedCols, true)) {
                        foreach ((array) $deps as $depCol) {
                            if (! in_array($depCol, $requestedCols, true)) {
                                $autoHidden[] = $depCol;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        if (! empty($autoHidden) && $records instanceof \Illuminate\Support\Collection) {
            $records = $records->map(function ($m) use ($autoHidden) {
                if (method_exists($m, 'makeHidden')) {
                    $m->makeHidden($autoHidden);
                }

                return $m;
            });
        }

        return response()->json(['data' => $records]);
    }

    public function show(Request $request, string $model, string $id)
    {
        // Log::info(json_encode([
        //     'url' => $request->url(),
        //     'request' => $request->all(),
        // ]));

        // $payload = json_decode($request->input('payload'), true);
        // Log::info($payload);

        $requestParams = array_change_key_case($request->all(), CASE_LOWER);
        $modelClass = $this->resolveModelClass($model);
        if (! $modelClass) {
            $catalog = $this->messageCatalog('Resource');

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['model_not_found']),
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $query = $modelClass::query()
                ->selectColumns($requestParams['columns'] ?? null)
                ->withRelations($requestParams['with'] ?? null)
                ->withPivotRelations($requestParams['pivot'] ?? null);

            $record = $this->resolveRecordByIdentifier($modelClass, $id, $query);

            $payload = json_decode($request->input('payload'), true);
            // Log::info($payload);
            if (is_array($payload)) {
                foreach ($payload as $key => $config) {

                    if (! str_contains($key, '.')) {
                        continue;
                    }

                    [$relation, $field] = explode('.', $key, 2);

                    // Get the related ID from the main record
                    $relatedId = $record->{$field} ?? null;
                    if (! $relatedId) {
                        continue;
                    }

                    // Build the API URL dynamically from dataLink
                    $dataLink = rtrim($config['dataLink'] ?? '', '/');
                    $url = "{$dataLink}/{$relation}/{$relatedId}";
                    $postBody = ['fields' => $config['fields'] ?? []];

                    // Log::info('Related data request', [
                    //     'url'       => $url,
                    //     'method'    => 'POST',
                    //     'body'      => $postBody,
                    //     'relation'  => $relation,
                    //     'relatedId' => $relatedId,
                    //     'config'    => $config,
                    // ]);

                    try {
                        $response = Http::post($url, $postBody);
                        Log::info($response);

                        if ($response->successful()) {
                            $relatedData = $response->json();

                            // Keep keys as in 'fields' mapping
                            // $mapped = [];
                            // foreach (($config['fields'] ?? []) as $keyInOutput => $dbField) {
                            //     $mapped[$keyInOutput] = $relatedData[$dbField] ?? null;
                            // }

                            // Attach mapped data to main record
                            // $record->setAttribute($relation, $mapped);
                            $record->setAttribute($relation, $relatedData);
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to fetch related data from {$url}: ".$e->getMessage());
                    }
                }
            }

            return response()->json($record);
        } catch (ModelNotFoundException $e) {
            $catalog = $this->messageCatalog(class_basename($modelClass));

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['record_not_found']).$this->debugSuffix($e),
            ], Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            $catalog = $this->messageCatalog(class_basename($modelClass));

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['unexpected']).$this->debugSuffix($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(Request $request, string $model)
    {

        $modelClass = $this->resolveModelClass($model);

        if (! $modelClass) {
            $catalog = $this->messageCatalog('Resource');

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['invalid_resource']),
            ], Response::HTTP_BAD_REQUEST);
        }

        $modelBaseName = class_basename($modelClass);
        $catalog = $this->messageCatalog($modelBaseName);

        try {
            return DB::transaction(function () use ($request, $modelClass, $catalog) {

                // Centralized validation
                $validated = $modelClass::validate($request);
                Log::info('Validated data', ['data' => $validated]);
                // Create record
                $record = $modelClass::createFromArray(
                    $validated,
                    $request->user()
                );

                // Optional pivot handling
                $modelClass::handlePivots($request, $record);

                // Model-level file handling
                $modelClass::handleFiles($request, $record, $request->user());

                // Host app file service
                if (class_exists(\App\Services\FileUploadService::class)) {
                    app(\App\Services\FileUploadService::class)->handle(
                        $request,
                        $record,
                        class_basename($modelClass),
                        $request->user()
                    );
                }

                return response()->json([
                    'message' => $this->localizedMessage($request, $catalog['created']),
                    'data' => $record,
                ], Response::HTTP_CREATED);
            });

        } catch (ValidationException $e) {
            // Validation errors: return model-defined messages
            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['validation_failed']),
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (QueryException $e) {
            // DB constraint / SQL errors
            Log::error('Database error while creating record', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['unable_save']).$this->debugSuffix($e),
                'errors' => $this->parseQueryExceptionErrors($e),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (\Throwable $e) {
            // Any other unexpected error
            Log::critical('Unexpected error while creating record', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['unexpected']).$this->debugSuffix($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, string $model, string $id)
    {
        $modelClass = $this->resolveModelClass($model);

        if (! $modelClass) {
            $catalog = $this->messageCatalog('Resource');

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['model_not_found']),
            ], Response::HTTP_BAD_REQUEST);
        }

        $modelBaseName = class_basename($modelClass);
        $catalog = $this->messageCatalog($modelBaseName);

        $record = $this->resolveRecordByIdentifier($modelClass, $id);
        try {
            return DB::transaction(function () use ($request, $modelClass, $catalog, $record) {
                // Centralized, model-driven validation
                $validated = $modelClass::validate($request, $record->id);

                // Update using validated data only
                $record = $record->updateFromArray(
                    $validated,
                    $request->user()
                );

                // Handle file uploads on update if applicable
                $modelClass::handleFiles($request, $record, $request->user() ?: null);

                return response()->json([
                    'message' => $this->localizedMessage($request, $catalog['updated']),
                    'data' => $record,
                ], Response::HTTP_OK);
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Validation errors: return model-defined messages
            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['validation_failed']),
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (QueryException $e) {
            // DB constraint / SQL errors
            Log::error('Database error while updating record', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
                'sql' => method_exists($e, 'getSql') ? $e->getSql() : null,
                'bindings' => method_exists($e, 'getBindings') ? $e->getBindings() : null,
            ]);

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['unable_update']).$this->debugSuffix($e),
                'errors' => $this->parseQueryExceptionErrors($e),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            // Any other unexpected error
            Log::critical('Unexpected error while updating record', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['unexpected']).$this->debugSuffix($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy(Request $request, string $model, string $id)
    {
        $modelClass = $this->resolveModelClass($model);
        if (! $modelClass) {
            $catalog = $this->messageCatalog('Resource');

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['model_not_found']),
            ], Response::HTTP_BAD_REQUEST);
        }

        $catalog = $this->messageCatalog(class_basename($modelClass));

        try {
            $record = $this->resolveRecordByIdentifier($modelClass, $id);
            $record->delete();

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['deleted']),
            ], Response::HTTP_OK);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['record_not_found']).$this->debugSuffix($e),
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * @return array<string, string[]>
     */
    protected function parseQueryExceptionErrors(QueryException $e): array
    {
        $message = $e->getMessage();
        $errorCode = $e->errorInfo[1] ?? null;

        // Unique constraint violation (MySQL 1062 / PostgreSQL 23505)
        if ($errorCode == 1062 || ($e->errorInfo[0] ?? '') === '23505') {
            if (preg_match("/for key '[^.]*\.?([^']+)'/", $message, $m)) {
                $index = $m[1];
                // Try to extract column name from index name (convention: table_column_unique)
                $parts = explode('_', $index);
                // Remove 'unique' suffix if present
                if (end($parts) === 'unique') {
                    array_pop($parts);
                }
                // Remove table name prefix (first part)
                if (count($parts) > 1) {
                    array_shift($parts);
                }
                $column = implode('_', $parts);

                return [$column => ['The value for '.$column.' already exists.']];
            }
            // PostgreSQL: extract from detail
            if (preg_match('/Key \(([^)]+)\)/', $message, $m)) {
                $column = trim($m[1]);

                return [$column => ['The value for '.$column.' already exists.']];
            }

            return ['database' => ['A duplicate entry was found.']];
        }

        // Foreign key constraint violation (MySQL 1452 / PostgreSQL 23503)
        if ($errorCode == 1452 || ($e->errorInfo[0] ?? '') === '23503') {
            if (preg_match('/FOREIGN KEY \(`?([^`)]+)`?\)/', $message, $m)) {
                $column = $m[1];

                return [$column => ['The referenced record for '.$column.' does not exist.']];
            }

            return ['database' => ['A referenced record does not exist.']];
        }

        // Not null violation (MySQL 1048 / PostgreSQL 23502)
        if ($errorCode == 1048 || ($e->errorInfo[0] ?? '') === '23502') {
            if (preg_match("/Column '([^']+)'/", $message, $m)) {
                $column = $m[1];

                return [$column => ['The '.$column.' field cannot be empty.']];
            }
            if (preg_match('/column "([^"]+)"/', $message, $m)) {
                $column = $m[1];

                return [$column => ['The '.$column.' field cannot be empty.']];
            }

            return ['database' => ['A required field is missing.']];
        }

        return ['database' => ['A database error occurred.']];
    }

    protected function debugSuffix(\Throwable $e): string
    {
        if (! config('app.debug')) {
            return '';
        }

        return ' '.$e->getMessage();
    }

    /**
     * Resolve a record by UUID or integer ID.
     *
     * Resolution order when $id looks like a UUID:
     *   1. Use $modelClass::$uuidColumn if declared on the model.
     *   2. Fall back to a column literally named 'uuid' if it exists on the table.
     *   3. Fall back to integer primary key lookup.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|null  $query  Existing query builder (e.g. with selects/relations already applied)
     */
    /**
     * Resolve a record by UUID or integer ID.
     *
     * Resolution order when $id looks like a UUID:
     *   1. Use $modelClass::$uuidColumn if declared on the model.
     *   2. Fall back to a column literally named 'uuid' if it exists on the table.
     *   3. Fall back to integer primary key lookup.
     *
     * When config('uiapi.enforce_uuid') is true, integer ID lookups are rejected
     * with a 404 for models that have a UUID column.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|null  $query  Existing query builder (e.g. with selects/relations already applied)
     */
    protected function resolveRecordByIdentifier(string $modelClass, string $id, ?\Illuminate\Database\Eloquent\Builder $query = null): \Illuminate\Database\Eloquent\Model
    {
        $isUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
        $instance = new $modelClass;

        $uuidColumn = null;
        if (property_exists($instance, 'uuidColumn')) {
            $uuidColumn = $instance->uuidColumn;
        } elseif (\Illuminate\Support\Facades\Schema::hasColumn($instance->getTable(), 'uuid')) {
            $uuidColumn = 'uuid';
        }

        if ($isUuid && $uuidColumn) {
            $q = $query ?? $modelClass::query();

            return $q->where($uuidColumn, $id)->firstOrFail();
        }

        // Enforce UUID: reject integer ID lookups for models that have a UUID column
        if (! $isUuid && $uuidColumn && config('uiapi.enforce_uuid', false)) {
            throw new ModelNotFoundException;
        }

        $q = $query ?? $modelClass::query();

        return $q->findOrFail((int) $id);
    }

    protected function resolveModelClass(string $model): ?string
    {
        // Try multiple normalized variants to support multi-word models via '-', '_', spaces, '.'
        $names = array_values(array_unique([
            ucfirst(strtolower($model)),
            Str::studly($model),
            Str::studly(str_replace(['-', ' ', '.'], '_', $model)),
        ]));

        foreach ($names as $name) {
            $packageFqcn = 'Ogp\\UiApi\\Models\\'.$name;
            $appFqcn = 'App\\Models\\'.$name;

            // Prefer existing files to avoid noisy autoload warnings
            $packagePath = base_path('vendor/ogp/uiapi/src/Models/'.$name.'.php');
            $appPath = base_path('app/Models/'.$name.'.php');

            if (file_exists($packagePath) && class_exists($packageFqcn)) {
                return $packageFqcn;
            }
            if (file_exists($appPath) && class_exists($appFqcn)) {
                return $appFqcn;
            }

            // Fallback: let autoloader resolve if possible
            if (class_exists($packageFqcn)) {
                return $packageFqcn;
            }
            if (class_exists($appFqcn)) {
                return $appFqcn;
            }
        }

        return null;
    }
}
