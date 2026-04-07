# UIAPI Ecosystem — Project Architecture & Reference

> A comprehensive guide to the four interconnected projects that form the schema-driven UI generation system built for PGO (Prosecutor General's Office, Maldives).

---

## Table of Contents

1. [High-Level Overview](#high-level-overview)
2. [Project: uiapi (Laravel Backend)](#project-uiapi-laravel-backend)
3. [Project: uiapi-package (Laravel Package)](#project-uiapi-package-laravel-package)
4. [Project: uiux-copy (Vue 3 Frontend / Component Library)](#project-uiux-copy-vue-3-frontend--component-library)
5. [Project: uiapi-vscode (VS Code Extension)](#project-uiapi-vscode-vs-code-extension)
6. [End-to-End Data Flow](#end-to-end-data-flow)
7. [Schema-Driven UI Generation](#schema-driven-ui-generation)
8. [Key Concepts & Terminology](#key-concepts--terminology)
9. [API Endpoints](#api-endpoints)
10. [View Config System](#view-config-system)
11. [Component Map](#component-map)
12. [Bilingual / RTL Support](#bilingual--rtl-support)
13. [noModel Architecture](#nomodel-architecture)

---

## High-Level Overview

The ecosystem consists of **four projects** working together to deliver a **schema-driven, dynamic UI system**. Instead of hardcoding UI for each data model, models self-describe their structure via `apiSchema()`, and view config JSON files declare how that schema should render. The frontend dynamically builds tables, forms, filters, and toolbars from the resulting configuration payloads.

```
┌─────────────────────────────────────────────────────────────────────┐
│                        DEVELOPER TOOLING                            │
│  ┌──────────────────┐                                               │
│  │  uiapi-vscode    │  VS Code extension: generates models &        │
│  │  (Extension)     │  view configs from SQL files or existing       │
│  └────────┬─────────┘  models. Calls PHP bridge into uiapi-package. │
│           │                                                         │
├───────────┼─────────────────────────────────────────────────────────┤
│           │             BACKEND (Laravel 12 / PHP 8.4)              │
│           ▼                                                         │
│  ┌──────────────────┐    uses       ┌──────────────────────┐        │
│  │  uiapi           │─────────────►│  uiapi-package        │        │
│  │  (Host App)      │  ogp/uiapi   │  (Reusable Package)   │        │
│  │                  │              │                        │        │
│  │  • Domain Models │              │  • Routes (ccs/, gapi/)│        │
│  │    with          │              │  • GenericApiController│        │
│  │    apiSchema()   │              │  • ComponentConfigSvc  │        │
│  │  • viewConfig    │              │  • BaseModel            │        │
│  │    JSON files    │              │  • Model Generator     │        │
│  │  • Migrations    │              │  • View Config Validator│       │
│  │  • Seeders       │              │                        │        │
│  │  (no active      │              │  (provides all API     │        │
│  │   routes/ctrls)  │              │   routes & logic)      │        │
│  └──────────────────┘              └──────────────────────┘         │
│           │                                                         │
│    JSON API: /api/ccs/{model}  &  /api/gapi/{model}                │
│    (routes registered by uiapi-package)                             │
│           │                                                         │
├───────────┼─────────────────────────────────────────────────────────┤
│           │             FRONTEND (Vue 3 / Vite / Tailwind)          │
│           ▼                                                         │
│  ┌──────────────────────────────────────────────────┐               │
│  │  uiux-copy (PGO UI Component Library)            │               │
│  │                                                  │               │
│  │  • ComponentRenderer → fetches /ccs/{model}      │               │
│  │  • ListView → renders DataTable + Toolbar + Form │               │
│  │  • 50+ reusable components (DataTable, Form,     │               │
│  │    Select, Chip, Modal, TipTap Editor, etc.)     │               │
│  │  • Plugin system (theme, i18n, snackbar, etc.)   │               │
│  │  • Bilingual (English + Dhivehi) + RTL support   │               │
│  └──────────────────────────────────────────────────┘               │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Project: uiapi (Laravel Backend)

**Path:** `/var/www/uiapi`
**Role:** The host Laravel 12 application — the deployed backend.
**Tech:** PHP 8.4, Laravel 12, PHPUnit 11

### Purpose

This is the **main Laravel application** that serves as the backend API for the PGO system. It defines the application's Eloquent models, database schema, and view configuration JSON files. It consumes `uiapi-package` (`ogp/uiapi`) as a local Composer dependency (symlinked from `../uiapi-package`).

> **Important:** Although `uiapi` contains its own copies of `GenericApiController.php` and `ComponentConfigService.php` in `app/`, these are **inactive backup copies**. All API routes, CRUD handling, and CCS logic are provided by the `uiapi-package`. The host app's `routes/api.php` has all routes commented out with the note: *"Duplicate routes disabled: provided by ogp/uiapi package"*. The package's `UiApiServiceProvider` registers the actual routes that reference `Ogp\UiApi\Http\Controllers\GenericApiController` and `Ogp\UiApi\Services\ComponentConfigService`.

### What uiapi Provides vs. What the Package Provides

| Concern | Provided By | Location |
|---------|-------------|----------|
| **API Routes** (`/api/ccs/*`, `/api/gapi/*`) | **uiapi-package** | `uiapi-package/routes/api.php` |
| **GenericApiController** (CRUD) | **uiapi-package** | `Ogp\UiApi\Http\Controllers\GenericApiController` |
| **ComponentConfigService** (CCS) | **uiapi-package** | `Ogp\UiApi\Services\ComponentConfigService` |
| **BaseModel** (abstract base) | **uiapi-package** | `Ogp\UiApi\Models\BaseModel` |
| **Domain Models** (Entry, CForm, etc.) | **uiapi** (host app) | `App\Models\*` |
| **View Config JSONs** | **uiapi** (host app) | `app/Services/viewConfigs/*.json` |
| **Migrations & Seeders** | **uiapi** (host app) | `database/migrations/`, `database/seeders/` |
| **File Upload Service** | **uiapi** (host app) | `app/Services/FileUploadService.php` |
| **Exception Handling** | **uiapi** (host app) | `app/Exceptions/Handler.php` |
| **Tests** | **uiapi** (host app) | `tests/` |

### Model Resolution Priority

When the package resolves a model name (e.g., `"CForm"`), it checks in this order:
1. **`App\Models\CForm`** (host app) — **preferred**
2. **`Ogp\UiApi\Models\CForm`** (package fallback)

This means the host app's models always take priority. The package only provides example/fallback models (like `Person`).

### Key Directories

| Directory | Purpose |
|-----------|---------|
| `app/Models/` | Domain models (Entry, EntryType, Country, Letter, LegalAid, CForm, Invoice, _Person, etc.) — each implements `apiSchema()` |
| `app/Services/viewConfigs/` | View config JSON files that define how each model's UI should render (e.g., `cform.json`, `person.json`, `entry.json`, `letter.json`, `invoice.json`, `legalaid.json`, `noModel-cform.json`) |
| `app/Services/FileUploadService.php` | Handles file uploads per model, stores to `uploads/{model}/{field}/` |
| `app/Services/ComponentConfigService.php` | **Inactive copy** — the package's version is used at runtime |
| `app/Http/Controllers/GenericApiController.php` | **Inactive copy** — the package's version is used at runtime |
| `app/Exceptions/Handler.php` | Custom handler returning 422 JSON for validation errors |
| `routes/api.php` | **All routes commented out** — routes are provided by uiapi-package |
| `database/migrations/` | Database schema definitions |
| `tests/` | PHPUnit feature and unit tests |
| `docs/` | API guide & project architecture documentation |

### Models & apiSchema Pattern

Every model extends `BaseModel` and implements an `apiSchema()` method that self-documents its columns for UI generation:

```php
public static function apiSchema(): array
{
    return [
        'columns' => [
            'ref_num' => [
                'hidden' => false,
                'key' => 'ref_num',
                'label' => ['en' => 'Reference Number', 'dv' => 'ރެފަރެންސް ނަންބަރު'],
                'type' => 'string',
                'sortable' => true,
                'displayType' => 'text',
                'inputType' => 'textField',
                'formField' => true,
                'validationRule' => 'required|string|max:255',
            ],
            'status' => [
                'displayType' => 'chip',
                'chip' => [
                    'draft' => ['label' => ['en' => 'Draft', 'dv' => '...'], 'color' => 'secondary'],
                    'submitted' => ['label' => ['en' => 'Submitted', 'dv' => '...'], 'color' => 'primary'],
                ],
                // ...
            ],
        ],
        'searchable' => ['ref_num', 'summary'],
        'deletable' => [...],
    ];
}
```

### Existing Models

| Model | Purpose |
|-------|---------|
| `User` | Standard Laravel auth user |
| `BaseModel` | Abstract base with `apiSchema()`, validation, pagination, search/sort helpers |
| `Entry` | Case/document entries (belongs to EntryType) |
| `EntryType` | Entry classification types |
| `Country` | Geographic reference with bilingual names + nationality |
| `Letter` | Correspondence documents (~50+ schema fields) |
| `LegalAid` | Legal assistance records (~40+ validation rules) |
| `CForm` | Case forms with UUID keys (~100+ schema columns) |
| `Invoice` | Financial records |
| `_Person` | Bilingual person records (English + Dhivehi names) |

### Configuration

- `config/uiapi.php` (published from package) — controls view config path, route prefix, debug level, logging, and UUID enforcement (`enforce_uuid`)
- `Model::unguard()` enabled globally in `AppServiceProvider`

---

## Project: uiapi-package (Laravel Package)

**Path:** `/var/www/uiapi-package`
**Package Name:** `ogp/uiapi`
**Role:** Reusable Laravel package providing the generic API framework, component config service core, and developer tooling.
**Integration:** Symlinked into `uiapi` via Composer path repository.

### Purpose

This is the **engine** of the schema-driven system. It provides:

1. **Generic CRUD API** (`/api/gapi/{model}`) — Dynamic REST endpoints for any Eloquent model
2. **Component Config Service** (`/api/ccs/{model}`) — Transforms model schemas into frontend UI payloads
3. **BaseModel** — Abstract model class with `apiSchema()`, validation, date normalization, computed attributes
4. **Model Generator** — Creates models and view configs from SQL migrations
5. **View Config Validator** — Validates view config JSON files (30+ rules)

### Key Files

| File | Purpose |
|------|---------|
| `src/UiApiServiceProvider.php` | Registers commands, loads routes, publishes configs |
| `src/Http/Controllers/GenericApiController.php` | Dynamic CRUD: index, show, store, update, destroy |
| `src/Models/BaseModel.php` | Abstract base with `apiSchema()`, validation, date normalization |
| `src/Models/Person.php` | Example model with full bilingual schema |
| `src/Services/ComponentConfigService.php` | Core CCS logic — merges schema + view config + templates |
| `src/Services/ModelGeneratorService.php` | Parses migrations/SQL to generate models + view configs |
| `src/Services/ViewConfigValidator.php` | Validates view config structure (errors + warnings) |
| `src/Services/ComponentConfigs/*.json` | Component templates (table.json, form.json, toolbar.json, filterSection.json, meta.json) |
| `config/uiapi.php` | Package configuration (paths, prefix, debug level, UUID enforcement) |
| `routes/api.php` | Defines `/ccs/{model}` and `/gapi/{model}` routes |

### API Routes (registered by the package)

```
GET    /api/ccs/{model}         → ComponentConfigService (UI config payload)
GET    /api/gapi/{model}        → GenericApiController@index (list/paginate)
GET    /api/gapi/{model}/{id}   → GenericApiController@show
POST   /api/gapi/{model}        → GenericApiController@store
PUT    /api/gapi/{model}/{id}   → GenericApiController@update
DELETE /api/gapi/{model}/{id}   → GenericApiController@destroy
```

> **`{id}` accepts integer ID or UUID.** For models with a `uuid` column, passing the UUID is preferred. When `enforce_uuid: true` is set in `config/uiapi.php`, integer ID lookups are rejected with a `404` for UUID-enabled models — only UUID-based requests are accepted.

### GAPI Query Parameters

| Parameter | Example | Purpose |
|-----------|---------|---------|
| `columns` | `id,name,country.name_eng` | Select specific columns (dot-notation for relations) |
| `with` | `country,entryType` | Eager-load relationships |
| `filter[status]` | `draft` | Filter by field value |
| `search[name]` | `John` | Search specific field |
| `searchAll` | `John` | Global search across searchable fields |
| `sort` | `-created_at,name` | Sort (prefix `-` for DESC) |
| `per_page` | `15` | Pagination page size |
| `page` | `2` | Current page |
| `pagination` | `true` | Enable/disable pagination |

### CCS Query Parameters

| Parameter | Example | Purpose |
|-----------|---------|---------|
| `view` | `listView` | Return all components for a named view |
| `component` | `table` | Return payload for single component |
| `lang` | `dv` | Language preference |

### Artisan Commands

```bash
php artisan uiapi:generate {name} [--migration=path] [--sql=path]   # Generate model + view config
php artisan uiapi:validate [model]                                    # Validate view config JSON
```

### Model Resolution

The package resolves model names flexibly:
- `person` → `Person` → checks `Ogp\UiApi\Models\Person` then `App\Models\Person`
- Handles snake_case, camelCase, StudlyCase, kebab-case variants

### Documentation

The `docs/` folder contains comprehensive versioned documentation:
- `api-schema-reference-v0.1–v0.3.md` — Complete `apiSchema()` reference
- `view-config-manual-v0.1–v0.4.md` — View config authoring guides
- `view-config-reference.md` — Complete view config structure reference
- `model-generator.md` — Model generation guide
- `UIAPI-README.md` — Quick start guide
- `VALIDATOR-README.md` — Validation rules reference

---

## Project: uiux-copy (Vue 3 Frontend / Component Library)

**Path:** `/var/www/uiux-copy`
**Package Name:** `pgo-ui` (v1.0.40)
**Role:** Vue 3 component library + design system + demo application for the PGO frontend.
**Tech:** Vue 3, Vite, Tailwind CSS 4, TypeScript, Axios

### Purpose

This is a **dual-purpose project**:

1. **Component Library** — Published as an npm package (`pgo-ui`) with 50+ reusable Vue 3 components (DataTable, Form, Select, Modal, TipTap Editor, etc.)
2. **Demo/Showcase App** — Includes a full Vue Router application with interactive examples of all components

The library is designed to consume the JSON payloads produced by the CCS endpoint and dynamically render data-driven UIs.

### Architecture

```
src/
├── main.js                     # Demo app entry point
├── index.js                    # Library export entry point
├── App.vue / PgoApp.vue        # Demo application shells
├── router/                     # Vue Router (demo routes)
│
├── components/
│   ├── pgo/                    # 50+ Core UI components (.vue)
│   │   ├── DataTable.vue       # Advanced data table (sort, paginate, inline edit, chips)
│   │   ├── Form.vue            # Validation-aware form wrapper
│   │   ├── DynamicForm.vue     # Schema-driven form renderer
│   │   ├── Toolbar.vue         # Action toolbar (search, create, filters)
│   │   ├── FilterSection.vue   # Dynamic filter panel
│   │   ├── Modal.vue           # Dialog/modal
│   │   ├── Button.vue          # Multi-variant button
│   │   ├── Select.vue          # Dropdown select
│   │   ├── TextField.vue       # Text input
│   │   ├── DatePicker.vue      # Date picker
│   │   ├── TipTapEditor.vue    # Rich text editor
│   │   ├── AppBar.vue          # Top navigation bar
│   │   ├── NavigationDrawer.vue # Sidebar navigation
│   │   ├── Pagination.vue      # Pagination controls
│   │   ├── Chip.vue            # Badge/tag component
│   │   └── ... (50+ total)
│   │
│   └── examples/               # Demo components for each UI component
│       ├── DataTableExample.vue
│       ├── FormExample.vue
│       └── ... (30+ examples)
│
├── pgo-components/             # Infrastructure layer
│   ├── plugins/
│   │   ├── theme-plugin.js     # Light/dark theme engine (CSS variables)
│   │   ├── i18nPlugin.js       # Internationalization (en/dv)
│   │   ├── SnackBarPlugin.ts   # Toast/notification system
│   │   └── validation-plugin.js # Global form validation rules
│   │
│   ├── lib/
│   │   ├── componentConfig.js  # Design tokens (sizes, colors, spacing, grid maps)
│   │   ├── drawerState.ts      # Reactive drawer open/close state
│   │   ├── i18n/               # Translation messages + composable
│   │   └── core/rtl/           # Right-to-left support (Dhivehi)
│   │
│   ├── services/
│   │   └── axios.js            # Configured HTTP client (Bearer auth, interceptors)
│   │
│   ├── composables/
│   │   └── useTheme.js         # Theme injection hook
│   │
│   ├── directives/
│   │   └── tooltip-directive.ts # Advanced tooltip with RTL + bilingual support
│   │
│   ├── tokens/
│   │   └── index.js            # Design tokens (colors, radius, shadow, elevation)
│   │
│   └── pages/                  # Application pages
│       ├── ComponentRenderer.vue # Dynamic component loader (fetches /ccs/{model})
│       ├── ListView.vue         # Main data list page (table + toolbar + form + filters)
│       ├── Home.vue             # Example gallery
│       ├── CustomUrl.vue        # Custom URL handler
│       └── Examples.vue         # Component showcase
│
└── validations/
    └── validationRules.js       # Built-in rules (required, email, min, max, nid, etc.)
```

### Key Pages & Data Flow

**ComponentRenderer.vue** — Route-driven page that:
1. Reads `modelName` from route params
2. Calls `GET /api/ccs/{modelName}` to fetch UI configuration
3. Dynamically renders the appropriate component (usually `ListView`)

**ListView.vue** — The main data page that:
1. Receives component settings from ComponentRenderer
2. Renders `Toolbar` (search, create button, quick filters)
3. Renders `FilterSection` (advanced filters)
4. Renders `DataTable` (with headers, pagination, inline edit, chips, actions)
5. Opens `DynamicForm` modal for create/edit operations
6. Handles CRUD operations against `/api/gapi/{model}`

### Plugin System

The library injects these globally via Vue's provide/inject:

| Plugin | Injection Key | Purpose |
|--------|--------------|---------|
| Theme | `vts-theme` | Reactive light/dark mode with CSS variable tokens |
| i18n | `i18n` | Multi-language translation (`$t('key')`) |
| SnackBar | `snackbar` | Toast notifications (success, error, info) |
| Validation | `validationRules` | Form field validation rules |
| API | `api` | Configured axios instance with auth headers |
| Tooltip | `v-tooltip` directive | Hover/click tooltips with RTL awareness |

### Build Outputs

```json
"main": "./dist/index.umd.js",
"module": "./dist/index.es.js",
"exports": {
  ".": { "import": "./dist/index.es.js", "require": "./dist/index.umd.js" },
  "./style.css": "./dist/pgo-ui.css",
  "./validations": "./src/validations/validationRules.js",
  "./components/*": "./src/components/pgo/*.vue",
  "./examples/*": "./src/components/examples/*.vue"
}
```

---

## Project: uiapi-vscode (VS Code Extension)

**Path:** `/var/www/uiapi-vscode`
**Extension Name:** UiApi Model Generator (v0.2.0)
**Role:** Developer productivity tool — generates models and view configs from SQL files directly within VS Code.

### Purpose

A VS Code extension that bridges the developer's editor to the `uiapi-package`'s `ModelGeneratorService`. Right-click context menu actions let developers:

1. **Generate Model + View Config from SQL** — Right-click a `.sql` file
2. **Generate Model Only from SQL**
3. **Generate View Config Only from SQL**
4. **Generate View Config from Model** — Right-click a `.php` model with `apiSchema()`
5. **Validate View Config** — Right-click a view config JSON file

### How It Works

```
VS Code Context Menu → extension.js (registers commands)
    → bridge.js (calls vscode-bridge.php via child_process.execFile)
        → Laravel bootstraps → ModelGeneratorService
            → Parses SQL/migration → generates Model class + view config JSON
```

### Key Files

| File | Purpose |
|------|---------|
| `src/extension.js` | Registers 5 commands, handles menu interactions |
| `src/bridge.js` | PHP bridge — launches `vscode-bridge.php` with arguments |
| `src/config.js` | Auto-detects Laravel root and uiapi-package paths |
| `src/utils.js` | SQL table name → PascalCase model name conversion, English pluralization |

---

## End-to-End Data Flow

### Loading a List View (e.g., browsing "CForm" records)

```
1. User navigates to /page/CForm/listView
                    │
2. ComponentRenderer.vue mounts
   │  route.params.modelName = "CForm"
   │
   ▼
3. GET /api/ccs/CForm?view=listView&lang=dv
   │
   ▼
4. ComponentConfigService processes:
   │  a. Resolves App\Models\CForm → CForm::apiSchema()
   │  b. Loads app/Services/viewConfigs/cform.json
   │  c. Reads component templates (table.json, form.json, toolbar.json, etc.)
   │  d. Merges: schema columns + view config overrides + component templates
   │  e. Processes directives (headers: "on" → auto-generate from schema)
   │  f. Collapses bilingual labels to requested language
   │
   ▼
5. Returns JSON payload:
   {
     "component": "listView",
     "componentSettings": {
       "table": { headers, datalink, filters, pagination, actions, ... },
       "form": { fields, groups, validation, crudLink, ... },
       "toolbar": { title, filters, buttons, createButton, ... },
       "filterSection": { filters, buttons, ... },
       "meta": { crudLink, ... }
     }
   }
   │
   ▼
6. ListView.vue renders with componentSettings:
   │  ├── Toolbar (search box, create button, filter toggle)
   │  ├── FilterSection (dynamic filters)
   │  ├── DataTable
   │  │     │
   │  │     ▼
   │  │   7. GET /api/gapi/CForm?columns=id,uuid,ref_num,...&per_page=15&page=1
   │  │     │
   │  │     ▼
   │  │   8. GenericApiController@index:
   │  │      a. Resolves CForm model
   │  │      b. Applies filters, search, sorting
   │  │      c. Paginates results
   │  │      d. Returns { data: [...], pagination: {...} }
   │  │     │
   │  │     ▼
   │  │   9. DataTable renders rows with configured headers,
   │  │      chips for status, sortable columns, action buttons
   │  │
   │  └── DynamicForm (modal, opened on create/edit)
   │        │
   │        ▼
   │      10. POST /api/gapi/CForm (create)
   │          PUT /api/gapi/CForm/{id} (update)
   │          DELETE /api/gapi/CForm/{id} (delete)
```

### Creating a New Model (Developer Workflow)

```
1. Developer writes SQL migration or has .sql file
                    │
2. Right-click in VS Code → "Generate Model + View Config from SQL"
                    │
3. uiapi-vscode extension invokes bridge.js
   │  → spawns PHP process → ModelGeneratorService
   │
   ▼
4. Output:
   ├── app/Models/NewModel.php (with apiSchema(), casts, validation rules, relationships)
   └── app/Services/viewConfigs/newmodel.json (table, form, toolbar, filter configs)
                    │
5. Developer customizes view config JSON as needed
                    │
6. Frontend immediately works — navigate to /page/NewModel/listView
```

---

## Schema-Driven UI Generation

The core innovation of this system is that **UI is generated from data, not hardcoded**. Here's how the three layers combine:

### Layer 1: Model Schema (`apiSchema()`)

Defines what the data looks like — columns, types, labels, validation, display hints.

### Layer 2: View Config (JSON files in `viewConfigs/`)

Defines how the data should be presented — which columns to show, column customizations (chips, inline editing, custom display types), form field groups, toolbar buttons, filter sections, delete confirmations, and custom JS functions.

### Layer 3: Component Templates (`ComponentConfigs/*.json`)

Defines the component structure — what directives are available (`headers: "on"`, `datalink: "on"`, `filters: "on"`), default settings, and the shape of each component's output.

### Merge Order

```
Component Template (defaults)
    ↓  overridden by
View Config (per-model customizations)
    ↓  enriched by
Model Schema (column definitions, labels, types)
    ↓  filtered by
Language selection (en/dv)
    =
Final Frontend Payload
```

---

## Key Concepts & Terminology

| Term | Definition |
|------|-----------|
| **apiSchema** | Static method on models that defines column metadata (labels, types, display hints, validation) |
| **viewConfig** | JSON file that customizes how a model's UI renders (column overrides, form groups, toolbar settings) |
| **CCS** | Component Config Service — the backend service that merges schema + view config + templates |
| **GAPI** | Generic API — the dynamic CRUD endpoints (`/api/gapi/{model}`) |
| **ComponentRenderer** | Frontend page that fetches CCS payload and dynamically renders components |
| **ListView** | Frontend page that orchestrates DataTable + Toolbar + FilterSection + DynamicForm |
| **displayType** | How a column value renders: `text`, `chip`, `checkbox`, `select`, `html`, `custom`, `docButton`, `date`, `englishText` |
| **inputType** | How a form field renders: `textField`, `numberField`, `select`, `datepicker`, `textarea`, `file`, `search`, `label` |
| **inlineEditable** | Flag that allows a column to be edited directly in the DataTable (with confirmation modal) |
| **columnCustomizations** | View config block that overrides schema defaults for specific columns |
| **noModel mode** | CCS mode where column schema is defined inline in the view config (no Eloquent model needed) |
| **columnsSchema** | Inline column definitions used in noModel mode (replaces `apiSchema()`) |
| **BaseModel** | Abstract Eloquent model providing `apiSchema()`, validation, date normalization, computed attributes |
| **Design tokens** | CSS variable system in uiux-copy for consistent theming (colors, spacing, radius, shadows) |

---

## API Endpoints

### Component Config Service (CCS)

```
GET /api/ccs/{model}
```

**Purpose:** Returns UI configuration payload for rendering components.

| Query Param | Type | Description |
|------------|------|-------------|
| `view` | string | Named view to render (e.g., `listView`) — returns all components |
| `component` | string | Single component to return (e.g., `table`, `form`) |
| `lang` | string | Language preference (`en` or `dv`) |

**Response shape:**
```json
{
  "component": "listView",
  "componentSettings": {
    "table": {
      "headers": [{ "title": "...", "value": "...", "sortable": true, ... }],
      "datalink": "/api/gapi/CForm?columns=id,uuid,...&per_page=15",
      "pagination": { "page": 1, "itemsPerPage": 15 },
      "actions": { "showView": true, "showEdit": true, "showDelete": true },
      "delete": { "title": "...", "crudLink": "gapi/CForm" },
      "functions": { ... },
      "filters": [...]
    },
    "form": {
      "fields": [...],
      "groups": [...],
      "createTitle": { "en": "...", "dv": "..." },
      "updateLink": "...",
      "showLink": "..."
    },
    "toolbar": { ... },
    "filterSection": { ... },
    "meta": { ... }
  }
}
```

### Generic API (GAPI)

```
GET    /api/gapi/{model}        # List (paginated, filterable, sortable)
GET    /api/gapi/{model}/{id}   # Show single record
POST   /api/gapi/{model}        # Create
PUT    /api/gapi/{model}/{id}   # Update
DELETE /api/gapi/{model}/{id}   # Delete
```

**List query parameters:**

| Parameter | Example | Description |
|-----------|---------|-------------|
| `columns` | `id,name,country.name_eng` | Select columns (dot-notation for relations) |
| `with` | `country,entryType` | Eager-load relationships |
| `filter[status]` | `draft` | Exact field filter |
| `search[name]` | `John` | Search within specific field |
| `searchAll` | `John` | Global search across `searchable` fields |
| `sort` | `-created_at,name` | Sort (`-` prefix = DESC) |
| `per_page` | `15` | Page size |
| `page` | `2` | Page number |
| `pagination` | `true/false` | Toggle pagination |

---

## View Config System

### File Location

View config JSON files live in `app/Services/viewConfigs/` (configurable via `config/uiapi.php`).

### Naming Convention

Filename matches the model name in lowercase: `Person` → `person.json`, `CForm` → `cform.json`.

### Structure

```json
{
  "listView": {
    "components": {
      "table": "ModelName/table",
      "form": "ModelName/form",
      "toolbar": "ModelName/toolbar",
      "filterSection": "ModelName/filterSection",
      "meta": "ModelName/meta"
    },
    "lang": ["en", "dv"]
  },
  "table": {
    "per_page": 15,
    "columns": ["id", "name", "status", "related.field"],
    "columnCustomizations": {
      "status": {
        "displayType": "chip",
        "chip": {
          "draft": { "label": { "en": "Draft", "dv": "..." }, "color": "secondary" },
          "submitted": { "label": { "en": "Submitted", "dv": "..." }, "color": "primary" }
        },
        "sortable": true,
        "inlineEditable": true
      }
    },
    "actions": { "showView": true },
    "delete": { "title": { "en": "Confirm", "dv": "..." }, "crudLink": "gapi/Model" },
    "functions": { "customFn": { "file": "misc.js", "function": "customFn" } }
  },
  "form": {
    "groups": [
      { "name": "BasicInfo", "title": { "en": "Basic", "dv": "..." }, "numberOfColumns": 3 }
    ],
    "fields": [
      { "key": "name", "group": "BasicInfo", "rules": "[rules.required]" },
      { "key": "type", "inputType": "select", "mode": "url", "url": "..." }
    ],
    "variables": { ... },
    "functions": { ... }
  },
  "toolbar": { ... },
  "filterSection": { ... },
  "meta": { ... }
}
```

---

## Component Map

### How View Config Components Map to Vue Components

| View Config Component | Vue Component | Purpose |
|----------------------|---------------|---------|
| `table` | `DataTable.vue` | Data table with headers, sorting, pagination, chips, inline edit |
| `form` | `DynamicForm.vue` + `Form.vue` | Modal form with grouped fields, validation, CRUD |
| `toolbar` | `Toolbar.vue` | Top bar with search, create button, quick filters |
| `filterSection` | `FilterSection.vue` | Advanced filter panel with dynamic inputs |
| `meta` | (used by ListView internally) | CRUD links, metadata |

### DataTable Display Types

| displayType | Renders As |
|-------------|-----------|
| `text` (default) | Plain text |
| `chip` | Colored badge with icon (status indicators) |
| `checkbox` | Checkbox (optionally inline-editable) |
| `select` | Dropdown (inline-editable mode) |
| `input` | Text input (inline-editable mode) |
| `html` | Raw HTML from custom function |
| `custom` | Custom render via JS function (supports CopyTextBox, etc.) |
| `docButton` | Document viewer button |
| `date` | Date-formatted text (English font) |
| `englishText` | Text forced to English font |

---

## Bilingual / RTL Support

The system has first-class support for **English** and **Dhivehi** (Thaana script, RTL):

### Backend
- Model labels defined as `{ "en": "Name", "dv": "ނަން" }`
- CCS collapses to requested language via `lang` param
- View configs specify `lang: ["en", "dv"]` for supported languages
- Validation messages support both languages

### Frontend
- `i18nPlugin` provides `$t()` translation function
- RTL module sets `document.dir = 'rtl'` and applies `rtl-font` CSS class
- Font switching: Roboto (English) ↔ Faruma (Dhivehi)
- All components respect `textAlign` maps that account for RTL
- Tooltip directive supports bilingual text objects
- Language persisted in localStorage

### Fonts
- **English:** Roboto (via `eng-font` class)
- **Dhivehi:** Faruma (via `faruma` class, automatically applied in RTL mode)

---

## noModel Architecture

The standard flow requires an Eloquent model with `apiSchema()`. The **noModel** mode is an alternative that lets you build UI from a view config alone — no model, no database table, no GAPI data endpoint. This is useful for:

- Displaying data from **external APIs** (the frontend fetches data itself)
- Building **static/configuration UIs** that don't map to a database table
- **Prototyping** a UI before the model exists
- Rendering data from **non-Eloquent sources**

### How It Works

In noModel mode, the CCS endpoint still works (`GET /api/ccs/{configName}`), but instead of resolving an Eloquent model and reading its `apiSchema()`, it reads column definitions directly from the view config JSON's `columnsSchema` block.

### Model Flow vs. noModel Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                    MODEL FLOW (Standard)                        │
│                                                                 │
│  GET /api/ccs/CForm                                             │
│       │                                                         │
│       ▼                                                         │
│  1. Load viewConfig: cform.json                                 │
│  2. Detect noModel: false (default)                             │
│  3. Resolve model: App\Models\CForm                             │
│  4. Read CForm::apiSchema() → columnsSchema                    │
│  5. Merge: component templates + view config + apiSchema        │
│  6. Generate datalink: "/api/gapi/CForm?columns=..."            │
│  7. Return payload                                              │
│       │                                                         │
│       ▼                                                         │
│  Frontend fetches data from datalink → /api/gapi/CForm          │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                    noModel FLOW                                 │
│                                                                 │
│  GET /api/ccs/noModel-cform                                     │
│       │                                                         │
│       ▼                                                         │
│  1. Load viewConfig: noModel-cform.json                         │
│  2. Detect noModel: true (from listView block)                  │
│  3. SKIP model resolution entirely                              │
│  4. Read columnsSchema from view config JSON directly           │
│  5. Merge: component templates + view config + columnsSchema    │
│  6. Datalink: auto-generated or manually set in view config     │
│  7. Return payload                                              │
│       │                                                         │
│       ▼                                                         │
│  Frontend fetches data from whatever URL the datalink points to │
│  (could be external API, custom endpoint, etc.)                 │
└─────────────────────────────────────────────────────────────────┘
```

### Key Differences

| Aspect | Model Mode | noModel Mode |
|--------|-----------|-------------|
| Column schema source | `Model::apiSchema()` | `columnsSchema` block in view config JSON |
| Model resolution | Resolves Eloquent model class | Skipped entirely |
| Relation columns (dot-notation) | Validates relation exists on model | Lenient passthrough (no validation) |
| GAPI data endpoint | Auto-generated `/api/gapi/{model}` | Must be set manually or uses whatever is configured |
| Validation | Model's `validate()` method | Not applicable (no model) |
| Use case | Standard database-backed CRUD | External data, prototyping, non-Eloquent sources |

### noModel View Config Example

File: `app/Services/viewConfigs/noModel-cform.json`

```json
{
  "listView": {
    "noModel": true,
    "components": {
      "table": "CForm/table",
      "form": "CForm/form",
      "toolbar": "CForm/toolbar",
      "filterSection": "CForm/filterSection",
      "meta": "CForm/meta"
    },
    "lang": ["en", "dv"]
  },
  "columnsSchema": {
    "ref_num": {
      "width": "auto",
      "sortable": true,
      "label": { "en": "Ref Number", "dv": "ރެފް ނަންބަރު" },
      "lang": ["en", "dv"]
    },
    "client_ref_number": {
      "label": { "en": "Client Ref", "dv": "ކްލައިންޓް ރެފް" },
      "lang": ["en", "dv"]
    },
    "status": {
      "displayType": "chip",
      "chip": {
        "draft": { "label": { "en": "Draft" }, "color": "secondary" },
        "submitted": { "label": { "en": "Submitted" }, "color": "primary" }
      },
      "lang": ["en", "dv"]
    }
  },
  "table": {
    "per_page": 15,
    "columns": ["ref_num", "client_ref_number", "status"],
    "columnCustomizations": { ... }
  },
  "form": { ... },
  "toolbar": { ... },
  "filterSection": { ... }
}
```

### How CCS Detects noModel

In the package's `ComponentConfigService`, the detection happens at the view-level block:

```php
$isNoModel = (bool) ($compBlock['noModel'] ?? false);

if ($isNoModel) {
    // Read columns from view config's columnsSchema
    $columnsSchema = $compBlock['columnsSchema'] ?? [];
    // SKIP model resolution
} else {
    // Standard path: resolve model, call apiSchema()
    $resolved = $this->resolveModel($targetModel);
    $columnsSchema = $schema['columns'] ?? [];
}
```

The rest of the pipeline (header building, filter generation, column customizations, language collapsing) works identically regardless of the source of `columnsSchema`.

### When to Use noModel

- **External API data**: You fetch data from a third-party API and want to display it in a DataTable. Define columns in `columnsSchema`, set a custom `datalink`, and the frontend handles the rest.
- **Cross-service data**: Data comes from another internal microservice, not from this app's database.
- **Static configuration pages**: UI that doesn't need CRUD operations.
- **Rapid prototyping**: Define the UI structure before creating the database table and model.

---

## Quick Reference: Running the Projects

### uiapi (Backend)
```bash
cd /var/www/uiapi
composer install
php artisan serve          # Start dev server
php artisan test           # Run tests
vendor/bin/pint --dirty    # Format code
```

### uiux-copy (Frontend)
```bash
cd /var/www/uiux-copy
npm install
npm run dev                # Vite dev server
npm run build              # Production build
```

### uiapi-vscode (Extension)
- Open in VS Code → F5 to launch Extension Development Host
- Right-click `.sql` or `.php` files to use generator commands
