# OGP / UiApi

UI API package for Laravel 12 (PHP 8.2+). It provides:
- Generic CRUD JSON API for your Eloquent models
- Component configuration service to generate UI payloads from simple JSON view configs

Package namespace: `Ogp\UiApi`. Auto-discovered provider: `Ogp\UiApi\UiApiServiceProvider`.

## Installation

### 1) Require the package
```bash
composer require ogp/uiapi
```

### 2) (Optional) Publish config and view configs

- View configs (installs sample JSON files to `app/Services/viewConfigs`):
```bash
php artisan vendor:publish --tag=uiapi-view-configs
```

### 3) Clear caches
```bash
php artisan optimize:clear
```


## Routes
By default routes are registered under `/{prefix}` where `prefix = config('uiapi.route_prefix', 'api')`.

- Component Config Service (CCS)
  - GET `/{prefix}/ccs/{model}?view={viewKey}` → returns component settings for all components in the view
    - Required: `view` parameter (e.g., `view=listView`)
    - Optional: `lang` — defaults to `dv` if omitted (supports `en`, `dv` where applicable)
  - GET `/{prefix}/ccs/{model}?component={componentKey}` → returns full payload for a specific component
    - Required: `component` parameter (e.g., `component=table`)
    - Optional: `lang` — defaults to `dv` if omitted
    - Supports cross-model references: `component=person/form` to fetch form component from person model

- Generic API CRUD (GAPI)
  - GET `/{prefix}/gapi/{model}` → list/index
  - GET `/{prefix}/gapi/{model}/{id}` → show
  - POST `/{prefix}/gapi/{model}` → create
  - PUT `/{prefix}/gapi/{model}/{id}` → update
  - DELETE `/{prefix}/gapi/{model}/{id}` → delete

Verify:
```bash
php artisan route:list --path=api
```

