<?php

namespace Ogp\UiApi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

abstract class BaseModel extends Model
{
    protected array $searchable = [];

    protected array $sortable = [];

    protected array $withable = [];

    protected array $pivotable = [];

    public static function paginateFromRequest(Builder $query, ?string $pagination, ?int $perPage = null, ?int $page = null): Paginator|Collection
    {
        if ($pagination === 'off') {
            return $query->get();
        }

        return $query->simplePaginate($perPage ?? 15, ['*'], 'page', $page);
    }

    public static function createFromRequest(Request $request, ?\App\Models\User $user = null)
    {
        $data = static::prepareData($request, $user);

        $record = static::create($data);

        return $record;
    }

    public static function createFromArray(array $data, ?\App\Models\User $user = null)
    {
        $sanitized = static::sanitizeArrayData($data, $user, true);

        $record = static::create($sanitized);

        return $record;
    }

    protected static function prepareData(Request $request, ?\App\Models\User $user = null): array
    {
        $data = $request->except(['password', 'pivot']);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        if (method_exists(static::class, 'fileFields')) {
            $data = $request->except(static::fileFields());
        }

        return $data;
    }

    protected static function sanitizeArrayData(array $data, ?\App\Models\User $user = null, bool $isCreate = true): array
    {
        // Remove fields not meant for direct persistence
        unset($data['pivot']);

        if (! empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }

        if ($isCreate && $user) {
            $data['created_by'] = $user->username ?? null;
        }

        if (! $isCreate && $user) {
            $data['updated_by'] = $user->username ?? null;
        }

        return $data;
    }

    public function updateFromRequest(Request $request, $user = null): self
    {
        $data = static::sanitizeRequestData($request, $user, false);

        $this->update($data);

        return $this->fresh();
    }

    public function updateFromArray(array $data, $user = null): static
    {
        $sanitized = static::sanitizeArrayData($data, $user, false);
        $this->fill($sanitized);
        $this->save();

        return $this->fresh();
    }

    protected static function sanitizeRequestData(
        Request $request,
        $user,
        bool $isCreate
    ): array {
        $data = $request->except(['password', 'pivot']);

        if ($isCreate && $user) {
            $data['created_by'] = $user->username ?? null;
        }

        if (! $isCreate && $user) {
            $data['updated_by'] = $user->username ?? null;
        }

        if ($request->filled('password')) {
            $data['password'] = bcrypt($request->password);
        }

        return $data;
    }

    public static function validationMessages(): array
    {
        return [];
    }

    protected static function handlePivots(Request $request, Model $record): void
    {
        if (! $request->has('pivot')) {
            return;
        }

        foreach ($request->input('pivot') as $relation => $items) {
            if (! method_exists($record, $relation)) {
                continue;
            }

            foreach ($items as $item) {
                $record->$relation()->attach(
                    $item['id'],
                    $item['pivot'] ?? []
                );
            }
        }
    }

    public static function handleFiles(Request $request, Model $record, ?\App\Models\User $user = null): void
    {
        if (! method_exists(static::class, 'fileFields')) {
            return;
        }

        app(\App\Services\FileUploadService::class)->handle(
            $request,
            $record,
            strtolower(class_basename(static::class)),
            $user
        );
    }

