# View Config Reference

Complete reference for authoring **view config JSON** files consumed by the Component Config Service (CCS). These files drive the UI payload that CCS returns — headers, filters, data links, form fields, toolbar, and component settings — all from a single JSON file per model.

---

## Table of Contents

1. [Overview](#overview)
2. [File Location & Naming](#file-location--naming)
3. [Top-Level Structure](#top-level-structure)
4. [View Block Keys](#view-block-keys)
   - [components](#components-required)
   - [columns](#columns-required)
   - [columnCustomizations](#columncustomizations-optional)
   - [columnsSchema (noModel only)](#columnsschema-required-for-nomodel)
   - [filters](#filters-optional)
   - [per_page](#per_page-optional)
   - [lang](#lang-required)
   - [noModel](#nomodel-optional)
5. [Component Blocks](#component-blocks)
   - [table](#table)
   - [form](#form)
   - [toolbar](#toolbar)
   - [filterSection](#filtersection)
   - [meta](#meta)
6. [Column Customizations Deep Dive](#column-customizations-deep-dive)
7. [External JS Functions](#external-js-functions)
8. [Localization](#localization)
9. [Component Config Templates](#component-config-templates)
10. [apiSchema() Reference](#apischema-reference)
11. [Package Config (uiapi.php)](#package-config-uiapiphp)
12. [Full Example](#full-example)
13. [Quick Checklist](#quick-checklist)

---

## Overview

The CCS endpoint `GET /{prefix}/ccs/{model}?component={viewKey}&lang={lang}` reads a view config JSON, merges it with the model's `apiSchema()` and internal component config templates, and returns a fully assembled UI payload.

**Data flow:**

```
View Config JSON  ──┐
                    ├──→  CCS  ──→  JSON Response (componentSettings, headers, filters, etc.)
Model apiSchema()  ─┘
Component Templates ┘
```

---

## File Location & Naming

| Setting | Default |
|---|---|
| Config key | `uiapi.view_configs_path` |
| Default path | `app/Services/viewConfigs/` |

**Filename rules:**
- Lowercase, no hyphens, no underscores, no spaces.
- Must match the normalized model name.
- Extension: `.json`

| Model Name | Filename |
|---|---|
| `Person` | `person.json` |
| `CForm` | `cform.json` |
| `LegalAid` | `legalaid.json` |
| `EntryType` | `entrytype.json` |

---

## Top-Level Structure

A view config file is a JSON object with one or more **view block** keys. Each view block key is a named view (e.g. `listView`, `detailView`, `listView2`).

```json
{
  "listView": { ... },
  "detailView": { ... }
}
```

The CCS `component` query parameter selects which view block to use:

```
GET /api/ccs/person?component=listView&lang=en
```

---

## View Block Keys

These are the keys recognized inside each view block (e.g. inside `"listView": { ... }`).

### `components` (required)

Declares which UI components to assemble. Each key must correspond to a [component config template](#component-config-templates) (`table`, `form`, `toolbar`, `filterSection`, `meta`). The value is an object with per-component overrides.

```json
"components": {
  "table": { ... },
  "form": { ... },
  "toolbar": { ... },
  "filterSection": { ... },
  "meta": {}
}
```

An empty object `{}` means "use template defaults with no overrides."

**How it works:** CCS loads the template (e.g. `table.json`), processes its directives (`"headers": "on"`, `"datalink": "on"`, etc.), then applies the overrides from your component block on top.

---

### `columns` (required)

An array of column tokens that define which fields this view shows. This is the **root-level** column list used as the default when a component doesn't declare its own `columns`.

```json
"columns": [
  "id",
  "ref_num",
  "status",
  "created_at",
  "country.name_eng"
]
```

**Relation tokens** use dot notation: `relation.field` (e.g. `createdby.first_name_div`). CCS automatically derives the `with` query parameter from dot tokens.

Components can override the root columns by declaring their own `columns` array inside their component block. See [table → columns](#per-component-columns).

---

### `columnCustomizations` (optional)

A map of column token → customization object. Applied at the root level as defaults. Can be overridden per-component.

```json
"columnCustomizations": {
  "status": {
    "width": "180px",
    "displayType": "chip",
    "sortable": true,
    "inlineEditable": true,
    "chip": { ... }
  },
  "created_at": {
    "hidden": false,
    "width": "180px",
    "displayType": "date",
    "sortable": true
  }
}
```

If both root and component-level customizations exist for the same column, they are **deep-merged** (component wins on conflict).

See [Column Customizations Deep Dive](#column-customizations-deep-dive) for all accepted keys.

---

### `columnsSchema` (required for noModel)

Only used when [`noModel`](#nomodel-optional) is `true`. Replaces the model's `apiSchema()` entirely. A map of column token → schema definition.

```json
"columnsSchema": {
  "id": {
    "hidden": true,
    "key": "id",
    "label": { "en": "ID" },
    "type": "number",
    "sortable": true,
    "lang": ["en", "dv"]
  },
  "country.name_eng": {
    "hidden": true,
    "lang": ["en"]
  },
  "first_name_eng": {
    "key": "first_name_eng",
    "label": { "en": "First Name" },
    "type": "string",
    "displayType": "text",
    "sortable": true,
    "lang": ["en"]
  }
}
```

Relation dot tokens (`country.name_eng`) are fully supported as keys in `columnsSchema`.

See [apiSchema() Reference](#apischema-reference) for accepted column definition keys.

---

### `filters` (optional)

Array of column keys that are allowed as filter fields. CCS only builds filter metadata for columns listed here.

```json
"filters": ["status", "sender_type", "submitted_at", "ref_num"]
```

If omitted, all filterable columns from `apiSchema()` are included.

---

### `per_page` (optional)

Default page size for this view. Overridable at request time via `?per_page=`.

```json
"per_page": 15
```

Default: `25` if omitted.

---

### `lang` (required)

Array of language codes this view supports. CCS validates the request `lang` parameter against this list. If the requested language isn't allowed, CCS returns a message and empty data.

```json
"lang": ["en", "dv"]
```

Currently supported: `"en"` (English), `"dv"` (Dhivehi). Columns with a `lang` array in their schema are filtered by the request language.

---

### `noModel` (optional)

When `true`, CCS skips model resolution and uses `columnsSchema` from the view config instead. Useful for views that don't have a corresponding Eloquent model.

```json
"noModel": true
```

Default: `false` (model-backed mode).

**Requirements when `noModel: true`:**
- `columnsSchema` is mandatory.
- `columns` still required for column ordering.

---

## Component Blocks

Each key inside `components` is processed against a template and then receives overrides from the view config.

### `table`

**Template** (`ComponentConfigs/table.json`):

| Template Key | Default | Description |
|---|---|---|
| `headers` | `"on"` | Auto-build headers from schema + customizations |
| `filters` | `"off"` | Include filter metadata |
| `pagination` | `"on"` | Include pagination block |
| `datalink` | `"on"` | Auto-build data fetch URL |
| `button` | `["create","edit","delete"]` | Action buttons |
| `actions` | array of action objects | CRUD action definitions |

**Accepted override keys:**

| Key | Type | Description |
|---|---|---|
| `columns` | `string[]` | Override root-level columns for this component only |
| `columnCustomizations` | `object` | Override/extend root-level customizations for this component |
| `buttons` | `string[]` | Filter template buttons to only these types |
| `actionButtons` | `string[]` | Custom action button list |
| `functions` | `object` | External JS function map (see [External JS Functions](#external-js-functions)) |

#### Per-Component Columns

```json
"table": {
  "columns": ["id", "uuid", "ref_num", "summary", "status", "createdby.first_name_div"],
  "columnCustomizations": {
    "New_ref": {
      "label": { "en": "New Column", "dv": "ކޮލަމް" },
      "columnData": "customeColumnData(item)",
      "order": 3,
      "displayType": "custom"
    }
  }
}
```

When `columns` is set inside a component block, it takes precedence over the root-level `columns` for that component only.

---

### `form`

**Template** (`ComponentConfigs/form.json`):

| Template Key | Default | Description |
|---|---|---|
| `fields` | `"on"` | Auto-build form fields from schema |
| `rules` | `"on"` | Include validation rules |
| `createLink` | `"off"` | Build POST create endpoint URL |
| `numberOfColumns` | `3` | Default column layout |
| `button` | `["create","edit"]` | Form action buttons |

**Accepted override keys:**

| Key | Type | Required | Description |
|---|---|---|---|
| `createTitle` | `{en, dv}` | Recommended | Title when creating a new record |
| `editTitle` | `{en, dv}` | Recommended | Title when editing a record |
| `groups` | `array` | Recommended | Form section groups |
| `fields` | `array` | Recommended | Form field definitions with overrides |
| `variables` | `object` | Optional | Reactive variables for conditional logic |
| `functions` | `object` | Optional | External JS function map |

#### Form Groups

```json
"groups": [
  {
    "name": "Search",
    "title": { "en": "Search", "dv": "ހޯދާ" },
    "numberOfColumns": 2
  },
  {
    "name": "BasicInfo",
    "condition": "SearchResults",
    "title": { "en": "Basic Information", "dv": "މަޢުލޫމާތު" },
    "numberOfColumns": 3
  }
]
```

| Key | Type | Required | Description |
|---|---|---|---|
| `name` | `string` | ✅ | Unique group identifier (referenced by fields) |
| `title` | `{en, dv}` or `string` | ✅ | Group heading |
| `numberOfColumns` | `integer` | Optional | Column layout for this group (default: from template) |
| `condition` | `string` | Optional | Variable name from `variables` that controls visibility |

#### Form Fields

Fields override the auto-generated form fields from `apiSchema()`. When provided as an array of objects, they **restrict and reorder** the fields to only those listed (matched by `key`).

```json
"fields": [
  {
    "key": "nid_pp",
    "group": "Search",
    "columnSpan": 2,
    "inputType": "search",
    "submitUrl": "gapi/person/",
    "events": {
      "results": "handleSearchResults"
    }
  },
  { "key": "ref_num", "group": "BasicInfo" },
  { "key": "summary", "inputType": "textarea", "group": "BasicInfo", "columnSpan": 3 },
  { "key": "uuid", "hidden": true, "formField": false, "group": "Submission" },
  { "key": "submitted_at", "group": "Submission", "format": "dd/MM/yyyy" }
]
```

| Key | Type | Required | Description |
|---|---|---|---|
| `key` | `string` | ✅ | Column name (must match apiSchema key) |
| `group` | `string` | ✅ | Which group this field belongs to |
| `hidden` | `bool` | Optional | Hide the field from UI |
| `formField` | `bool` | Optional | `false` to exclude from form entirely |
| `inputType` | `string` | Optional | Override the auto-detected input type |
| `columnSpan` | `integer` | Optional | Number of grid columns to span |
| `placeholder` | `string` | Optional | Placeholder text |
| `format` | `string` | Optional | Display format (e.g. date format) |
| `submitUrl` | `string` | Optional | URL for search-type inputs |
| `events` | `object` | Optional | Event handler map (values are function names) |

**Auto-detected `inputType` by schema type:**

| Schema Type | Default inputType |
|---|---|
| `string` | `textField` |
| `number` | `numberField` |
| `boolean` | `checkbox` |
| `date` | `datepicker` |
| FK column | `select` |

#### Form Variables

Reactive variables for conditional form group visibility.

```json
"variables": {
  "draftingEnabled": true,
  "SearchResults": false
}
```

Referenced by `condition` in form groups. The frontend toggles these via event handlers.

---

### `toolbar`

**Template** (`ComponentConfigs/toolbar.json`):

| Template Key | Default | Description |
|---|---|---|
| `filters` | `"on"` | Include filter metadata |
| `searchAll` | `"on"` | Include global search config |
| `buttons` | array of button objects | Toolbar action buttons |

**Accepted override keys:**

| Key | Type | Description |
|---|---|---|
| `title` | `{en, dv}` | Toolbar heading |
| `filters` | `string[]` | Restrict filters to these column keys |
| `buttons` | `string[]` | Filter template buttons to these types |

```json
"toolbar": {
  "title": { "en": "Forms", "dv": "ސަރަހައްދު" },
  "filters": ["status"],
  "buttons": ["search", "clear"]
}
```

---

### `filterSection`

**Template** (`ComponentConfigs/filterSection.json`):

| Template Key | Default | Description |
|---|---|---|
| `filters` | `"on"` | Include filter metadata |
| `buttons` | array of button objects | Filter action buttons |

**Accepted override keys:**

| Key | Type | Description |
|---|---|---|
| `filters` | `string[]` | Restrict filters to these column keys |
| `buttons` | `string[]` | Filter template buttons to these types |
| `columnCustomizations` | `object` | Per-column overrides specific to the filter section |

```json
"filterSection": {
  "filters": ["status"],
  "buttons": ["submit", "clear"],
  "columnCustomizations": {
    "status": {
      "inputType": "select",
      "select": {
        "type": "select",
        "mode": "self",
        "items": [
          { "itemTitleEn": "Draft", "itemTitleDv": "ޑްރާފްޓް", "itemValue": "draft" },
          { "itemTitleEn": "Submitted", "itemTitleDv": "ސަބްމިޓް", "itemValue": "submitted" }
        ]
      }
    }
  }
}
```

---

### `meta`

**Template** (`ComponentConfigs/meta.json`):

| Template Key | Default | Description |
|---|---|---|
| `crudLink` | `"on"` | Auto-generate CRUD link (`gapi/{Model}`) |

**Accepted override keys:** Any custom keys. An empty object `{}` uses template defaults.

```json
"meta": {}
```

---

## Column Customizations Deep Dive

Column customizations override or extend schema-derived header properties. They can be set at the **root level** (applies to all components) and/or inside a **component block** (applies to that component only). Component-level customizations are deep-merged on top of root-level.

### Accepted Keys

| Key | Type | Description |
|---|---|---|
| `title` | `{en, dv}` or `string` | Override the header title/label |
| `label` | `{en, dv}` or `string` | Same as `title` (used for custom columns) |
| `hidden` | `bool` | Hide/show in headers |
| `sortable` | `bool` | Enable/disable sorting |
| `width` | `string` | Column width (e.g. `"180px"`, `"auto"`) |
| `align` | `string` | Text alignment (e.g. `"center"`) |
| `displayType` | `string` | How to render: `"text"`, `"chip"`, `"checkbox"`, `"date"`, `"select"`, `"custom"`, `"html"` |
| `type` | `string` | Data type hint: `"string"`, `"number"`, `"boolean"`, `"date"`, `"chip"` |
| `inlineEditable` | `bool` | Allow inline editing |
| `editable` | `bool` | Alias for `inlineEditable` |
| `displayProps` | `object` | Legacy: display configuration (prefer using `{displayType}` key instead) |
| `order` | `integer` | 0-based position index for header reordering |

### Display Type Configs

When `displayType` is set, you can provide a config object under a key matching the display type name.

#### Chip Config

```json
"status": {
  "displayType": "chip",
  "chip": {
    "draft": {
      "size": "sm",
      "label": { "en": "Draft", "dv": "ޑްރާފްޓް" },
      "color": "secondary",
      "prependIcon": "file"
    },
    "submitted": {
      "size": "sm",
      "label": { "en": "Submitted", "dv": "ސަބްމިޓް" },
      "color": "primary",
      "prependIcon": "paper-airplane"
    }
  }
}
```

Each chip option key is a **field value** (e.g. `"draft"`, `"submitted"`). The labels are localized and resolved to the request language automatically.

| Chip Option Key | Type | Description |
|---|---|---|
| `size` | `string` | Size variant (`"sm"`, `"md"`, `"lg"`) |
| `label` | `{en, dv}` or `string` | Display label |
| `color` | `string` | Color token (`"primary"`, `"secondary"`, `"success"`, `"error"`, `"warning"`) |
| `prependIcon` | `string` | Icon name to show before label |

#### Select Config (inline editing / filters)

```json
"gender": {
  "displayType": "select",
  "inlineEditable": true,
  "displayProps": {
    "items": [
      { "title": "Male", "value": "M" },
      { "title": "Female", "value": "F" }
    ]
  }
}
```

### Custom Columns (Non-Schema)

You can add entirely new columns that don't exist in the model schema. These appear as extra headers. The key becomes the `value` in the header.

```json
"columnCustomizations": {
  "New_ref": {
    "label": { "en": "New Column", "dv": "ކޮލަމް" },
    "columnData": "customeColumnData(item)",
    "order": 3,
    "type": "chip",
    "displayType": "custom"
  }
}
```

| Key | Type | Description |
|---|---|---|
| `label` | `{en, dv}` or `string` | **Required** for custom columns (fallback: title-cased key) |
| `columnData` | `string` | JS expression or function call for dynamic data |
| `order` | `integer` | 0-based position in the header list |
| `displayType` | `string` | How to render (e.g. `"custom"`, `"html"`) |

### Header Reordering (`order`)

The `order` key sets a **0-based** insertion position for the header.

- Headers without `order` keep their natural position.
- Ordered headers are pulled out, sorted by their `order` value, then spliced in at the requested index.
- Out-of-range values are clamped to the end.
- Conflicts (same `order`) are resolved by insertion order.
- The `order` key is **stripped** from the final payload output.

```json
"columnCustomizations": {
  "status": { "order": 0 },
  "ref_num": { "order": 2 },
  "New_ref": { "label": { "en": "Custom" }, "order": 3 }
}
```

---

## External JS Functions

Components can reference JavaScript functions stored in external `.js` files. CCS extracts the function body at runtime and inlines it into the payload.

### Config

| Setting | Default |
|---|---|
| Config key | `uiapi.js_scripts_path` |
| Default path | `app/Services/jsScripts/` |

### Syntax

Inside a component block, add a `functions` object:

```json
"functions": {
  "handleSearchResults": {
    "file": "misc.js",
    "function": "handleSearchResults"
  },
  "customeColumnData": {
    "file": "misc.js",
    "function": "customeColumnData"
  }
}
```

| Key | Type | Required | Description |
|---|---|---|---|
| `file` | `string` | ✅ | JS filename in the `js_scripts_path` directory |
| `function` | `string` | ✅ | Function name to extract (`function name(...) { ... }`) |

**Inline functions** are also supported — just use a plain string value:

```json
"functions": {
  "simpleHandler": "console.log('hello');"
}
```

CCS extracts the function body (inner content between `{` and `}`) using brace-counting, so nested braces are handled correctly.

### JS File Example

`app/Services/jsScripts/misc.js`:

```javascript
function handleSearchResults() {
    console.log("Responses:", response);
    if (response && response.data && response.data.length > 0) {
        formData.client_ref_number = response.data[0].nid_pp;
        formVariables.SearchResults = true;
    }
}

function customeColumnData(item) {
    const componentData = {
        type: 'chips',
        chips: [
            { size: 'small', label: item.ref_num, color: 'success' },
            { size: 'small', label: item.uuid, color: 'error' }
        ]
    };
    return componentData;
}
```

---

## Localization

### Language Selection

CCS selects the language via the `?lang=` query parameter (default: `dv`).

### Localized Values

Any key that accepts `{en, dv}` is resolved to a single string based on the request language:

```json
"title": { "en": "Forms", "dv": "ފޯމުތައް" }
```

With `?lang=en` → `"title": "Forms"`

### Column Language Filtering

Columns with a `lang` array in their schema are only included when the request language matches:

```json
"first_name_eng": {
  "lang": ["en"]
}
```

This column is excluded when `?lang=dv`. Columns with `"lang": ["en", "dv"]` appear for both languages.

### Localized Payload Keys

These keys are automatically collapsed from `{en, dv}` objects to a single string in the response:

- `createTitle`
- `editTitle`
- `title`
- `label`

---

## Component Config Templates

Templates live in the package at `src/Services/ComponentConfigs/*.json`. They define the **skeleton** of each component's payload. Your view config overrides are applied on top.

### Template Directives

Template values use a tri-state pattern:

| Value | Behavior |
|---|---|
| `"on"` | CCS auto-builds this section from schema/model |
| `"off"` | Section is omitted from payload |
| Any other value | Passed through as-is (custom override) |

### Available Templates

| Template | File | Key Directives |
|---|---|---|
| `table` | `table.json` | `headers`, `filters`, `pagination`, `datalink`, `button`, `actions` |
| `form` | `form.json` | `fields`, `rules`, `createLink`, `numberOfColumns`, `button` |
| `toolbar` | `toolbar.json` | `filters`, `searchAll`, `buttons` |
| `filterSection` | `filterSection.json` | `filters`, `buttons` |
| `meta` | `meta.json` | `crudLink` |

### How Overrides Work

1. CCS loads the template JSON.
2. Each template key is processed (e.g. `"headers": "on"` → builds headers).
3. Your component block overrides are applied via `applyOverridesToSection()`.

**Override behaviors:**

| Override Type | Behavior |
|---|---|
| Scalar `"off"` | Removes the key from payload |
| Scalar value | Replaces the existing value |
| Array of strings (e.g. `["submit", "clear"]`) | **Filters** the template array to only items matching by `type`, `key`, `name`, or `label` |
| Array of objects (e.g. fields) | **Merges** with existing items by `key` match; appends unmatched items |
| Unknown keys | Passed through only if `allow_custom_component_keys` is `true` in config |

---

## apiSchema() Reference

Your model's `apiSchema()` method returns the schema that CCS uses for header building, filter generation, and form fields. Here's the complete column definition format.

```php
public function apiSchema(): array
{
    return [
        'columns' => [
            'field_name' => [ ... ],
        ],
        'searchable' => $this->searchable,
    ];
}
```

### Column Definition Keys

| Key | Type | Required | Description |
|---|---|---|---|
| `hidden` | `bool` | ✅ | Whether the column is hidden from table headers |
| `key` | `string` | ✅ | The column key (usually matches the field name) |
| `label` | `{en, dv}` or `string` | ✅ | Display label |
| `lang` | `string[]` | Recommended | Languages this column supports (e.g. `["en", "dv"]`) |
| `type` | `string` | ✅ | Data type: `"string"`, `"number"`, `"integer"`, `"boolean"`, `"date"`, `"datetime"`, `"json"` |
| `displayType` | `string` | ✅ | Render type: `"text"`, `"chip"`, `"checkbox"`, `"date"`, `"select"` |
| `inputType` | `string` | Recommended | Form input type: `"textField"`, `"numberField"`, `"checkbox"`, `"datepicker"`, `"select"`, `"textarea"`, `"search"` |
| `formField` | `bool` | Recommended | Whether this column appears in the form (`true`/`false`) |
| `sortable` | `bool` | Optional | Whether the column is sortable |
| `inlineEditable` | `bool` | Optional | Allow inline editing in the table |
| `filterable` | `object` | Optional | Legacy filter config (prefer `select` key) |
| `chip` | `object` | Optional | Chip display config (when `displayType` is `"chip"`) |
| `select` | `object` | Optional | Select config (when `inputType` is `"select"`) |
| `relationLabel` | `{en, dv}` or `string` | Optional | Label override for relation columns |

### Select Config (in apiSchema)

For `inputType: "select"`, provide a `select` key:

```php
'select' => [
    'type' => 'select',
    'label' => ['en' => 'Sender Type', 'dv' => 'ފޮނުވީ'],
    'mode' => 'self',           // 'self' for static items, 'relation' for API fetch
    'items' => [                // Only for mode: self
        ['itemTitleEn' => 'Person', 'itemTitleDv' => 'ފަރުދެއް', 'itemValue' => 'person'],
        ['itemTitleEn' => 'Organization', 'itemTitleDv' => 'މުއައްސަސާެއް', 'itemValue' => 'organization'],
    ],
    'itemTitle' => ['en' => 'itemTitleEn', 'dv' => 'itemTitleDv'],
    'itemValue' => 'itemValue',
    'relationship' => 'country',  // Only for mode: relation
],
```

| Key | Type | Description |
|---|---|---|
| `mode` | `string` | `"self"` (static items) or `"relation"` (fetch from related model API) |
| `items` | `array` | Static items array (for `mode: "self"`) |
| `itemTitle` | `{en, dv}` or `string` | Key in each item to use as display text |
| `itemValue` | `string` | Key in each item to use as the value |
| `relationship` | `string` | Eloquent relationship name (for `mode: "relation"`) |

---

## Package Config (uiapi.php)

Published to `config/uiapi.php`:

```php
return [
    'view_configs_path' => 'app/Services/viewConfigs',
    'route_prefix' => 'api',
    'logging_enabled' => true,
    'allow_custom_component_keys' => true,
    'js_scripts_path' => 'app/Services/jsScripts',
];
```

| Key | Type | Default | Description |
|---|---|---|---|
| `view_configs_path` | `string` | `app/Services/viewConfigs` | Where view config JSON files live |
| `route_prefix` | `string` | `api` | URL prefix for CCS and GAPI routes |
| `logging_enabled` | `bool` | `false` | Enable debug logging in CCS |
| `allow_custom_component_keys` | `bool` | `false` | Allow unknown keys in component overrides to pass through into payloads |
| `js_scripts_path` | `string` | `app/Services/jsScripts` | Where external JS function files live |

---

## Full Example

`app/Services/viewConfigs/cform.json`:

```json
{
  "listView": {
    "components": {
      "table": {
        "columns": ["id", "uuid", "ref_num", "summary", "status", "createdby.first_name_div"],
        "columnCustomizations": {
          "New_ref": {
            "label": { "en": "New Column", "dv": "ކޮލަމް" },
            "columnData": "customeColumnData(item)",
            "order": 3,
            "displayType": "custom"
          },
          "status": {
            "width": "180px",
            "displayType": "chip",
            "chip": {
              "draft": { "size": "sm", "label": { "en": "Draft", "dv": "ޑްރާފްޓް" }, "color": "secondary" },
              "submitted": { "size": "sm", "label": { "en": "Submitted", "dv": "ސަބްމިޓް" }, "color": "primary" }
            },
            "sortable": true,
            "inlineEditable": true
          }
        },
        "functions": {
          "customeColumnData": { "file": "misc.js", "function": "customeColumnData" }
        }
      },
      "form": {
        "createTitle": { "en": "Create Form", "dv": "އައު" },
        "editTitle": { "en": "Edit Form", "dv": "ބަދަލު" },
        "groups": [
          { "name": "Search", "title": { "en": "Search", "dv": "ހޯދާ" }, "numberOfColumns": 2 },
          { "name": "BasicInfo", "condition": "SearchResults", "title": { "en": "Basic Info", "dv": "މަޢުލޫމާތު" }, "numberOfColumns": 3 }
        ],
        "fields": [
          { "key": "nid_pp", "group": "Search", "inputType": "search", "submitUrl": "gapi/person/", "events": { "results": "handleSearchResults" } },
          { "key": "ref_num", "group": "BasicInfo" },
          { "key": "summary", "inputType": "textarea", "group": "BasicInfo", "columnSpan": 3 }
        ],
        "variables": { "SearchResults": false },
        "functions": { "handleSearchResults": { "file": "misc.js", "function": "handleSearchResults" } }
      },
      "toolbar": {
        "title": { "en": "Forms", "dv": "ފޯމުތައް" },
        "filters": ["status"],
        "buttons": ["search", "clear"]
      },
      "filterSection": {
        "filters": ["status"],
        "buttons": ["submit", "clear"]
      },
      "meta": {}
    },
    "columns": ["id", "uuid", "ref_num", "status", "created_at", "createdby.first_name_div"],
    "columnCustomizations": {
      "status": { "width": "180px", "displayType": "chip", "sortable": true },
      "created_at": { "hidden": false, "width": "180px", "displayType": "date", "sortable": true }
    },
    "filters": ["status", "sender_type", "submitted_at", "ref_num"],
    "per_page": 15,
    "lang": ["en", "dv"]
  }
}
```

---

## Quick Checklist

When creating a new view config:

- [ ] File named correctly (lowercase, no separators, `.json`)
- [ ] At least one view block key (e.g. `listView`)
- [ ] `components` object with at least `table` declared
- [ ] `columns` array with the fields to display
- [ ] `lang` array with supported languages
- [ ] `per_page` set (or accept default 25)
- [ ] `columnCustomizations` for any display overrides (chips, dates, widths)
- [ ] `filters` array if you want filter UI
- [ ] `functions` block if referencing external JS
- [ ] All `{dv}` labels filled in (not left as "TODO")

### Internal Keys (stripped from payload)

These keys are consumed by CCS and **never** appear in the final API response:

- `columnCustomizations`
- `columns`
- `per_page`
- `filters`
- `lang`
