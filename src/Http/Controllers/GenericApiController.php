<?php
namespace Ogp\UiApi\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
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
                'dv' => 'މި ރިސޯސް ބާވަތް ބާޠިލުވެފައި ނުވަތަ ނުފެންނަ.',
                'en' => 'Invalid resource type.',
            ],
            'model_not_found' => [
                'dv' => 'މޮޑަލް ނުފެނުނު.',
                'en' => 'Model not found.',
            ],
            'record_not_found' => [
                'dv' => 'ރެކޯޑް ނުފެނުނު.',
                'en' => 'Record not found.',
            ],
            'validation_failed' => [
                'dv' => 'ޗެކް ނުފާސްވެއްޖެ.',
                'en' => 'Validation failed.',
            ],
            'created' => [
                'dv' => "{$modelBaseName} އުފެއްދައިފި.",
                'en' => "{$modelBaseName} created successfully.",
            ],
            'updated' => [
                'dv' => "{$modelBaseName} އަޕްޑޭޓު ކުރެވިއްޖެ.",
                'en' => "{$modelBaseName} updated successfully.",
            ],
            'deleted' => [
                'dv' => "{$modelBaseName} ފޮހެލާފި.",
                'en' => "{$modelBaseName} deleted successfully.",
            ],
            'unable_save' => [
                'dv' => 'ރެކޯޑް ސޭވް ކުރެވޭނެ ނުވޭ.',
                'en' => 'Unable to save the record.',
            ],
            'unable_update' => [
                'dv' => 'ރެކޯޑް އަޕްޑޭޓު ކުރެވޭނެ ނުވޭ.',
                'en' => 'Unable to update the record.',
            ],
            'unexpected' => [
                'dv' => 'މައްސަލައެއް ޖެހިއްޖެ. އަނބުރާ ފަހަރުގައި ބަލާލާ.',
                'en' => 'Something went wrong while processing your request.',
            ],
        ];
    }

    public function index(Request $request, string $model)
    {
        $requestParams = array_change_key_case($request->all(), CASE_LOWER);
        $modelClass    = $this->resolveModelClass($model);
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
        $page    = isset($requestParams['page']) ? (int) $requestParams['page'] : null;
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
            $columnsParam  = $requestParams['columns'] ?? null;
            $requestedCols = is_string($columnsParam) && $columnsParam !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $columnsParam)), fn($c) => $c !== ''))
                : [];
            if (isset($requestParams['with']) && is_string($requestParams['with'])) {
                try {
                    $modelInstance = new $modelClass;
                    foreach (explode(',', $requestParams['with']) as $spec) {
                        [$path] = array_pad(explode(':', $spec, 2), 2, null);
                        $path   = is_string($path) ? trim($path) : '';
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
            $lastPage         = (int) max(1, (int) ceil(($total > 0 ? $total : 1) / max(1, (int) $effectivePerPage)));

            // Hide auto-included foreign keys from serialized output
            $items = array_map(function ($m) use ($autoHidden) {
                if (! empty($autoHidden) && method_exists($m, 'makeHidden')) {
                    $m->makeHidden($autoHidden);
                }

                return $m;
            }, $records->items());

            return response()->json([
                'data'       => $items,
                'pagination' => [
                    'current_page' => $records->currentPage(),
                    'first_page'   => 1,
                    'last_page'    => $lastPage,
                    'per_page'     => $effectivePerPage,
                    'total'        => $total,
                ],
            ]);
        }

        // Non-paginated response; hide auto-included foreign keys if any
        $autoHidden    = [];
        $columnsParam  = $requestParams['columns'] ?? null;
        $requestedCols = is_string($columnsParam) && $columnsParam !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $columnsParam)), fn($c) => $c !== ''))
            : [];
        if (isset($requestParams['with']) && is_string($requestParams['with'])) {
            try {
                $modelInstance = new $modelClass;
                foreach (explode(',', $requestParams['with']) as $spec) {
                    [$path] = array_pad(explode(':', $spec, 2), 2, null);
                    $path   = is_string($path) ? trim($path) : '';
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

    public function show(Request $request, string $model, int $id)
    {
        $requestParams = array_change_key_case($request->all(), CASE_LOWER);
        $modelClass    = $this->resolveModelClass($model);
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

            $record = $query->findOrFail($id);

            return response()->json($record);
        } catch (ModelNotFoundException $e) {
            $catalog = $this->messageCatalog(class_basename($modelClass));

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['record_not_found']),
            ], Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
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
                    'data'    => $record,
                ], Response::HTTP_CREATED);
            });

        } catch (ValidationException $e) {
            // Validation errors: return model-defined messages
            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['validation_failed']),
                'errors'  => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (QueryException $e) {
            // DB constraint / SQL errors
            Log::error('Database error while creating record', [
                'model'    => $modelClass,
                'error'    => $e->getMessage(),
                'sql'      => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['unable_save']),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (\Throwable $e) {
            // Any other unexpected error
            Log::critical('Unexpected error while creating record', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['unexpected']),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, string $model, int $id)
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

        $record = $modelClass::findOrFail($id);
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
                    'data'    => $record,
                ], Response::HTTP_OK);
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Validation errors: return model-defined messages
            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['validation_failed']),
                'errors'  => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (QueryException $e) {
            // DB constraint / SQL errors
            Log::error('Database error while updating record', [
                'model'    => $modelClass,
                'error'    => $e->getMessage(),
                'sql'      => method_exists($e, 'getSql') ? $e->getSql() : null,
                'bindings' => method_exists($e, 'getBindings') ? $e->getBindings() : null,
            ]);

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['unable_update']),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            // Any other unexpected error
            Log::critical('Unexpected error while updating record', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['unexpected']),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy(Request $request, string $model, int $id)
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
            $record = $modelClass::findOrFail($id);
            $record->delete();

            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['deleted']),
            ], Response::HTTP_OK);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => $this->localizedMessage($request, $catalog['record_not_found']),
            ], Response::HTTP_NOT_FOUND);
        }
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
            $packageFqcn = 'Ogp\\UiApi\\Models\\' . $name;
            $appFqcn     = 'App\\Models\\' . $name;

            // Prefer existing files to avoid noisy autoload warnings
            $packagePath = base_path('vendor/ogp/uiapi/src/Models/' . $name . '.php');
            $appPath     = base_path('app/Models/' . $name . '.php');

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