    public static function validate(Request|array $data, ?int $id = null): array
    {
        $lang = 'dv';

        if ($data instanceof Request) {
            $data->headers->set('Accept', 'application/json');
            $payload = $data->all();

            $candidate = strtolower((string) $data->query('lang', 'dv'));
            $lang = in_array($candidate, ['dv', 'en'], true) ? $candidate : 'dv';
        } else {
            $payload = $data;
        }

        $rules = [];
        // Prefer new-style split rules when available
        try {
            if ($id === null && method_exists(static::class, 'rulesForCreate')) {
                try {
                    $rules = static::rulesForCreate();
                } catch (\Throwable $e) {
                    $rules = [];
                }
            } elseif ($id !== null && method_exists(static::class, 'rulesForUpdate')) {
                try {
                    $rules = static::rulesForUpdate($id);
                } catch (\ArgumentCountError $e) {
                    try {
                        $rules = static::rulesForUpdate();
                    } catch (\Throwable $e2) {
                        $rules = [];
                    }
                } catch (\Throwable $e) {
                    $rules = [];
                }
            }
            if (empty($rules) && method_exists(static::class, 'baseRules')) {
                try {
                    $rules = static::baseRules();
                } catch (\Throwable $e) {
                    $rules = [];
                }
            }
        } catch (\Throwable $e) {
            // fall through to legacy rules() resolution
        }

        if (empty($rules) && method_exists(static::class, 'rules')) {
            try {
                // Try static with id
                $rules = static::rules($id);
            } catch (\ArgumentCountError $e) {
                try {
                    // Try static without id
                    $rules = static::rules();
                } catch (\Throwable $e2) {
                    // Fall back to instance methods
                    try {
                        $inst = new static;
                        $rules = $inst->rules($id);
                    } catch (\ArgumentCountError $e3) {
                        try {
                            $inst = new static;
                            $rules = $inst->rules();
                        } catch (\Throwable $e4) {
                            $rules = [];
                        }
                    } catch (\Throwable $e5) {
                        $rules = [];
                    }
                }
            } catch (\Throwable $e) {
                // Fall back to instance methods if static call failed for other reasons
                try {
                    $inst = new static;
                    $rules = $inst->rules($id);
                } catch (\ArgumentCountError $e6) {
                    try {
                        $inst = new static;
                        $rules = $inst->rules();
                    } catch (\Throwable $e7) {
                        $rules = [];
                    }
                } catch (\Throwable $e8) {
                    $rules = [];
                }
            }
        }

        if (empty($rules)) {
            return $payload;
        }

        $messages = static::validationMessages();
        $messages = static::localizeValidationMessages($messages, $lang);

        return Validator::make($payload, $rules, $messages)->validate();
    }

    /**
     * @param  array<string, mixed>  $messages
     * @return array<string, string>
     */
    protected static function localizeValidationMessages(array $messages, string $lang): array
    {
        $localized = [];

        foreach ($messages as $key => $value) {
            if (is_string($value)) {
                $localized[$key] = $value;

                continue;
            }

            if (is_array($value)) {
                $dv = $value['dv'] ?? null;
                $en = $value['en'] ?? null;

                $selected = $lang === 'en' ? ($en ?? $dv) : ($dv ?? $en);

                if (is_string($selected)) {
                    $localized[$key] = $selected;
                }
            }
        }

        return $localized;
    }