### Model Name Normalization
Both CCS and GAPI accept flexible model names in the URL path. The resolver normalizes common multi‑word inputs to StudlyCase and checks these namespaces in order: `Ogp\UiApi\Models\`, then `App\Models\`.

- Accepted examples all resolve to `EntryType`:
  - `entry_type`, `entry-type`, `entry type`, `entryType`
- Ambiguous forms like `entrytype` (no delimiters) cannot be reliably split; prefer one of the delimited forms above when your model is multi‑word.


## Query Parameters (GAPI)
Supported by `GenericApiController` and `BaseModel` scopes:
- `columns`: comma list of selected columns; relation tokens supported (e.g., `country.name_eng`).
- `with`: eager-load relations, supports `relation` or `relation:col1,col2` to constrain selected columns.
- `pivot`: eager-load belongsToMany relations including pivot data.
- `search`: OR groups separated by `|`, each group has comma pairs `column:value`.
- `searchAll`: global search across the model’s searchable fields.
- `withoutRow`: exclude rows matching criteria (`column:value`, supports OR groups).
- `filter`: advanced filters
  - AND across pairs: `a:x,b:y`; OR within a field: `a:x|y`
  - Relation fields: `relation.field:value`
  - Special values: `null`, `!null`, `true`, `false`, `''` (empty)
- `sort`: comma list; `-field` for DESC (e.g., `-created_at,name`).
- `pagination`: `off` to disable pagination.
- `per_page`: items per page.
- `page`: page number.

Responses:
- Paginated: `{ data: [...], pagination: { current_page, last_page, per_page, total } }`
- Non‑paginated: `{ data: [...] }`

## View Configs (UI Component Settings)
View configs are JSON files per model (lowercase filename, no hyphens, no underscores or spaces) and support multiple views and reusable components. Default path: `app/Services/viewConfigs`.

### Architecture: Views and Components

**Views** (keys containing "View") reference reusable components and define view-specific settings:
- `components`: object mapping aliases to component references (e.g., `{"table": "cform/table"}`)
- `columns`: fields to include; supports relation tokens like `country.name_eng`
- `columnCustomizations`: per‑column overrides for labels, visibility, display types, etc.
- `filters`: whitelisted fields for filter UI
- `per_page`: default page size
- `lang`: allowed UI languages (e.g., `["en","dv"]`)

**Components** (root-level keys without "View") contain component-specific customizations and inherit `lang` from views.

### Component References
- Local: `"table"` → references `table` component in same file
- Cross-model: `"person/form"` → references `form` from `person.json`

### Request Types
- View: `GET /api/ccs/{model}?view=listView` → returns `componentSettings` for all components
- Component: `GET /api/ccs/{model}?component=table` → returns full payload for single component

Example (`app/Services/viewConfigs/cform.json`):
```json
{
  "listView": {
    "components": {
      "table": "cform/table",
      "toolbar": "cform/toolbar",
      "filterSection": "cform/filterSection"
    },
    "columns": ["id", "ref_num", "status", "created_at"],
    "columnCustomizations": {
      "ref_num": {
        "width": "200px",
        "sortable": true
      }
    },
    "filters": ["status", "ref_num"],
    "per_page": 15,
    "lang": ["en", "dv"]
  },
  "table": {
    "columns": ["id", "ref_num", "summary", "status"],
    "functions": {
      "customeColumnData": {
        "file": "misc.js",
        "function": "customeColumnData"
      }
    }
  },
  "toolbar": {
    "title": { "en": "Forms", "dv": "ސަރަހައްދު" },
    "buttons": ["search", "clear"]
  }
}
```

Component templates in `ComponentConfigs/*.json` define base behavior (headers, pagination, datalink, etc.). When requesting a component, the service merges the template with view config customizations, processes "on/off" values, applies overrides, and returns the localized payload.

### NoModel View Configs
In some views, you can build UI payloads without an Eloquent model by setting `noModel: true` on the view config block. In this mode, the returned payload is derived entirely from the JSON config — specifically from `columns`, `columnsSchema`, optional `columnCustomizations`, `filters`, and `per_page`.

- What changes in noModel:
  - No model resolution occurs; `apiSchema()` is not required.
  - `columnsSchema` is mandatory and drives headers, filter metadata, and language support.
  - Relation fields can be declared directly via dot tokens as keys in `columnsSchema` (e.g., `"country.name_eng"`). Their schema entries (e.g., `hidden`, `sortable`, `type`, `displayType`, `lang`) will be used for headers and filtering.
  - Language handling honors `lang` on each column in `columnsSchema`; only columns supporting the requested `lang` are included.

- Component assembly remains the same: component templates in `src/Services/ComponentConfigs/*.json` are still used and merged with your view config inputs to produce `componentSettings`.

#### Datalink in noModel mode
The `datalink` section of a component template controls how the data-fetch URL is included in the payload.

- When a template section sets `"datalink": "on"`, the CCS generates the URL automatically based on:
  - Selected `columns` (after language filtering), including relation dot tokens.
  - Relation dot tokens become `with` segments: for example, `country.name_eng` yields `with=country:name_eng`.
  - Pagination is appended using `per_page` from the view config (or request override).
  - The base path uses the package route prefix: `/{prefix}/gapi/{Model}` where `{prefix} = config('uiapi.route_prefix', 'api')`.

- When a template section sets `"datalink": "off"`, the CCS omits the `datalink` key from the section payload.

- When a template section sets `"datalink": <object|string>`, the CCS uses that value directly, allowing custom URLs or structures without auto-generation.

Example minimal noModel view block:
```json
{
  "listView2": {
    "noModel": true,
    "components": { "table": {}, "filterSection": { "buttons": ["submit", "clear"] } },
    "columns": ["id", "country.name_eng", "first_name_eng"],
    "columnsSchema": {
      "id": { "hidden": true, "key": "id", "label": { "en": "ID" }, "type": "number", "sortable": true, "lang": ["en", "dv"] },
      "country.name_eng": { "lang": ["en"], "hidden": true },
      "first_name_eng": { "key": "first_name_eng", "label": { "en": "First Name" }, "type": "string", "displayType": "text", "sortable": true, "lang": ["en"] }
    },
    "per_page": 25,
    "lang": ["en", "dv"]
  }
}
```

Notes:
- If `lang` requested is not in the view’s `lang` array, CCS returns a message and an empty `data` array.
- Dot-key `columnsSchema` entries are fully honored (hidden, sortable, type, displayType, inlineEditable, displayProps, lang).
- `columnCustomizations` still apply and override schema-derived defaults in the final headers.

Component configuration templates live in the package at `src/Services/ComponentConfigs/*.json` and are referenced by keys in your view config `components` block (e.g., `table`, `filterSection`). `ComponentConfigService` builds per‑component payloads based on these templates + your model’s `apiSchema`.

### Fetch Component Settings
```bash
# Fetch view with all component settings
curl "http://localhost/api/ccs/cform?view=listView&lang=en"

# Fetch specific component payload
curl "http://localhost/api/ccs/cform?component=table&lang=en"

# Fetch cross-model component
curl "http://localhost/api/ccs/cform?component=person/form&lang=dv"
```
If `lang` is omitted, it defaults to `dv`.

## Creating a Model with apiSchema
Your models should expose an `apiSchema()` method that returns a schema with a `columns` map. The package resolves models by checking `Ogp\UiApi\Models\{Model}` first, then `App\Models\{Model}`.

Fastest path: extend the package base model so all query scopes and helpers are available.

Minimal example (`App\Models\Book`):
```php
<?php

namespace App\Models;

use Ogp\UiApi\Models\BaseModel;

class Book extends BaseModel // important to extent BaseModel
{


    public function apiSchema(): array
    {
        return [
            'columns' => [
                'id' => [
                    'hidden' => true,
                    'key' => 'id',
                    'label' => ['en' => 'ID'],
                    'type' => 'number',
                    'displayType' => 'text',
                    'sortable' => true,
                ],
                'title' => [
                    'hidden' => false,
                    'key' => 'title',
                    'label' => ['en' => 'Title'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'sortable' => true,
                    'filterable' => [
                        'type' => 'search',
                        'value' => 'title',
                    ],
                ],
                'author_id' => [
                    'hidden' => true,
                    'key' => 'author_id',
                    'label' => ['en' => 'Author'],
                    'type' => 'number',
                    'displayType' => 'text',
                    'filterable' => [
                        'type' => 'select',
                        'mode' => 'relation',
                        'relationship' => 'author',
                        'itemTitle' => ['en' => 'name_eng'],
                        'itemValue' => 'id',
                        'value' => 'author_id',
                    ],
                ],
            ],
        ];
    }

    public function author()
    {
        return $this->belongsTo(Author::class);
    }
}
```

Notes:
- `label` can be a string or a per‑language map. `lang` on a column can restrict language availability for UI.
- `displayType` helps the UI render (e.g., `text`, `chip`, `date`, `checkbox`).
- `filterable` defines filter UI. `type` can be `search` or `select`; `mode` can be `self` or `relation`.
- Relation tokens like `author.name_eng` are supported in `columns` and the service.

## Model Appends & Computed Attributes

Computed attributes should be defined the Laravel way using Eloquent `Attribute` accessors, and included in JSON responses via the model’s `$appends` property.

- Define an accessor whose method name is the camelCase version of the attribute; it will serialize as snake_case (e.g., `fullName()` → `full_name`).
- Add the snake_case attribute key to `$appends` so it’s present in API responses.
- Declare dependencies so computed attributes are correct even when their base columns are not requested via `columns`:
  - Add `protected array $computedAttributeDependencies = [ 'attr' => ['dep1','dep2',...] ];` to the model.
  - When a computed attribute is requested (or appended), the query auto‑selects its dependencies. Any auto‑selected dependency columns are hidden from the JSON unless explicitly requested in `columns`.

Example:
```php
use Illuminate\Database\Eloquent\Casts\Attribute;

class Person extends BaseModel
{
  protected $appends = ['full_name'];

  protected array $computedAttributeDependencies = [
    'full_name' => ['first_name_eng', 'middle_name_eng', 'last_name_eng'],
  ];

  protected function fullName(): Attribute
  {
    return Attribute::make(
      get: fn () => collect([
        $this->first_name_eng,
        $this->middle_name_eng,
        $this->last_name_eng,
      ])->filter()->join(' ')
    );
  }
}
```

With this setup:
- `columns=full_name` returns only `full_name` (dependencies are fetched but hidden).
- If `full_name` is in `$appends`, responses include `full_name` even when you request other columns; dependencies are fetched automatically.

## Validation Rules

Models can expose validation rules in a split, ergonomic form. The package’s validator prefers these methods when present and falls back to a legacy `rules()` if needed.

- `baseRules(): array` — shared constraints that apply to both create and update.
- `rulesForCreate(): array` — create‑specific requirements (e.g., required fields, unique constraints).
- `rulesForUpdate(?int $id = null): array` — update‑specific rules; receive the current record id to handle uniqueness (e.g., `Rule::unique(...)->ignore($id)`).
- Optional: `validationMessages(): array` — custom error messages.

Example:
```php
use Illuminate\Validation\Rule;

class Person extends BaseModel
{
  public static function baseRules(): array
  {
    return [
      'first_name_eng' => ['sometimes', 'string', 'max:255'],
      'last_name_eng'  => ['sometimes', 'string', 'max:255'],
      'gender'         => ['nullable', 'in:M,F'],
      'country_id'     => ['nullable', 'integer', 'exists:countries,id'],
    ];
  }

  public static function rulesForCreate(): array
  {
    $rules = static::baseRules();
    $rules['first_name_eng'] = ['required', 'string', 'max:255'];
    $rules['last_name_eng']  = ['required', 'string', 'max:255'];
    $rules['id']             = ['sometimes', 'integer', 'unique:people,id'];
    return $rules;
  }

  public static function rulesForUpdate(?int $id = null): array
  {
    $rules = static::baseRules();
    $rules['id'] = $id !== null
      ? ['required', 'integer', Rule::unique('people', 'id')->ignore($id)]
      : ['required', 'integer'];
    return $rules;
  }
}
```

How it’s used:
- The Generic API calls the model validator for you: `BaseModel::validate($request, $id)`.
- On POST (create), `rulesForCreate()` is applied; on PUT (update), `rulesForUpdate($id)` is applied.

If you prefer a single method, you can still implement `rules(?int $id = null): array` — the validator will fall back to it when the split methods are not present.

## Example Data Requests
- List with relation fields, sort, and pagination:
```bash
curl "http://localhost/api/gapi/book?columns=id,title&with=author:name_eng&id&sort=-created_at&per_page=10"
```
- Fetch a single record with a relation:
```bash
curl "http://localhost/api/gapi/book/42?columns=id,title&with=author"
```
- Create:
```bash
curl -X POST "http://localhost/api/gapi/book" \
  -H "Content-Type: application/json" \
  -d '{"title":"My Book","author_id":1}'
```
- Update:
```bash
curl -X PUT "http://localhost/api/gapi/book/42" \
  -H "Content-Type: application/json" \
  -d '{"title":"Updated"}'
```
- Delete:
```bash
curl -X DELETE "http://localhost/api/gapi/book/42"
```
