<?php

namespace Ogp\UiApi\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Str;

class GenericApiController extends BaseController
{
    public function index(Request $request, string $model)
    {
        $requestParams = array_change_key_case($request->all(), CASE_LOWER);
        $modelClass = $this->resolveModelClass($model);
        if (! $modelClass) {
            return response()->json(['error' => 'Model not found'], 400);
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

            return response()->json([
                'data' => $items,
                'pagination' => [
                    'current_page' => $records->currentPage(),
                    'first_page' => 1,
                    'last_page' => $lastPage,
                    'per_page' => $effectivePerPage,
                    'total' => $total,
                ],
            ]);
        }

        // Non-paginated response; hide auto-included foreign keys if any
        $autoHidden = [];
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
        $modelClass = $this->resolveModelClass($model);
        if (! $modelClass) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        try {
            $query = $modelClass::query()
                ->selectColumns($requestParams['columns'] ?? null)
                ->withRelations($requestParams['with'] ?? null)
                ->withPivotRelations($requestParams['pivot'] ?? null);

            $record = $query->findOrFail($id);

            return response()->json($record);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Record not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request, string $model)
    {
        $modelClass = $this->resolveModelClass($model);

        if (! $modelClass) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        // Centralized validation
        $validated = $modelClass::validate($request->all());

        // Creation using validated data only
        $record = $modelClass::createFromArray(
            $validated,
            $request->user() ?: null
        );

        // Optional pivot handling
        $modelClass::handlePivots($request, $record);

        // Handle file uploads if applicable
        $modelClass::handleFiles($request, $record, $request->user() ?: null);

        // Optional file handling (host app concern)
        if (class_exists('App\\Services\\FileUploadService')) {
            $normalized = class_basename($modelClass);

            app(\App\Services\FileUploadService::class)->handle(
                $request,
                $record,
                $normalized,
                $request->user() ?? null
            );
        }

        return response()->json($record, 201);
    }

    public function update(Request $request, string $model, int $id)
    {
        $modelClass = $this->resolveModelClass($model);

        if (! $modelClass) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        $record = $modelClass::findOrFail($id);

        // Centralized, model-driven validation
        $validated = $modelClass::validate(
            $request->all(),
            $record->id
        );

        // Update using validated data only
        $record = $record->updateFromArray(
            $validated,
            $request->user()
        );

        // Handle file uploads on update if applicable
        $modelClass::handleFiles($request, $record, $request->user() ?: null);

        return response()->json($record, 200);
    }

    public function destroy(string $model, int $id)
    {
        $modelClass = $this->resolveModelClass($model);
        if (! $modelClass) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        try {
            $record = $modelClass::findOrFail($id);
            $record->delete();

            return response()->noContent();
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Record not found'], 404);
        }
    }

    protected function resolveModelClass(string $model): ?string
    {
        $names = array_values(array_unique([
            ucfirst(strtolower($model)),
            Str::studly($model),
            Str::studly(str_replace(['-', ' ', '.'], '_', $model)),
        ]));

        $namespaces = ['Ogp\\UiApi\\Models\\', 'App\\Models\\'];
        foreach ($names as $normalized) {
            foreach ($namespaces as $ns) {
                $fqcn = $ns.$normalized;
                if (class_exists($fqcn)) {
                    return $fqcn;
                }
            }
        }

        return null;
    }
}