    public function scopeSelectColumns(Builder $query, ?string $columns): Builder
    {
        // When columns param is missing, leave query as-is (select *)
        if (! $columns) {
            return $query;
        }

        $requested = array_values(array_filter(array_map('trim', explode(',', $columns)), fn ($c) => $c !== ''));

        // Valid DB columns among requested
        $validDb = array_values(array_filter(
            $requested,
            fn ($col) => Schema::hasColumn($this->getTable(), $col)
        ));

        // Auto-include dependencies for requested computed attributes
        $depsMap = $this->getComputedAttributeDependencies();
        $depsToAdd = [];
        foreach ($depsMap as $attr => $deps) {
            if (in_array($attr, $requested, true)) {
                foreach ((array) $deps as $depCol) {
                    if (Schema::hasColumn($this->getTable(), $depCol)) {
                        $depsToAdd[] = $depCol;
                    }
                }
            }
        }

        // Ensure the model primary key is always selected
        $primaryKey = $this->getKeyName();
        $finalSelect = array_values(array_unique(array_merge([$primaryKey], $validDb, $depsToAdd)));

        // If nothing is valid, still select primary key (limit payload)
        if (empty($finalSelect)) {
            $finalSelect = [$primaryKey];
        }

        $query->select($finalSelect);

        return $query;
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (! $search) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            foreach (explode('|', $search) as $orGroup) {
                $q->orWhere(function (Builder $orQ) use ($orGroup) {
                    foreach (explode(',', $orGroup) as $criterion) {
                        [$column, $value] = explode(':', $criterion, 2);

                        if ($this->isSearchable($column)) {
                            $orQ->where($column, 'LIKE', "%{$value}%");
                        }
                    }
                });
            }
        });
    }

    public function scopeSearchAll(Builder $query, ?string $term): Builder
    {
        if (! $term || empty($this->searchable)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            foreach ($this->searchable as $column) {
                $q->orWhere($column, 'LIKE', "%{$term}%");
            }
        });
    }

    public function scopeWithoutRow(Builder $query, ?string $criteria): Builder
    {
        if (! $criteria) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($criteria) {
            foreach (explode('|', $criteria) as $orGroup) {
                $q->orWhere(function (Builder $orQ) use ($orGroup) {
                    foreach (explode(',', $orGroup) as $criterion) {
                        [$column, $value] = explode(':', $criterion, 2);

                        if ($this->isSearchable($column)) {
                            $orQ->where($column, '!=', $value);
                        }
                    }
                });
            }
        });
    }

    public function scopeSort(Builder $query, ?string $sort): Builder
    {
        if (! $sort) {
            return $query;
        }

        foreach (explode(',', $sort) as $field) {
            $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
            $column = ltrim($field, '-');

            if ($this->isSortable($column)) {
                $query->orderBy($column, $direction);
            }
        }

        return $query;
    }

    public function scopeFilter(Builder $query, ?string $filter): Builder
    {
        if (! $filter || trim($filter) === '') {
            return $query;
        }

        $pairs = array_filter(array_map('trim', explode(',', $filter)), fn ($p) => $p !== '');
        foreach ($pairs as $pair) {
            [$field, $raw] = array_pad(explode(':', $pair, 2), 2, null);
            $field = is_string($field) ? trim($field) : '';
            if ($field === '' || $raw === null) {
                continue;
            }

            $tokens = array_map('trim', explode('|', (string) $raw));
            $values = [];
            $hasEmpty = false;
            $hasNull = false;
            $hasNotNull = false;

            foreach ($tokens as $tok) {
                $lower = strtolower($tok);
                if ($tok === '') {
                    $hasEmpty = true;
                } elseif ($lower === 'null') {
                    $hasNull = true;
                } elseif ($lower === '!null') {
                    $hasNotNull = true;
                } elseif ($lower === 'true') {
                    $values[] = true;
                } elseif ($lower === 'false') {
                    $values[] = false;
                } else {
                    $values[] = $tok;
                }
            }

            $apply = function (Builder $b, string $col) use ($values, $hasEmpty, $hasNull, $hasNotNull): void {
                $b->where(function (Builder $q) use ($col, $values, $hasEmpty, $hasNull, $hasNotNull): void {
                    $first = true;
                    if (! empty($values)) {
                        $q->whereIn($col, $values);
                        $first = false;
                    }
                    if ($hasEmpty) {
                        if ($first) {
                            $q->where($col, '');
                        } else {
                            $q->orWhere($col, '');
                        }
                        $first = false;
                    }
                    if ($hasNull) {
                        if ($first) {
                            $q->whereNull($col);
                        } else {
                            $q->orWhereNull($col);
                        }
                        $first = false;
                    }
                    if ($hasNotNull) {
                        if ($first) {
                            $q->whereNotNull($col);
                        } else {
                            $q->orWhereNotNull($col);
                        }
                    }
                });
            };

            if (Str::contains($field, '.')) {
                [$relation, $relCol] = array_pad(explode('.', $field, 2), 2, null);
                if ($relation && $relCol && method_exists($this, $relation)) {
                    $query->whereHas($relation, function (Builder $relQ) use ($apply, $relCol): void {
                        $apply($relQ, $relCol);
                    });
                }
            } else {
                $apply($query, $field);
            }
        }

        return $query;
    }

    public function scopeWithRelations(Builder $query, ?string $relations): Builder
    {
        if (! $relations) {
            return $query;
        }

        // Ensure base model primary key is always selected when eager loading relations
        $query->addSelect($this->getKeyName());

        foreach (explode(',', $relations) as $spec) {
            [$path, $columns] = array_pad(explode(':', $spec, 2), 2, null);
            $path = is_string($path) ? trim($path) : '';
            if ($path === '') {
                continue;
            }

            // Support nested relations via dot-paths (e.g., author.country)
            $topLevel = explode('.', $path)[0];
            if (! method_exists($this, $topLevel)) {
                continue;
            }

            // If top-level relation is BelongsTo, ensure parent foreign key is selected on base model
            try {
                $rel = $this->{$topLevel}();
                if ($rel instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                    $fk = $rel->getForeignKeyName();
                    $query->addSelect($fk);
                }
            } catch (\Throwable $e) {
                // Ignore relation introspection failures
            }

            if ($columns) {
                $cols = array_values(array_filter(array_map('trim', explode(',', $columns)), fn ($c) => $c !== ''));

                // Apply selection to the target related model (nested path supported)
                $query->with([
                    $path => function ($q) use ($cols) {
                        $relatedKey = $q->getModel()->getKeyName();
                        if (! in_array($relatedKey, $cols, true)) {
                            array_unshift($cols, $relatedKey);
                        }
                        $q->select($cols);
                    },
                ]);
            } else {
                $query->with($path);
            }
        }

        return $query;
    }

    public function scopeWithPivotRelations(Builder $query, ?string $relations): Builder
    {
        if (! $relations) {
            return $query;
        }

        $requested = array_map('trim', explode(',', $relations));

        foreach ($requested as $relation) {
            if (! method_exists($this, $relation)) {
                continue;
            }

            $relationInstance = $this->$relation();

            if (! $relationInstance instanceof BelongsToMany) {
                continue;
            }

            if (! $this->isPivotableRelation($relation)) {
                continue;
            }

            $query->with([
                $relation => fn ($q) => $q->withPivot('*'),
            ]);
        }

        return $query;
    }

    public static function parseWithRelations(Model $model, ?string $relations): array
    {
        if ($relations === null || trim($relations) === '') {
            return [];
        }

        $result = [];

        foreach (explode(',', $relations) as $relation) {
            [$name] = array_pad(explode(':', $relation, 2), 2, null);
            $name = is_string($name) ? trim($name) : '';

            if ($name === '') {
                continue;
            }

            if (method_exists($model, $name)) {
                try {
                    $rel = $model->{$name}();
                } catch (\Throwable $e) {
                    $rel = null;
                }

                if ($rel instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                    $result[] = $name;
                }
            }
        }

        return array_values(array_unique($result));
    }

    public function isSearchable(string $column): bool
    {
        return true;
        // return in_array($column, $this->searchable, true);
    }

    public function isSortable(string $column): bool
    {
        return true;
        // return in_array($column, $this->sortable, true);
    }

    protected function isWithableRelation(string $relation): bool
    {
        return true;
        // return empty($this->withable) || in_array($relation, $this->withable, true);
    }

    protected function isPivotableRelation(string $relation): bool
    {
        return true;
        // return empty($this->pivotable) || in_array($relation, $this->pivotable, true);
    }

    /**
     * Return mapping of computed attributes to their dependent DB columns.
     * Example: ['full_name' => ['first_name_eng','middle_name_eng','last_name_eng']]
     */
    public function getComputedAttributeDependencies(): array
    {
        $deps = [];
        // Allow models to define a property for dependencies
        if (property_exists($this, 'computedAttributeDependencies') && is_array($this->computedAttributeDependencies)) {
            $deps = $this->computedAttributeDependencies;
        }

        return is_array($deps) ? $deps : [];
    }
}
