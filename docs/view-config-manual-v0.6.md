<div align="center">

# View Config Manual

<p><strong>A comprehensive guide to authoring view config JSON for the UiApi package.</strong></p>

<p>
    <img alt="Manual Version v0.6" src="https://img.shields.io/badge/Manual-v0.6-F59E0B?style=for-the-badge">
</p>



</div>

> These files drive the Component Config Service (CCS), which assembles UI payloads including headers, form fields, filters, data links, toolbar settings, and more from a single JSON file per model.

## Quick Jump

<table>
    <tr>
        <td><a href="#1-how-it-works"><strong>01.</strong> How It Works</a></td>
    </tr>
    <tr>
        <td><a href="#2-file-naming--location"><strong>02.</strong> File Naming &amp; Location</a></td>
    </tr>
    <tr>
        <td><a href="#3-architecture-views--components"><strong>03.</strong> Views &amp; Components</a></td>
    </tr>
    <tr>
        <td><a href="#4-the-view-block"><strong>04.</strong> The View Block</a></td>
    </tr>
    <tr>
        <td><a href="#5-component-table"><strong>05.</strong> Component: table</a></td>
    </tr>
    <tr>
        <td><a href="#6-component-form"><strong>06.</strong> Component: form</a></td>
    </tr>
    <tr>
        <td><a href="#7-component-toolbar"><strong>07.</strong> Component: toolbar</a></td>
    </tr>
    <tr>
        <td><a href="#8-component-filtersection"><strong>08.</strong> Component: filterSection</a></td>
    </tr>
    <tr>
        <td><a href="#9-component-card"><strong>09.</strong> Component: card</a></td>
    </tr>
    <tr>
        <td><a href="#10-component-meta"><strong>10.</strong> Component: meta</a></td>
    </tr>
    <tr>
        <td><a href="#11-column-customizations"><strong>11.</strong> Column Customizations</a></td>
    </tr>
    <tr>
        <td><a href="#12-the-override-mechanism"><strong>12.</strong> The Override Mechanism</a></td>
    </tr>
    <tr>
        <td><a href="#13-external-js-functions"><strong>13.</strong> External JS Functions</a></td>
    </tr>
    <tr>
        <td><a href="#14-localization"><strong>14.</strong> Localization</a></td>
    </tr>
    <tr>
        <td><a href="#15-nomodel-mode"><strong>15.</strong> noModel Mode</a></td>
    </tr>
    <tr>
        <td><a href="#16-root-level-columnsschema"><strong>16.</strong> Root-Level columnsSchema</a></td>
    </tr>
    <tr>
        <td><a href="#17-validation-rules"><strong>17.</strong> Validation Rules</a></td>
    </tr>
    <tr>
        <td><a href="#18-examples"><strong>18.</strong> Examples</a></td>
    </tr>
    <tr>
        <td><a href="#19-quick-checklist"><strong>19.</strong> Quick Checklist</a></td>
    </tr>
</table>

## 1. How It Works

CCS reads your view config JSON, merges it with the model's `apiSchema()` and built-in component config templates, and returns a fully assembled UI payload.

<details open>
<summary><strong>Workflow Snapshot</strong></summary>

```
View Config JSON   --------\
Model apiSchema()  --------+--> CCS --> JSON Response
Component Templates -------/
                             (componentSettings, headers, filters, etc.)
                             (table.json, card.json, form.json, etc.)
```

</details>

## 2. File Naming & Location

### 📍 Location

View config files live in the directory specified by `uiapi.view_configs_path` in `config/uiapi.php`. Default: `app/Services/viewConfigs/`.

### 🏷️ Naming Rules

The filename must be the **lowercased, no-separator** version of the model name, with a `.json` extension.

| Model Name | Filename |
|---|---|
| `Person` | `person.json` |
| `CForm` | `cform.json` |
| `LegalAid` | `legalaid.json` |
| `CriminalRecord` | `criminalrecord.json` |


### 🧩 noModel Naming

For noModel configs (no backing Eloquent model), the filename is still just a lowercase identifier. There is no naming prefix requirement — just make sure the name is unique so CCS can find it.

## 3. Architecture: Views & Components

A view config JSON has a **two-tier structure**:

1. **View blocks** — Keys containing `"View"` in the name (e.g. `listView`, `detailView`). These define *which components* a particular screen uses and configure view-level settings like language support.

2. **Component definitions** — Top-level keys that do NOT contain `"View"` (e.g. `table`, `card`, `form`, `toolbar`, `filterSection`, `meta`). These define the overrides and settings for each individual component.

```json
{
    "listView": {                    // ← View block (references components)
        "components": { ... },
        "lang": ["en", "dv"]
    },
    "table": { ... },               // ← Component definition (overrides table.json template)
    "card": { ... },                // ← Component definition (overrides card.json template)
    "form": { ... },                // ← Component definition (overrides form.json template)
    "toolbar": { ... },             // ← Component definition
    "filterSection": { ... },       // ← Component definition
    "meta": { ... }                 // ← Component definition
}
```

CCS distinguishes views from components by checking if the key contains `"View"`. This means:
- `listView`, `detailView`, `listView2` — all treated as views.
- `table`, `card`, `form`, `toolbar`, `filterSection`, `meta` — all treated as component definitions.

### Why This Separation?

This two-tier approach allows:
- **Reuse** — Multiple views can reference the same component definitions.
- **Cross-model references** — A view can reference a component from a different model's config.
- **Clean separation** — View-level concerns (language, which components to show) are separate from component-level concerns (columns, customizations, form fields).

## 4. The View Block

A view block declares which components a particular screen uses and provides view-level settings.

### Structure

```json
"listView": {
    "components": {
        "table": "CForm/table",
        "card": "CForm/card",
        "form": "CForm/form",
        "toolbar": "CForm/toolbar",
        "filterSection": "CForm/filterSection",
        "meta": "CForm/meta"
    },
    "lang": ["en", "dv"]
}
```

### `components` (required)

A map of **alias → reference**. The alias is the component role name (`table`, `card`, `form`, etc.) and the reference tells CCS where to find the component definition.

**Reference format:**

| Format | Meaning | Example |
|---|---|---|
| `"ModelName/componentKey"` | Self-reference (same model) or cross-model reference | `"CForm/table"` |
| `"componentKey"` | Direct reference to a root-level key in the same file | `"table"` |


### `lang` (required on view or component)

Array of supported language codes. CCS validates the request `lang` parameter against this list. If the requested language isn't in the array, CCS returns an empty response.

```json
"lang": ["en", "dv"]
```

**Where to define `lang`:** You can define it on the view block, on individual component definitions, or both. If a component definition doesn't have `lang`, it inherits it from the view that references it. If a component has its own `lang`, that takes precedence.

**Supported codes:** `"en"` (English), `"dv"` (Dhivehi).

### Multiple Views

You can define multiple views in one file:

```json
{
    "listView": {
        "components": {
            "table": "CForm/table",
            "toolbar": "CForm/toolbar"
        },
        "lang": ["en", "dv"]
    },
    "detailView": {
        "components": {
            "form": "CForm/form",
            "meta": "CForm/meta"
        },
        "lang": ["en"]
    },
    "table": { ... },
    "form": { ... },
    "toolbar": { ... },
    "meta": { ... }
}
```

## 5. Component: table

The table component drives the data table — headers, pagination, data link, action buttons, delete confirmations, and column display.

<details open>
<summary><strong>Base Template</strong> <code>ComponentConfigs/table.json</code></summary>

```json
{
    "table": {
        "headers": "on",
        "filters": "off",
        "pagination": "on",
        "datalink": "on",
        "button": ["create", "edit", "delete"],
        "showActions": true,
        "actions": {
            "showView": true,
            "showEdit": true,
            "showDelete": true
        }
    }
}
```

</details>

The base template defines the *skeleton*. Each key uses a **tri-state** pattern:

| Value | Behavior |
|---|---|
| `"on"` | CCS auto-generates this section from the model's schema |
| `"off"` | Section is omitted from the payload |
| Any other value | Passed through as-is |

So `"headers": "on"` means CCS will automatically build the headers array from `apiSchema()` columns + any column customizations you define.

### Table Component Keys

Define these keys in your root-level `"table": { ... }` block to override or extend the template.

#### `columns` (array of strings)

Which columns to show in the table. Each entry is either a bare column name (`"ref_num"`) or a dot-notation relation token (`"createdby.first_name_div"`).

```json
"columns": [
    "id",
    "uuid",
    "ref_num",
    "summary",
    "status",
    "createdby.first_name_div"
]
```

**Dot notation:** `"relation.field"` tells CCS to:
1. Load the related model's `apiSchema()` to get header metadata.
2. Automatically add the relation to the `with` parameter in the data link.

If `columns` is omitted, CCS uses all non-hidden columns from `apiSchema()`.

#### `columnCustomizations` (object)

Overrides for how individual columns are displayed. See [Section 11: Column Customizations](#11-column-customizations) for full details.

```json
"columnCustomizations": {
    "ref_num": {
        "width": "auto",
        "align": "center",
        "sortable": true
    },
    "status": {
        "displayType": "chip",
        "chip": { ... },
        "sortable": true,
        "inlineEditable": true
    }
}
```

#### `per_page` (integer)

Default number of rows per page. Overridable at request time via query parameter.

```json
"per_page": 15
```

Default if omitted: `25`.

#### `sort` (string)

Default sort order applied to the auto-generated `datalink` URL. Uses the same format as the GAPI `?sort=` query parameter: a comma-separated list of column names, where a `-` prefix means descending.

```json
"sort": "-updated_at,created_at"
```

This appends `&sort=-updated_at,created_at` to the generated datalink, so the initial data fetch returns pre-sorted results. If omitted, no `sort` parameter is added and the API returns results in default database order.

> **Note:** This key is consumed internally by CCS — it is not passed through to the output payload. It only affects the `datalink` URL.

#### `actions` (object)

Controls which CRUD action buttons appear for each row and under what conditions.

##### Visibility Toggles

Use `showView`, `showEdit`, and `showDelete` to globally enable or disable each action button.

```json
"actions": {
    "showView": false,
    "showEdit": true,
    "showDelete": true
}
```

Values defined here are **merged** with the template defaults. So to hide the view button, you only need to set `"showView": false` — the others remain as-is.

##### Per-Row Conditions

Use `viewCondition`, `editCondition`, and `deleteCondition` to control action button visibility **per row** based on the row's data. Each condition is a JavaScript expression string that receives the current row as `item`. The button is shown when the expression evaluates to truthy.

| Key | Type | Description |
|---|---|---|
| `showView` | `boolean` | Globally show/hide the view button (default: `true`) |
| `showEdit` | `boolean` | Globally show/hide the edit button (default: `true`) |
| `showDelete` | `boolean` | Globally show/hide the delete button (default: `true`) |
| `viewCondition` | `string` | JS expression — view button shown only when truthy |
| `editCondition` | `string` | JS expression — edit button shown only when truthy |
| `deleteCondition` | `string` | JS expression — delete button shown only when truthy |

> **How it works:** Both the toggle and the condition must pass for a button to appear. For example, if `showDelete` is `true` but `deleteCondition` evaluates to `false` for a given row, the delete button is hidden for that row. If a condition is not set, it defaults to `true` (always shown, as long as the toggle is enabled).

**Simple condition** — only allow deleting draft records:

```json
"actions": {
    "showView": true,
    "showEdit": true,
    "showDelete": true,
    "deleteCondition": "item.status === 'draft'"
}
```

**Multiple conditions** — edit only drafts, delete only drafts created by the current user:

```json
"actions": {
    "showView": true,
    "showEdit": true,
    "showDelete": true,
    "editCondition": "item.status === 'draft'",
    "deleteCondition": "item.status === 'draft' && item.created_by === item.current_user_id"
}
```

> **Note:** Conditions are evaluated on the frontend using the row's data. Any field available in the row object can be referenced via `item.fieldName`. If the expression throws an error (e.g., referencing a non-existent field), the button defaults to **shown**.

#### `delete` (object)

Configuration for the delete confirmation dialog.

```json
"delete": {
    "title": {
        "en": "Confirm Deletion",
        "dv": "މުދައްދު ނިންމު"
    },
    "subtitle": {
        "en": "Are you sure you want to delete this item?",
        "dv": "..."
    },
    "message": {
        "ref_num": { "en": "ref", "dv": "އައިޑީ" },
        "uuid": { "en": "UUID", "dv": "ޔުއިއުޑީ" }
    },
    "crudLink": "gapi/CForm"
}
```

| Key | Type | Description |
|---|---|---|
| `title` | `{en, dv}` | Dialog title |
| `subtitle` | `{en, dv}` | Confirmation message |
| `message` | `object` | Map of field → label pairs shown as summary in the dialog |
| `crudLink` | `string` | DELETE endpoint (optional; auto-generated from model name if omitted) |

#### `functions` (object)

External JS function definitions for custom column data. See [Section 13: External JS Functions](#13-external-js-functions).

```json
"functions": {
    "customeColumnData": {
        "file": "misc.js",
        "function": "customeColumnData"
    }
}
```

#### `filters` (array of strings)

Column keys to expose as filters within the table component. In the base template, table filters are `"off"` by default — this key only takes effect when the template directive is changed.

#### `lang` (array of strings)

Override the language support for this component specifically. If omitted, inherited from the view block.

## 6. Component: form

The form component drives create/edit forms — field layout, validation, groups, search fields, and conditional visibility.

<details open>
<summary><strong>Base Template</strong> <code>ComponentConfigs/form.json</code></summary>

```json
{
    "form": {
        "fields": "on",
        "rules": "on",
        "createLink": "off",
        "crudLink": "on",
        "updateLink": "on",
        "numberOfColumns": 3,
        "button": ["create", "edit"]
    }
}
```

</details>

When `"fields": "on"`, CCS auto-generates form fields from columns that have `"formField": true` in `apiSchema()`. When you provide explicit `fields` in your form config, they override and restrict the auto-generated set.

### Form Component Keys

#### `createTitle` / `editTitle` ({en, dv})

Titles displayed at the top of the form in create and edit modes.

```json
"createTitle": { "en": "Create Form", "dv": "އައު" },
"editTitle": { "en": "Edit Form", "dv": "ބަދަލު" }
```

#### `banner` (object)

An optional informational banner shown at the top of the form.

```json
"banner": {
    "title": { "en": "Form Details", "dv": "..." },
    "message": { "en": "Please fill out the form below...", "dv": "..." },
    "color": "info",
    "icon": "information-circle",
    "variant": "tonal"
}
```

| Key | Type | Description |
|---|---|---|
| `title` | `{en, dv}` | Banner heading |
| `message` | `{en, dv}` | Banner body text |
| `color` | `string` | Color token: `"info"`, `"success"`, `"warning"`, `"error"` |
| `icon` | `string` | Icon name |
| `variant` | `string` | Display variant: `"tonal"`, `"flat"`, `"outlined"`, etc. |

#### `groups` (array of objects)

Forms are organized into named **groups** (sections). Each group gets its own heading and column layout.

```json
"groups": [
    {
        "name": "Search",
        "title": { "en": "Search", "dv": "ހޯދާ" },
        "numberOfColumns": 2
    },
    {
        "name": "BasicInfo",
        "condition": "SearchResults || formType === 'edit'",
        "title": { "en": "Basic Information", "dv": "މަޢުލޫމާތު" },
        "numberOfColumns": 3
    },
    {
        "name": "Submission",
        "condition": "SearchResults || formType === 'edit'",
        "title": { "en": "Submission", "dv": "އިތުރު ތަފްޞީލް" },
        "numberOfColumns": 2
    }
]
```

| Key | Type | Required | Description |
|---|---|---|---|
| `name` | `string` | Yes | Unique identifier. Fields reference this via their `group` key. Must be unique across all groups. |
| `title` | `{en, dv}` | Yes | Section heading. Should be localized. |
| `numberOfColumns` | `integer` | No | Grid columns for this group (overrides the form-level `numberOfColumns`). |
| `condition` | `string` | No | A JavaScript expression referencing variables from `variables`. The group is only visible when this evaluates to truthy. |

#### `fields` (array of objects)

When you provide `fields`, you are **overriding** the auto-generated form fields. CCS merges your field overrides with the schema-generated fields by matching on the `key` property. Fields you list are included; fields you don't list may be excluded (depending on how the override merge works).

Each field object requires at minimum a `key`:

```json
"fields": [
    {
        "key": "nid_pp",
        "group": "Search",
        "disabled": "mode == 'edit' ? true : false",
        "columnSpan": 2,
        "inputType": "search",
        "submitUrl": "gapi/person/",
        "submitMethod": "get",
        "NotDbField": true,
        "lang": "en",
        "submitParam": "lang=${language}&filter",
        "events": {
            "results": "handleSearchResults"
        }
    },
    {
        "key": "ref_num",
        "disabled": "mode == 'edit' ? true : false",
        "group": "BasicInfo",
        "rules": "[rules.required]"
    },
    { "key": "client_ref_number", "group": "BasicInfo" },
    {
        "key": "summary",
        "inputType": "textarea",
        "group": "BasicInfo",
        "columnSpan": 3,
        "hidden": false
    },
    {
        "key": "entry_type",
        "group": "Submission",
        "inputType": "select",
        "mode": "url",
        "url": "http://example.com/api/entry-types",
        "itemTitle": "itemTitleDv",
        "itemValue": "itemValue"
    },
    {
        "key": "submitted_at",
        "group": "Submission",
        "format": "dd/MM/yyyy"
    },
    {
        "key": "remarks",
        "dataKey": "url",
        "label": { "en": "Upload File", "dv": "ފައިލް އަންނަނީ" },
        "inputType": "file",
        "columnSpan": 3,
        "accept": "image/*,application/pdf,.doc,.docx",
        "multiple": true,
        "maxSizeMB": 200,
        "uploadUrl": "https://uiapi.pgo.mv/api/upload"
    }
]
```

##### Field Keys Reference

<details open>
<summary><strong>Field Keys Reference</strong></summary>

| Key | Type | Required | Description |
|---|---|---|---|
| `key` | `string` | Yes | Column name. Must match an `apiSchema()` key (unless it's a non-DB field). |
| `group` | `string` | Yes | Which group this field belongs to. Must match a `groups[].name`. |
| `inputType` | `string` | No | Override the auto-detected input type. See [Input Types](#input-types). |
| `label` | `{en, dv}` | No | Override the schema label. |
| `columnSpan` | `integer` | No | Number of grid columns to span (default: 1). |
| `hidden` | `boolean` | No | Hide the field from the UI. |
| `disabled` | `string` or `boolean` | No | Disable condition. String values are JS expressions evaluated by the frontend. |
| `placeholder` | `string` | No | Placeholder text for the input. |
| `format` | `string` | No | Display format (e.g. `"dd/MM/yyyy"` for date fields). |
| `rules` | `string` | No | Frontend validation rules expression (e.g. `"[rules.required]"`). |
| `condition` | `string` | No | JS expression for conditional visibility (like group condition). |
| `color` | `string` | No | Color token for certain input types (e.g. checkbox). |
| `NotDbField` | `boolean` | No | `true` if this field does not map to a database column. |
| `lang` | `string` | No | Restrict this field to a specific language (`"en"` or `"dv"`). |
| `enabledLang` | `string` or `array` | No | Languages for which this field should be included. If omitted, the field is included for all languages. See [enabledLang — Per-Field Language Filtering](#enabledlang--per-field-language-filtering). |
| `events` | `object` | No | Map of event name → function name. See [Functions & Events](#functions--events). |

</details>

##### Input Types

<details open>
<summary><strong>Input Types</strong></summary>

| `inputType` Value | Description | Extra Keys Required |
|---|---|---|
| `"text"` / `"textField"` | Standard text input | — |
| `"textarea"` | Multi-line text input | — |
| `"number"` / `"numberField"` | Numeric input | — |
| `"checkbox"` | Boolean checkbox | — |
| `"date"` / `"datepicker"` | Date picker | `format` (optional) |
| `"select"` | Dropdown select | `mode`, `items`/`url`, `itemTitle`, `itemValue` (see [Select in Forms](#select-in-forms)) |
| `"search"` | Search-as-you-type field | `submitUrl` (required), `submitMethod`, `submitParam`, `events` |
| `"label"` | Read-only display — renders the field's value as static text instead of an editable input | — |
| `"file"` | File upload | `uploadUrl` (required), `accept`, `multiple`, `maxSizeMB`, `uploadOnSelect`, `dataKey`, `params` (see [File Upload Fields](#file-upload-fields)) |

**Auto-detection:** If you omit `inputType`, CCS derives it from the column's `type` in `apiSchema()`:

| Schema `type` | Default `inputType` |
|---|---|
| `string` | `textField` |
| `number` | `numberField` |
| `boolean` | `checkbox` |
| `date` | `datepicker` |

</details>

##### Select in Forms

When `inputType` is `"select"`, you configure the dropdown directly in the field object:

```json
{
    "key": "entry_type",
    "group": "Submission",
    "inputType": "select",
    "mode": "url",
    "url": "http://example.com/api/entry-types",
    "itemTitle": "itemTitleDv",
    "itemValue": "itemValue"
}
```

Or using self mode with static items:

```json
{
    "key": "status",
    "group": "Submission",
    "inputType": "select",
    "mode": "self",
    "items": [
        { "itemTitleEn": "Draft", "itemTitleDv": "ޑްރާފްޓް", "itemValue": "draft" },
        { "itemTitleEn": "Submitted", "itemTitleDv": "ސަބްމިޓް", "itemValue": "submitted" }
    ],
    "itemTitle": "itemTitleEn",
    "itemValue": "itemValue"
}
```

> **Note:** Select configuration for form fields can also come from the model's `apiSchema()` (via the `select` or `filterable` key on the column definition). The viewConfig field-level select config takes precedence.

##### Search Fields

Search fields (`"inputType": "search"`) allow users to search an external endpoint and populate form data from the results.

```json
{
    "key": "nid_pp",
    "group": "Search",
    "inputType": "search",
    "submitUrl": "gapi/person/",
    "submitMethod": "get",
    "submitParam": "lang=${language}&filter",
    "events": {
        "results": "handleSearchResults"
    }
}
```

| Key | Type | Required | Description |
|---|---|---|---|
| `submitUrl` | `string` | Yes | The API endpoint to send search queries to. |
| `submitMethod` | `string` | No | HTTP method (`"get"` or `"post"`). Default: `"get"`. |
| `submitParam` | `string` | No | Query parameter template. Supports `${language}` interpolation. |
| `events.results` | `string` | No | Function name to handle the search results. Must be defined in `functions`. |

##### File Upload Fields

File upload fields (`"inputType": "file"`) render a drag-and-drop upload zone with file selection, validation, and server upload. The `uploadUrl` key is required — without it, users can select files but the component has no endpoint to send them to.

**Minimal example:**

```json
{
    "key": "remarks",
    "group": "Documents",
    "inputType": "file",
    "uploadUrl": "https://uiapi.pgo.mv/api/upload"
}
```

**Full example with all options:**

```json
{
    "key": "remarks",
    "dataKey": "url",
    "label": { "en": "Upload File", "dv": "ފައިލް އަންނަނީ" },
    "group": "Documents",
    "inputType": "file",
    "columnSpan": 3,
    "accept": "image/*,application/pdf,.doc,.docx",
    "multiple": true,
    "maxSizeMB": 200,
    "uploadUrl": "https://uiapi.pgo.mv/api/upload",
    "uploadOnSelect": false,
    "params": { "model": "CForm", "field": "remarks" }
}
```

| Key | Type | Required | Description |
|---|---|---|---|
| `key` | `string` | Yes | Database column name this field maps to. |
| `uploadUrl` | `string` | Yes | The endpoint URL to POST files to. Without this, file selection works but uploading is disabled. |
| `accept` | `string` | No | Comma-separated list of allowed file types. Supports MIME types (`image/*`, `application/pdf`), MIME subtypes (`image/png`), and extensions (`.doc`, `.docx`). If omitted, all file types are accepted. |
| `multiple` | `boolean` | No | Allow selecting multiple files. Default: `true`. |
| `maxSizeMB` | `number` | No | Maximum file size in megabytes. Files exceeding this are rejected client-side with an error message. Default: `2`. |
| `uploadOnSelect` | `boolean` | No | When `true`, files are uploaded immediately after selection. When `false` (default), a manual "Upload" button is shown below the file list. |
| `dataKey` | `string` | No | The property name to read existing uploaded files from when the form fetches record data (e.g., in edit mode). If omitted, defaults to the `key` value. |
| `params` | `string`, `array`, or `object` | No | Extra metadata sent alongside the files in the upload POST request. See below. |

**How `params` works:**

The `params` value is appended to the `FormData` sent to `uploadUrl`. The format depends on the type:

- **String**: sent as a single `params` field — `formData.append('params', value)`
- **Array**: each item sent as `params[0]`, `params[1]`, etc.
- **Object** (most common): each key-value pair is sent as a separate form field. For example, `"params": { "model": "CForm", "field": "remarks" }` sends `model=CForm` and `field=remarks` alongside the files. This lets the backend know which model and field the upload belongs to.

> **Note:** The `relationId` (the record's ID when editing) is automatically appended to the upload request by the form — you do not need to include it in `params`.

> **Note:** The component also supports viewing and deleting previously uploaded files. When the form loads record data, it reads the file list from the `dataKey` (or `key`) property of the fetched data. Each file object in that list can include a `url` (for viewing) and a `delete_url` (for deletion).

##### Functions & Events

Field-level `events` map event names to function names. The function names must be defined in the form's `functions` block.

```json
"fields": [
    {
        "key": "nid_pp",
        "events": { "results": "handleSearchResults" }
    }
],
"functions": {
    "handleSearchResults": {
        "file": "misc.js",
        "function": "handleSearchResults"
    }
}
```

The validator enforces that every event handler name exists in the `functions` block.

##### enabledLang — Per-Field Language Filtering

The `enabledLang` property controls which languages a form field appears for. When the CCS endpoint is called with a `lang` query parameter, fields are filtered **server-side** — only fields whose `enabledLang` includes the requested language are returned in the payload.

**Behavior:**

| `enabledLang` value | Field included when `lang=en` | Field included when `lang=dv` |
|---|---|---|
| Not set (omitted) | Yes | Yes |
| `"dv"` | No | Yes |
| `"en"` | No | No (only `en`) |
| `["en", "dv"]` | Yes | Yes |
| `["dv"]` | No | Yes |

> **Key point:** If `enabledLang` is omitted, the field is always included regardless of the requested language. This makes it backwards-compatible — existing configs without `enabledLang` behave exactly as before.

**String form** — field appears only for a single language:

```json
{
    "key": "qazziyya_number",
    "group": "BasicInfo",
    "rules": "rules.nidwc(formData.summary)",
    "enabledLang": "dv"
}
```

**Array form** — field appears for multiple (but not necessarily all) languages:

```json
{
    "key": "client_ref_number",
    "group": "BasicInfo",
    "inputType": "label",
    "enabledLang": ["dv", "en"]
}
```

> **Note:** `enabledLang` is stripped from the CCS response — it is not sent to the frontend. The filtering happens entirely server-side.

> **Scope:** `enabledLang` applies to form fields only. Table columns and headers are not affected — they continue to use the existing `lang` filtering from `apiSchema()`.

#### `variables` (object)

Reactive variables used for conditional visibility of groups and fields. The frontend manages their state; the view config just declares initial values.

```json
"variables": {
    "draftingEnabled": true,
    "SearchResults": true
}
```

Referenced by `condition` in groups:

```json
{
    "name": "BasicInfo",
    "condition": "SearchResults || formType === 'edit'"
}
```

#### `functions` (object)

External JavaScript functions. See [Section 13](#13-external-js-functions) for full syntax.

#### `lang` (array)

Override language support for this component. If omitted, inherited from the view.

## 7. Component: toolbar

The toolbar component drives the top toolbar — title, search/filter buttons, and quick filters.

<details open>
<summary><strong>Base Template</strong> <code>ComponentConfigs/toolbar.json</code></summary>

```json
{
    "toolbar": {
        "filters": "on",
        "searchAll": "on",
        "buttons": [
            {
                "type": "search",
                "color": "primary",
                "icon": "magnifying-glass",
                "variant": "contained"
            },
            {
                "type": "Clear",
                "label": "Clear",
                "color": "secondary"
            }
        ]
    }
}
```

</details>

### Toolbar Component Keys

#### `title` ({en, dv})

The toolbar heading.

```json
"title": { "en": "Forms", "dv": "ސަރަހައްދު" }
```

#### `filters` (array of strings)

Column keys to show as quick filter inputs in the toolbar. CCS builds filter metadata only for columns listed here.

```json
"filters": ["status", "search"]
```

If omitted and the template has `"filters": "on"`, all filterable columns from `apiSchema()` are included.

#### `buttons` (array of strings)

Restricts the template's button definitions to only those matching the listed types. This is a **filter operation** — it doesn't add new buttons, it selects from the template's defaults.

```json
"buttons": ["search", "clear"]
```

CCS matches by the `type` key in each template button object. So `"search"` matches `{"type": "search", ...}` and `"clear"` matches `{"type": "Clear", ...}` (case-insensitive comparison).

#### `columnCustomizations` (object)

Override column presentation specifically for toolbar filters. For example, customizing how the search filter appears:

```json
"columnCustomizations": {
    "search": {
        "key": "ref_num",
        "label": { "en": "Search by ID", "dv": "އައިޑީ އިން ހޯދާ" },
        "color": "primary",
        "prependIcon": "search"
    }
}
```

#### `lang` (array)

Override language support.

## 8. Component: filterSection

The filter section component displays an expandable filter panel with column-based filters and action buttons.

<details open>
<summary><strong>Base Template</strong> <code>ComponentConfigs/filterSection.json</code></summary>

```json
{
    "filterSection": {
        "buttons": [
            {
                "type": "submit",
                "color": "primary",
                "prependIcon": "magnifying-glass",
                "variant": "contained"
            },
            {
                "type": "Clear",
                "label": "x",
                "appendIcon": "funnel",
                "color": "secondary"
            },
            {
                "type": "Close",
                "prependIcon": "x-mark",
                "label": "Close",
                "color": "gray"
            }
        ],
        "filters": "on"
    }
}
```

</details>

### FilterSection Component Keys

#### `filters` (array of strings)

Column keys to expose as filter fields in the filter section.

```json
"filters": ["status"]
```

CCS builds filter metadata (type, key, label, items/url) from the column's `apiSchema()` definition (or from `columnCustomizations` in the filterSection).

#### `buttons` (array of strings)

Filter the template buttons to only the listed types.

```json
"buttons": ["submit", "clear"]
```

#### `columnCustomizations` (object)

Override or extend column configuration *specifically* for the filter section. This is commonly used to configure select dropdowns for filter fields.

```json
"columnCustomizations": {
    "status": {
        "width": "180px",
        "displayType": "chip",
        "sortable": true,
        "inlineEditable": true,
        "inputType": "select",
        "select": {
            "type": "select",
            "mode": "self",
            "items": [
                {
                    "itemTitleEn": "Draft",
                    "itemTitleDv": "ޑްރާފްޓް",
                    "itemValue": "draft"
                },
                {
                    "itemTitleEn": "Submitted",
                    "itemTitleDv": "ސަބްމިޓްކުރެވިފަ",
                    "itemValue": "submitted"
                }
            ]
        }
    }
}
```

The `select` configuration follows the same rules as `apiSchema()` select configs. See `select` modes below.

##### Select Modes in Filters

| Mode | Description | Required Keys |
|---|---|---|
| `"self"` | Static items defined inline | `items` (array of objects) |
| `"url"` | Fetch options from a custom URL | `url` (string) |
| `"relation"` | Auto-generate URL from an Eloquent relationship | `relationship` (string, optional — CCS can guess from column name) |

**Self mode example:**
```json
"select": {
    "mode": "self",
    "items": [
        { "itemTitleEn": "Draft", "itemTitleDv": "ޑްރާފްޓް", "itemValue": "draft" }
    ]
}
```

**URL mode example:**
```json
"select": {
    "mode": "url",
    "url": "http://example.com/api/options"
}
```

**Relation mode example:**
```json
"select": {
    "mode": "relation",
    "relationship": "country",
    "itemTitle": { "en": "name_eng", "dv": "name_div" },
    "itemValue": "id"
}
```

#### `lang` (array)

Override language support.

## 9. Component: card

The card component drives card-grid/list layouts where each record renders as a structured card instead of a table row.

<details open>
<summary><strong>Base Template</strong> <code>ComponentConfigs/card.json</code></summary>

```json
{
    "card": {
        "datalink": "on",
        "pagination": "on"
    }
}
```

</details>

Like other components, card follows the same tri-state behavior for template keys:

| Value | Behavior |
|---|---|
| `"on"` | CCS auto-generates the value |
| `"off"` | Key is removed from payload |
| Any other value | Passed through as-is |

### Card Component Keys

#### `columns` (array of strings)

Optional subset of columns for card data fetching and relation auto-`with` behavior (same token rules as table).

```json
"columns": [
    "person.person_name_eng",
    "person.person_name_div",
    "categories",
    "experiences.organization_eng"
]
```

If `columns` is omitted, card falls back to all available schema columns.

#### `per_page` (integer)

Default card page size used when pagination is enabled.

```json
"per_page": 9
```

Default if omitted: `25`.

#### `sort` (string)

Default sort order appended to the auto-generated `datalink` URL. Same format as the GAPI `?sort=` parameter: comma-separated columns, `-` prefix for descending.

```json
"sort": "-updated_at,created_at"
```

If omitted, no `sort` parameter is added to the datalink.

#### `datalink` (`"on"` | `"off"` | `string`)

- `"on"`: CCS builds the data URL automatically.
- `"off"`: no data link is returned.
- `string`: use a custom endpoint directly.

```json
"datalink": "https://uiapi.pgo.mv/api/sample/experts?"
```

#### `pagination` (`"on"` | `"off"` | `object`)

Controls pagination metadata returned for card views.

```json
"pagination": "on"
```

Or provide a custom object payload.

#### `grid` (object)

Frontend grid density settings (passed through).

```json
"grid": { "sm": 1, "md": 2, "lg": 3 }
```

#### `cardLayout` (object)

Defines how each card is rendered using stack blocks.

```json
"cardLayout": {
    "stacks": [
        { "stackType": "field", "key": "person.nid_pp", "tag": "h4", "class": "font-semibold" },
        {
            "stackType": "row",
            "justify": "space-between",
            "fields": [
                { "key": "person.person_name_eng", "lang": "en" },
                { "key": "person.person_name_div", "lang": "dv" }
            ]
        },
        { "stackType": "divider" },
        {
            "stackType": "chips",
            "key": "categories",
            "labelKey": { "en": "label_eng", "dv": "label_div" }
        },
        {
            "stackType": "section",
            "key": "experiences",
            "title": { "en": "Experiences", "dv": "ތަޖުރިބާ" },
            "emptyText": { "en": "No experience information", "dv": "ނެތް" },
            "stacks": [
                {
                    "stackType": "row",
                    "separator": " - ",
                    "fields": [
                        { "key": "organization_eng", "lang": "en" },
                        { "key": "organization", "lang": "dv" }
                    ]
                }
            ]
        }
    ]
}
```

Supported top-level `stackType` values:
- `field`
- `row`
- `divider`
- `chips`
- `section`

##### `field`

Use `field` to render a single value from the current item.

Typical definition:

```json
{
    "stackType": "field",
    "key": "person.nid_pp",
    "tag": "h4",
    "class": "font-semibold",
    "lang": "dv",
    "align": {
        "en": "left",
        "dv": "right"
    }
}
```

Common keys:

| Key | Type | Required | Purpose |
|---|---|---|---|
| `stackType` | `string` | Yes | Must be `"field"` |
| `key` | `string` | Yes | Dot-notated data path to render |
| `tag` | `string` | No | HTML tag/component wrapper, e.g. `"span"`, `"h4"` |
| `class` | `string` | No | CSS utility classes |
| `lang` | `string` or `array` | No | Restrict display to one language or a set of languages |
| `align` | `string` or `{en, dv}` | No | Text alignment used by the frontend |

##### `row`

Use `row` to render multiple inline items on one line. A row can mix simple field items and action buttons.

Typical definition:

```json
{
    "stackType": "row",
    "justify": "space-between",
    "align": "center",
    "separator": " - ",
    "fields": [
        { "key": "person.person_name_eng", "lang": "en" },
        { "key": "person.person_name_div", "lang": "dv" },
        {
            "stackType": "button",
            "label": { "en": "View", "dv": "އޮބާލާ" },
            "color": "info",
            "size": "xs",
            "linkKey": "/show/CForm/:uuid/:ref_num"
        }
    ]
}
```

Common keys:

| Key | Type | Required | Purpose |
|---|---|---|---|
| `stackType` | `string` | Yes | Must be `"row"` |
| `justify` | `string` | No | Layout behavior such as `"space-between"`, `"center"`, `"start"` |
| `align` | `string` | No | Alignment hint for row contents |
| `separator` | `string` | No | Text inserted between non-first fields |
| `fields` | `array` | Yes | Child items rendered in the row |

Inside `fields`, each item is usually either:
- a simple field object like `{ "key": "person.person_name_eng", "lang": "en" }`
- a button object with `stackType: "button"`

##### `divider`

Use `divider` to add a horizontal break between blocks.

Typical definition:

```json
{
    "stackType": "divider"
}
```

This is the simplest stack type. It normally does not need any other keys.

##### `chips`

Use `chips` when the source value is an array and each item should render as a chip/badge.

Typical definition:

```json
{
    "stackType": "chips",
    "key": "categories",
    "lang": ["en", "dv"],
    "labelKey": {
        "en": "label_eng",
        "dv": "label_div"
    },
    "variant": "tonal",
    "size": "sm",
    "color": "primary"
}
```

Common keys:

| Key | Type | Required | Purpose |
|---|---|---|---|
| `stackType` | `string` | Yes | Must be `"chips"` |
| `key` | `string` | Yes | Array field to iterate, e.g. `categories` |
| `labelKey` | `string` or `{en, dv}` | No | Which property from each chip item to display |
| `variant` | `string` | No | Chip style variant |
| `size` | `string` | No | Chip size |
| `color` | `string` | No | Chip color |
| `lang` | `string` or `array` | No | Language visibility filter |

##### `section`

Use `section` when the source value is an array of nested records and each nested record should render using its own mini-layout.

Typical definition:

```json
{
    "stackType": "section",
    "key": "experiences",
    "title": {
        "en": "Experiences",
        "dv": "ތަޖުރިބާ"
    },
    "bgColor": "bg-teal-50",
    "emptyText": {
        "en": "No experience information",
        "dv": "ނެތް"
    },
    "stacks": [
        {
            "stackType": "row",
            "separator": " - ",
            "fields": [
                { "key": "organization", "lang": "dv" },
                { "key": "designation", "lang": "dv" },
                { "key": "organization_eng", "lang": "en" },
                { "key": "designation_eng", "lang": "en" }
            ]
        }
    ]
}
```

Common keys:

| Key | Type | Required | Purpose |
|---|---|---|---|
| `stackType` | `string` | Yes | Must be `"section"` |
| `key` | `string` | Yes | Array field to iterate, e.g. `experiences` |
| `title` | `{en, dv}` or `string` | No | Section heading |
| `bgColor` | `string` | No | Background styling passed through to the frontend |
| `emptyText` | `{en, dv}` or `string` | No | Message shown when the section array is empty |
| `stacks` | `array` | Yes | Nested layout for each section item |

Important behavior:
- Nested `section.stacks` currently support `field` and `row`.
- Inside a section, nested keys are relative to the section item. For example, if the section `key` is `"experiences"`, then nested fields use keys like `"organization_eng"`, not `"experiences.organization_eng"`.

Supported nested section `stackType` values:
- `field`
- `row`

#### `lang` (array)

Override language support for card specifically. If omitted, inherited from the view.

### Card Stack Schema Inheritance

When stack items reference a `key`, CCS can auto-inherit these attributes from `apiSchema()` / `columnsSchema` if they are missing on the stack item:
- `lang`
- `label`
- `align`

This inheritance also applies to nested `section.stacks` keys by prefixing with the section key path.

### Card-Specific Notes

- Stack/list items with non-matching `lang` are filtered out of the payload for the active request language.
- Keys like `grid`, `cardLayout`, and `scrollHeight` are custom pass-through overrides and require `allow_custom_component_keys = true`.
- A row field with `stackType: "button"` can be used for card actions; use `linkKey` to open a URL from item data.

## 10. Component: meta

The meta component provides metadata — primarily the CRUD link for create/update operations.

<details open>
<summary><strong>Base Template</strong> <code>ComponentConfigs/meta.json</code></summary>

```json
{
    "meta": {
        "crudLink": "on"
    }
}
```

</details>

When `"crudLink": "on"`, CCS auto-generates `gapi/{ModelName}` as the CRUD endpoint.

### Meta Component Keys

#### `crudLink` (string)

Override the auto-generated CRUD link with a custom URL.

```json
"meta": {
    "crudLink": "http://external-api.example.com/api/gapi/CriminalRecordRequest"
}
```

When a custom string is provided (not `"on"` or `"off"`), it's passed through as-is.

#### `lang` (array)

Override language support.

#### Any Custom Keys

The meta component is a good place for arbitrary pass-through metadata that the frontend needs:

```json
"meta": {
    "crudLink": "on",
    "customSetting": "value"
}
```

> **Note:** Custom keys only pass through if `allow_custom_component_keys` is `true` in `config/uiapi.php`.

## 11. Column Customizations

Column customizations override or extend schema-derived header properties. They control how columns appear in the table — width, display type, sorting, inline editing, and more.

### Where to Define

Column customizations can be defined in **two places**:

1. **Inside a component definition** — Applies to that component only.
2. **(noModel only) In `columnCustomizations` within the component block** — Same effect.

When both exist, they are **deep-merged** (component-specific wins on conflict).

<details open>
<summary><strong>Accepted Keys</strong></summary>

| Key | Type | Description |
|---|---|---|
| `title` | `{en, dv}` or `string` | Override the header title |
| `label` | `{en, dv}` or `string` | Override the header label (used for custom columns) |
| `hidden` | `boolean` | Hide/show in table headers |
| `sortable` | `boolean` | Enable/disable column sorting |
| `width` | `string` | Column width (e.g. `"180px"`, `"200px"`, `"auto"`) |
| `align` | `string` | Text alignment (`"center"`, `"left"`, `"right"`) |
| `headerAlign` | `string` | Header text alignment |
| `displayType` | `string` | How the cell renders (see [Display Types](#display-types)) |
| `type` | `string` | Data type hint (`"string"`, `"number"`, `"boolean"`, `"date"`) |
| `inlineEditable` | `boolean` | Allow inline editing in the table |
| `editable` | `boolean` | Alias for `inlineEditable` |
| `displayProps` | `object` | Display configuration (legacy; prefer the display-type-named key) |
| `order` | `integer` | 0-based insertion position for header reordering |
| `inputType` | `string` | Input type for filter usage (e.g. `"select"`) |
| `select` | `object` | Select/dropdown configuration |

</details>

### Display Types

| `displayType` | Description | Requires Config? |
|---|---|---|
| `"text"` | Plain text (default) | No |
| `"chip"` | Colored badge/tag | Yes — `chip` sub-key |
| `"date"` | Formatted date | No |
| `"checkbox"` | Boolean checkbox | No |
| `"select"` | Dropdown display | Yes — `displayProps` or `select` sub-key |
| `"custom"` | Custom rendering via JS function | Yes — `columnData` key |
| `"link"` | Clickable link | No |
| `"image"` | Image display | No |
| `"badge"` | Badge display | No |
| `"html"` | Raw HTML rendering | No |
| `"docButton"` | Document/file viewer button(s) | Yes — `docButton` sub-key |

#### Chip Configuration

When `displayType` is `"chip"`, provide a `chip` object where each key is a possible **field value** and the value is the chip's appearance config:

```json
"status": {
    "displayType": "chip",
    "chip": {
        "draft": {
            "size": "sm",
            "label": { "en": "Draft", "dv": "ޑްރާފްޓް" },
            "color": "secondary",
            "prependIcon": "document-text"
        },
        "submitted": {
            "size": "sm",
            "label": { "en": "Submitted", "dv": "ސަބްމިޓް" },
            "color": "primary",
            "prependIcon": "paper-airplane"
        },
        "rejected": {
            "size": "sm",
            "label": { "en": "Rejected", "dv": "ރިޖެކްޓުކުރެވިފަ" },
            "color": "error",
            "prependIcon": "x-circle"
        },
        "accepted": {
            "size": "sm",
            "label": { "en": "Accepted", "dv": "ބަލައިގަނެވިފަ" },
            "color": "success",
            "prependIcon": "check-circle"
        }
    }
}
```

| Chip Option Key | Type | Description |
|---|---|---|
| `size` | `string` | Size variant: `"sm"`, `"md"`, `"lg"` |
| `label` | `{en, dv}` | Display label (auto-resolved to request language) |
| `color` | `string` | Color token: `"primary"`, `"secondary"`, `"success"`, `"error"`, `"warning"` |
| `prependIcon` | `string` | Icon name displayed before the label |

#### Document Button Configuration

When `displayType` is `"docButton"`, the column renders clickable document buttons using the `FileDisplay` component. This is used to display file attachments in a table row — each file gets a button that opens a document viewer when clicked.

The column's data is expected to be a JSON string or array of file objects, where each object has a `url` (for viewing) and optionally a `display_name` or `name` (for the tooltip/label).

```json
"url": {
    "displayType": "docButton",
    "docButton": {
        "displayLabel": false,
        "columnView": false
    },
    "label": {
        "en": "Files",
        "dv": "ފައިލް"
    }
}
```

The `docButton` sub-key configures how the buttons are rendered:

| Key | Type | Default | Description |
|---|---|---|---|
| `displayLabel` | `boolean` | `true` | Show the file name next to the icon. When `false`, only the document icon is shown (file name appears as a tooltip on hover). |
| `columnView` | `boolean` | `false` | Layout direction. When `true`, files are stacked vertically (one per line). When `false`, files are displayed inline (side by side). |
| `color` | `string` | `"primary"` | Button color token (`"primary"`, `"secondary"`, `"success"`, `"error"`, `"warning"`). |
| `variant` | `string` | `"text"` | Button variant (`"contained"`, `"outlined"`, `"text"`, `"tonal"`, `"plain"`). |
| `size` | `string` | `"xs"` | Button size (`"xs"`, `"sm"`, `"md"`, `"lg"`, `"xl"`). |

> **Data format:** The column value should be a JSON-encoded array of file objects. Each file object should have `url` (the file URL for viewing) and either `display_name` or `name` (used for the button label and tooltip). If the column value is an empty array or null, a dash (`-`) is displayed.

**Compact icon-only buttons (good for narrow columns):**

```json
"docButton": {
    "displayLabel": false,
    "columnView": false
}
```

**Labeled buttons stacked vertically (good for showing file names):**

```json
"docButton": {
    "displayLabel": true,
    "columnView": true
}
```

### Custom Columns

You can add entirely **new columns** that don't exist in the model's schema. These appear as extra headers in the table.

```json
"columnCustomizations": {
    "New_ref": {
        "width": "400px",
        "label": { "en": "New Column", "dv": "ޢންވަރު ކޮލަމް" },
        "columnData": "customeColumnData()",
        "order": 3,
        "displayType": "custom"
    }
}
```

| Key | Type | Description |
|---|---|---|
| `label` | `{en, dv}` or `string` | Required for custom columns (otherwise title-cased from key) |
| `columnData` | `string` | JS expression or function call for dynamic cell data |
| `order` | `integer` | Position in the header list |
| `displayType` | `string` | Typically `"custom"` for JS-rendered columns |

The `columnData` value references a function defined in the `functions` block. CCS extracts the function body and inlines it.

### Header Reordering (`order`)

The `order` key sets a **0-based insertion position** for the header:

- Headers without `order` keep their natural sequence.
- Headers with `order` are pulled out, sorted by `order` value, then spliced in at the requested index.
- Out-of-range values are clamped.
- The `order` key is stripped from the final payload.

```json
"columnCustomizations": {
    "status": { "order": 0 },
    "ref_num": { "order": 2 },
    "New_ref": { "label": { "en": "Custom" }, "order": 3, "displayType": "custom" }
}
```

## 12. The Override Mechanism

Understanding how CCS merges your view config overrides with base component templates is essential for advanced usage.

### How It Works

1. CCS loads the **base component template** (e.g. `table.json`).
2. Each template directive is processed (e.g. `"headers": "on"` → builds the headers array).
3. Your component block overrides are applied on top via `applyOverridesToSection()`.

### Override Behaviors by Value Type

| Override Value | What Happens |
|---|---|
| `"off"` (string) | **Removes** the matching key from the payload entirely |
| Other scalar (string, number, bool) | **Replaces** the existing value |
| Array of strings (e.g. `["search", "clear"]`) | **Filters** the template array — keeps only items whose `type`, `key`, `name`, or `label` matches one of the strings (case-insensitive) |
| Array of objects (for `fields`) | **Replaces** — your array becomes the fields list, merged by `key` with schema-generated fields |
| Array of objects (for other keys) | **Merges** — matches existing items by `key`, merges properties; appends unmatched items |
| Object | **Deep-merges** with the existing object |

### Practical Examples

**Filtering buttons:**

Template has three buttons. You only want "search" and "clear":

```json
"buttons": ["search", "clear"]
```

CCS scans the template's button array and keeps only items where `type` matches `"search"` or `"clear"`.

**Removing a section:**

To hide pagination from the table payload:

```json
"pagination": "off"
```

**Adding custom keys:**

To pass arbitrary data to the frontend, set `allow_custom_component_keys: true` in `config/uiapi.php`, then add any key:

```json
"variables": {
    "draftingEnabled": true
}
```

Without `allow_custom_component_keys`, unknown keys are silently ignored.

### Internal Keys (Stripped from Payload)

These keys are consumed by CCS during processing and **never appear** in the final API response:

- `columnCustomizations`
- `columnsSchema`
- `noModel`
- `columns`
- `per_page`
- `filters`
- `lang`

## 13. External JS Functions

Components can reference JavaScript functions stored in external `.js` files. CCS extracts the function body at runtime and inlines it into the payload as a string.

### Configuration

| Setting | Default |
|---|---|
| Config key | `uiapi.js_scripts_path` |
| Default path | `app/Services/jsScripts/` |

### Syntax

Inside any component block, add a `functions` object:

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
| `file` | `string` | Yes | JS filename in the `js_scripts_path` directory |
| `function` | `string` | Yes | Function name to extract (must be a named `function` declaration) |

**Inline string functions** are also supported:

```json
"functions": {
    "simpleHandler": "console.log('hello');"
}
```

### How Extraction Works

CCS reads the JS file, finds `function functionName(...) {`, then uses **brace-counting** to extract everything between the opening `{` and closing `}`. Nested braces are handled correctly.

The extracted body is returned as a single-line string with whitespace trimmed.

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

### Linking Events to Functions

In form fields, the `events` key maps event names to function names. The validator checks that every referenced function name exists in the `functions` block.

```json
"fields": [
    {
        "key": "nid_pp",
        "events": { "results": "handleSearchResults" }
    }
],
"functions": {
    "handleSearchResults": { "file": "misc.js", "function": "handleSearchResults" }
}
```

If you reference a function name in `events` that doesn't exist in `functions`, the validator issues a warning.

## 14. Localization

### Language Selection

CCS resolves the language from the `?lang=` query parameter (default: `"dv"`).

### Localized Values

Any key that accepts `{en, dv}` is collapsed to a single string based on the request language:

```json
"title": { "en": "Forms", "dv": "ފޯމުތައް" }
```

With `?lang=en`, the response contains `"title": "Forms"`.

### Auto-Collapsed Keys

These payload keys are automatically collapsed from `{en, dv}` objects to a single string:

- `createTitle`
- `editTitle`
- `title`
- `label`

### Column Language Filtering

Columns that have a `lang` array in their `apiSchema()` definition are only included when the request language matches:

```json
{
    "first_name_eng": {
        "lang": ["en"]
    }
}
```

This column is excluded when `?lang=dv`. Columns with `"lang": ["en", "dv"]` appear for both languages. Columns without a `lang` array appear for all languages.

### Language Fallback

When resolving localized values, CCS uses this fallback chain:
1. Requested language (e.g. `en`)
2. Opposite language (e.g. `dv`)
3. First available value

## 15. noModel Mode

By default, CCS resolves an Eloquent model from the model name and reads its `apiSchema()` for column definitions. **noModel mode** skips model resolution entirely — you provide the column schema inline in the view config JSON.

### When to Use noModel

- The data comes from an **external API** (not a local database).
- There is **no Eloquent model** for this data.
- You want to display data from a **third-party service**.
- You need a **fully frontend-driven** table/form with no server-side model.

### Enabling noModel

Set `"noModel": true` on the **view block**. CCS automatically propagates it to every component referenced by that view, so you don't need to repeat it on each component:

```json
{
    "listView": {
        "noModel": true,
        "components": {
            "table": "criminalrecord/table",
            "form": "criminalrecord/form",
            "filterSection": "criminalrecord/filterSection",
            "toolbar": "criminalrecord/toolbar",
            "meta": "criminalrecord/meta"
        }
    },
    "table": {
        "lang": ["en", "dv"],
        ...
    },
    "form": {
        "lang": ["en", "dv"],
        ...
    }
}
```

> **noModel propagation:** When a view sets `"noModel": true`, every component it references inherits that flag automatically. You can still override on a per-component basis — set `"noModel": false` on a component to opt it out.

> **Backward compatibility:** Setting `"noModel": true` directly on individual component definitions still works. The view-level flag simply removes the need to repeat it.

### Required: `columnsSchema`

In noModel mode, there is no `apiSchema()` to read from. You must provide a `columnsSchema` object that acts as the replacement. This is required for components that consume column data: **table**, **toolbar**, **filterSection**.

For **card**, `columnsSchema` is optional but recommended when you want stack-level inheritance of `lang`, `label`, or `align`.

```json
"table": {
    "noModel": true,
    "lang": ["en", "dv"],
    "columnsSchema": {
        "id": {
            "hidden": false,
            "key": "id",
            "label": { "dv": "އައިޑީ", "en": "Id" },
            "lang": ["en", "dv"],
            "type": "number",
            "displayType": "text",
            "formField": false,
            "sortable": true
        },
        "ref_num": {
            "hidden": false,
            "key": "ref_num",
            "label": { "dv": "ރެފެރެންސް ނަންބަރު", "en": "Reference Number" },
            "lang": ["en", "dv"],
            "type": "text",
            "displayType": "text",
            "formField": false,
            "sortable": true
        },
        "status": {
            "hidden": false,
            "key": "status",
            "label": { "dv": "ސްޓޭޓަސް", "en": "Status" },
            "lang": ["en", "dv"],
            "type": "text",
            "displayType": "text",
            "formField": false,
            "sortable": true
        }
    },
    "columns": ["id", "ref_num", "status"],
    ...
}
```

The `columnsSchema` entries use the same format as `apiSchema()` column definitions. See the [apiSchema Reference Manual](apiSchema-manual.md) for the full key reference.

### Required: `columns`

Even in noModel mode, you still need a `columns` array to define ordering:

```json
"columns": ["id", "ref_num", "person_name", "permanent_address", "details", "person__id", "status"]
```

### Required: `lang`

In noModel mode, `lang` must be explicitly set on each component (it can't be inherited from the model):

```json
"lang": ["en", "dv"]
```

### Custom `datalink`

In model-backed mode, CCS auto-generates the data link URL. In noModel mode, you provide it manually:

```json
"table": {
    "noModel": true,
    "datalink": "http://external-api.example.com/api/criminal-records/6d5c9d34-f80a-4596-ad22-56ca8ffecef1/requests",
    ...
}
```

This URL is passed through as-is to the frontend.

### Custom `crudLink` in Meta

Similarly, the CRUD link must be manually specified:

```json
"meta": {
    "noModel": true,
    "crudLink": "http://external-api.example.com/api/gapi/CriminalRecordRequest",
    "lang": ["en", "dv"]
}
```

### noModel FilterSection with Inline Schema

When using noModel for the filterSection, you provide `columnsSchema` with the filter columns' definitions directly:

```json
"filterSection": {
    "noModel": true,
    "lang": ["en", "dv"],
    "filters": ["status"],
    "columnsSchema": {
        "status": {
            "key": "status",
            "label": { "dv": "ސްޓޭޓަސް", "en": "Status" },
            "lang": ["en", "dv"],
            "inputType": "select",
            "select": {
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

### noModel Toolbar

Same pattern — provide `columnsSchema` for any filter columns the toolbar needs:

```json
"toolbar": {
    "noModel": true,
    "lang": ["en", "dv"],
    "title": { "en": "Forms", "dv": "އައު ފޯމެއް" },
    "filters": ["status"],
    "buttons": ["search", "clear"],
    "columnsSchema": {
        "status": {
            "key": "status",
            "label": { "dv": "ސްޓޭޓަސް", "en": "Status" },
            "lang": ["en", "dv"],
            "inputType": "select",
            "select": {
                "mode": "self",
                "items": [
                    { "itemTitleEn": "Draft", "itemTitleDv": "ޑްރާފްޓް", "itemValue": "draft" }
                ]
            }
        }
    }
}
```

### noModel Form

The form component in noModel mode doesn't necessarily need `columnsSchema` because fields are defined explicitly. But you still set `noModel: true` and provide `lang`:

```json
"form": {
    "noModel": true,
    "lang": ["en", "dv"],
    "groups": [ ... ],
    "fields": [ ... ],
    "variables": {}
}
```

### Model-Backed vs noModel Comparison

| Aspect | Model-Backed | noModel |
|---|---|---|
| Column definitions | From `apiSchema()` on Eloquent model | From inline `columnsSchema` |
| Data link | Auto-generated `gapi/{Model}?columns=...` | Manual URL string |
| CRUD link | Auto-generated `gapi/{Model}` | Manual URL string |
| `lang` | Can inherit from view | Must be explicit on each component |
| Relation columns | Resolved via Eloquent relationships | Defined directly in `columnsSchema` with dot-notation keys |
| Form fields | Auto-generated from `formField: true` columns | Explicitly declared in `fields` |
| `noModel` flag | Not needed (default `false`) | Set on view (propagates to components) or per-component |
| `columnsSchema` | Not needed | Required on table, toolbar, filterSection; optional for card inheritance (inline or root-level) |

## 16. Root-Level columnsSchema

Instead of repeating the same `columnsSchema` inline on every noModel component, you can define it once as a **root-level key** in the view config and have all components share it.

### Flat Root Schema (Single Schema)

When all components share the same column definitions, define them at the root:

```json
{
    "listView": {
        "noModel": true,
        "components": {
            "table": "criminalrecord/table",
            "toolbar": "criminalrecord/toolbar",
            "filterSection": "criminalrecord/filterSection"
        }
    },
    "columnsSchema": {
        "id": {
            "key": "id",
            "label": { "en": "Id", "dv": "އައިޑީ" },
            "type": "number",
            "sortable": true
        },
        "status": {
            "key": "status",
            "label": { "en": "Status", "dv": "ސްޓޭޓަސް" },
            "type": "text",
            "inputType": "select",
            "select": {
                "mode": "self",
                "items": [
                    { "itemTitleEn": "Draft", "itemTitleDv": "ޑްރާފްޓް", "itemValue": "draft" }
                ]
            }
        }
    },
    "table": {
        "columns": ["id", "status"],
        "lang": ["en", "dv"]
    },
    "toolbar": {
        "filters": ["status"],
        "lang": ["en", "dv"]
    },
    "filterSection": {
        "filters": ["status"],
        "lang": ["en", "dv"]
    }
}
```

CCS automatically resolves each component's `columnsSchema` from the root. Components no longer need `"noModel": true` individually (it's propagated from the view).

### Numbered Root Schema (Multiple Schemas)

When different components need different column definitions, use numeric keys to define multiple schemas:

```json
{
    "columnsSchema": {
        "1": {
            "id": { "key": "id", "label": { "en": "Id" }, "type": "number" },
            "status": { "key": "status", "label": { "en": "Status" }, "type": "text" }
        },
        "2": {
            "status": {
                "key": "status",
                "label": { "en": "Status" },
                "inputType": "select",
                "select": {
                    "mode": "self",
                    "items": [
                        { "itemTitleEn": "Draft", "itemValue": "draft" }
                    ]
                }
            }
        }
    },
    "table": {
        "columnsSchema": 1,
        "columns": ["id", "status"],
        "lang": ["en", "dv"]
    },
    "toolbar": {
        "columnsSchema": 2,
        "filters": ["status"],
        "lang": ["en", "dv"]
    }
}
```

Components reference their schema by number (`"columnsSchema": 1` or `"columnsSchema": "1"` — both accepted).

### Resolution Rules

| Component Has | Root-Level Schema | Result |
|---|---|---|
| Inline array (existing behavior) | Any | **Inline wins** — root is ignored |
| Integer/string reference (e.g. `1`) | Numbered | Resolves to the referenced entry |
| Nothing | Flat (column-name keys) | Falls back to the root schema |
| Nothing | Numbered | Falls back to the **first** numbered entry |
| Nothing | None | Error if component requires columns |

### Detection Logic

CCS determines whether the root schema is flat or numbered by inspecting its keys:
- **Numbered:** Every key is a numeric digit string (`"1"`, `"2"`, ...).
- **Flat:** At least one key is a non-numeric column name (`"id"`, `"status"`, ...).

### Validation

The `ViewConfigValidator` validates root-level schemas:
- Numbered entries must each be a non-empty array of column definitions.
- Integer references in components must point to an existing numbered key.
- The root key `columnsSchema` is excluded from component validation (it's not treated as a component definition).

## 17. Validation Rules

The `ViewConfigValidator` validates your view config JSON and reports errors and warnings. Validation runs automatically when `debug_level` is 2+ in `config/uiapi.php`.

<details open>
<summary><strong>Errors (Block the Request)</strong></summary>

| Rule | What It Checks |
|---|---|
| `lang` required | Every component must have a `lang` array (either directly or inherited from the view). |
| `columns` required | Views that reference a `table` component must define `columns`. |
| `noModel` requires `columnsSchema` | When `noModel: true`, components that need column data (table, toolbar, filterSection) must have `columnsSchema` — either inline, from root-level, or via integer reference. |
| View requires `components` | Every view block must have a `components` object. |
| Component not found | A component reference in a view must point to an existing root-level key. |
| Root `columnsSchema` valid | If present, root-level `columnsSchema` must be a non-empty array. Numbered entries must each be non-empty arrays. |
| `inputType: "select"` requires config | When `inputType` is `"select"`, a `select` or `filterable` config object must exist. |
| `mode: "url"` requires `url` | Select mode `"url"` requires a valid `url` string. |
| Functions require `file` and `function` | Object-style function definitions must have both keys. |
| Search requires `submitUrl` | Fields with `inputType: "search"` must have a `submitUrl`. |

</details>

<details open>
<summary><strong>Warnings (Informational)</strong></summary>

| Rule | What It Checks |
|---|---|
| `per_page` positive | `per_page` should be a positive integer. |
| Filter key exists | Filter entries should reference known columns in the schema. |
| Column exists in schema | `columns` entries should reference valid `apiSchema()` or `columnsSchema` keys. |
| `displayType` requires config | `displayType: "chip"` or `"select"` should have a matching sub-key or `displayProps`. |
| `mode: "self"` requires `items` | Self-mode select should have a non-empty `items` array. |
| `mode: "relation"` requires `relationship` | Relation-mode select should specify the relationship name. |
| Field group exists | Field `group` values should match one of the `groups[].name` values. |
| Group name unique | Group names should not be duplicated. |
| Group title localized | Group titles should be `{en, dv}` objects. |
| Event handler exists | Field event handler names should exist in the `functions` block. |
| Column customization key exists | Customized columns should exist in the schema (unless `displayType: "custom"`). |
| Component config file exists | Referenced components should have matching template files. |
| Cross-model reference | References to other models' configs generate a "ensure it exists" warning. |

</details>

## 18. Examples

<details>
<summary><strong>Card Example</strong> <code>cform.json (excerpt)</code></summary>

```json
{
    "listView": {
        "noModel": true,
        "components": {
            "card": "CForm/card",
            "form": "CForm/form",
            "meta": "CForm/meta"
        },
        "lang": ["en", "dv"]
    },
    "card": {
        "noModel": true,
        "per_page": 3,
        "datalink": "https://uiapi.pgo.mv/api/sample/experts?",
        "grid": { "sm": 1, "md": 2, "lg": 3 },
        "columns": ["experiences.organization_eng"],
        "cardLayout": {
            "stacks": [
                { "stackType": "field", "key": "person_id" },
                {
                    "stackType": "row",
                    "justify": "space-between",
                    "fields": [
                        { "key": "person.person_name_eng", "lang": "en" },
                        { "key": "person.person_name_div", "lang": "dv" }
                    ]
                },
                { "stackType": "divider" },
                {
                    "stackType": "section",
                    "key": "experiences",
                    "title": { "en": "Experiences", "dv": "ތަޖުރިބާ" },
                    "emptyText": { "en": "No experience information", "dv": "ނެތް" },
                    "stacks": [
                        {
                            "stackType": "row",
                            "separator": " - ",
                            "fields": [
                                { "key": "organization_eng", "lang": "en" },
                                { "key": "organization", "lang": "dv" }
                            ]
                        }
                    ]
                }
            ]
        }
    }
}
```

</details>

<details>
<summary><strong>Model-Backed Example</strong> <code>cform.json</code></summary>

A full view config for the `CForm` model with table, form, toolbar, filterSection, and meta:

```json
{
    "listView": {
        "components": {
            "table": "CForm/table",
            "form": "CForm/form",
            "toolbar": "CForm/toolbar",
            "filterSection": "CForm/filterSection",
            "meta": "CForm/meta"
        },
        "lang": ["en", "dv"]
    },

    "table": {
        "per_page": 15,
        "actions": {
            "showView": false
        },
        "columns": [
            "id",
            "uuid",
            "ref_num",
            "summary",
            "status",
            "createdby.first_name_div"
        ],
        "columnCustomizations": {
            "New_ref": {
                "width": "400px",
                "label": { "en": "New Column", "dv": "ޢންވަރު ކޮލަމް" },
                "columnData": "customeColumnData()",
                "order": 3,
                "displayType": "custom"
            },
            "ref_num": {
                "width": "auto",
                "align": "center",
                "sortable": true
            },
            "status": {
                "displayType": "chip",
                "chip": {
                    "draft": {
                        "size": "sm",
                        "label": { "en": "Draft", "dv": "ޑްރާފްޓް" },
                        "color": "secondary",
                        "prependIcon": "document-text"
                    },
                    "submitted": {
                        "size": "sm",
                        "label": { "en": "Submitted", "dv": "ސަބްމިޓް" },
                        "color": "primary",
                        "prependIcon": "paper-airplane"
                    },
                    "rejected": {
                        "size": "sm",
                        "label": { "en": "Rejected", "dv": "ރިޖެކްޓުކުރެވިފަ" },
                        "color": "error",
                        "prependIcon": "x-circle"
                    },
                    "accepted": {
                        "size": "sm",
                        "label": { "en": "Accepted", "dv": "ބަލައިގަނެވިފަ" },
                        "color": "success",
                        "prependIcon": "check-circle"
                    }
                },
                "sortable": true,
                "inlineEditable": true
            }
        },
        "functions": {
            "customeColumnData": {
                "file": "misc.js",
                "function": "customeColumnData"
            }
        },
        "delete": {
            "title": { "en": "Confirm Deletion", "dv": "މުދައްދު ނިންމު" },
            "subtitle": {
                "en": "Are you sure you want to delete this item?",
                "dv": "..."
            },
            "message": {
                "ref_num": { "en": "ref", "dv": "އައިޑީ" },
                "uuid": { "en": "UUID", "dv": "ޔުއިއުޑީ" }
            },
            "crudLink": "gapi/CForm"
        }
    },

    "form": {
        "createTitle": { "en": "Create Form", "dv": "އައު" },
        "editTitle": { "en": "Edit Form", "dv": "ބަދަލު" },
        "banner": {
            "title": { "en": "Form Details", "dv": "ސަރަހައްދު މަޢުލޫމާތު" },
            "message": {
                "en": "Please fill out the form below. Fields marked with * are required.",
                "dv": "..."
            },
            "color": "info",
            "icon": "information-circle",
            "variant": "tonal"
        },
        "groups": [
            {
                "name": "Search",
                "title": { "en": "Search", "dv": "ހޯދާ" },
                "numberOfColumns": 2
            },
            {
                "name": "BasicInfo",
                "condition": "SearchResults || formType === 'edit'",
                "title": { "en": "Basic Information", "dv": "މަޢުލޫމާތު" },
                "numberOfColumns": 3
            },
            {
                "name": "Submission",
                "condition": "SearchResults || formType === 'edit'",
                "title": { "en": "Submission", "dv": "އިތުރު ތަފްޞީލް" },
                "numberOfColumns": 2
            }
        ],
        "fields": [
            {
                "key": "nid_pp",
                "group": "Search",
                "disabled": "mode == 'edit' ? true : false",
                "columnSpan": 2,
                "inputType": "search",
                "submitUrl": "gapi/person/",
                "submitMethod": "get",
                "NotDbField": true,
                "lang": "en",
                "submitParam": "lang=${language}&filter",
                "events": { "results": "handleSearchResults" }
            },
            {
                "key": "ref_num",
                "disabled": "mode == 'edit' ? true : false",
                "group": "BasicInfo",
                "rules": "[rules.required]"
            },
            { "key": "client_ref_number", "group": "BasicInfo" },
            { "key": "qazziyya_number", "group": "BasicInfo" },
            {
                "key": "summary",
                "inputType": "textarea",
                "group": "BasicInfo",
                "columnSpan": 3,
                "hidden": false
            },
            { "key": "sender_type", "group": "Submission" },
            {
                "key": "entry_type",
                "group": "Submission",
                "inputType": "select",
                "mode": "url",
                "url": "http://v2.govapi.pgo.mv/api/entry-types",
                "itemTitle": "itemTitleDv",
                "itemValue": "itemValue"
            },
            { "key": "total_pages", "group": "Submission" },
            { "key": "status", "group": "Submission" },
            {
                "key": "submitted_at",
                "group": "Submission",
                "format": "dd/MM/yyyy"
            },
            {
                "key": "remarks",
                "group": "Submission",
                "placeholder": "asdf"
            },
            {
                "key": "file_upload",
                "label": { "en": "Upload File", "dv": "ފައިލް އަންނަނީ" },
                "inputType": "file",
                "columnSpan": 3
            }
        ],
        "variables": { "draftingEnabled": true, "SearchResults": true },
        "functions": {
            "handleSearchResults": {
                "file": "misc.js",
                "function": "handleSearchResults"
            }
        }
    },

    "toolbar": {
        "title": { "en": "Forms", "dv": "ސަރަހައްދު" },
        "filters": ["status", "search"],
        "buttons": ["search", "clear"],
        "columnCustomizations": {
            "search": {
                "key": "ref_num",
                "label": { "en": "Search by ID", "dv": "އައިޑީ އިން ހޯދާ" },
                "color": "primary",
                "prependIcon": "search"
            }
        }
    },

    "filterSection": {
        "filters": ["status"],
        "columnCustomizations": {
            "status": {
                "width": "180px",
                "displayType": "chip",
                "sortable": true,
                "inlineEditable": true,
                "inputType": "select",
                "select": {
                    "type": "select",
                    "mode": "self",
                    "items": [
                        { "itemTitleEn": "Draft", "itemTitleDv": "ޑްރާފްޓް", "itemValue": "draft" },
                        { "itemTitleEn": "Submitted", "itemTitleDv": "ސަބްމިޓް", "itemValue": "submitted" },
                        { "itemTitleEn": "Rejected", "itemTitleDv": "ރިޖެކްޓު", "itemValue": "rejected" },
                        { "itemTitleEn": "Accepted", "itemTitleDv": "ބަލައިގަނެވިފަ", "itemValue": "accepted" }
                    ]
                }
            }
        }
    },

    "meta": {}
}
```

</details>

<details>
<summary><strong>noModel Example</strong> <code>criminalrecord.json</code></summary>

A full view config for external API data with no Eloquent model:

```json
{
    "listView": {
        "noModel": true,
        "components": {
            "table": "criminalrecord/table",
            "form": "criminalrecord/form",
            "filterSection": "criminalrecord/filterSection",
            "toolbar": "criminalrecord/toolbar",
            "meta": "criminalrecord/meta"
        }
    },

    "table": {
        "noModel": true,
        "lang": ["en", "dv"],
        "per_page": 7,
        "datalink": "http://external-api.example.com/api/criminal-records/.../requests",
        "delete": {
            "title": { "en": "Confirm Deletion", "dv": "..." },
            "subtitle": { "en": "Are you sure?", "dv": "..." },
            "message": {
                "ref_num": { "en": "ref", "dv": "އައިޑީ" }
            }
        },
        "columnsSchema": {
            "id": {
                "hidden": false,
                "key": "id",
                "label": { "dv": "އައިޑީ", "en": "Id" },
                "lang": ["en", "dv"],
                "type": "number",
                "displayType": "text",
                "formField": false,
                "sortable": true
            },
            "ref_num": {
                "hidden": false,
                "key": "ref_num",
                "label": { "dv": "ރެފެރެންސް ނަންބަރު", "en": "Reference Number" },
                "lang": ["en", "dv"],
                "type": "text",
                "displayType": "text",
                "formField": false,
                "sortable": true
            },
            "person_name": {
                "hidden": false,
                "key": "person_name",
                "label": { "dv": "ފުރިހަމަ ނަން", "en": "Person Name" },
                "lang": ["en", "dv"],
                "type": "text",
                "displayType": "text",
                "formField": false,
                "sortable": true
            },
            "status": {
                "hidden": false,
                "key": "status",
                "label": { "dv": "ސްޓޭޓަސް", "en": "Status" },
                "lang": ["en", "dv"],
                "type": "text",
                "displayType": "text",
                "formField": false,
                "sortable": true
            }
        },
        "columns": ["id", "ref_num", "person_name", "status"],
        "columnCustomizations": {
            "ref_num": {
                "width": "200px",
                "align": "center",
                "sortable": true
            },
            "status": {
                "width": "180px",
                "displayType": "chip",
                "chip": {
                    "draft": {
                        "size": "sm",
                        "label": { "en": "Draft", "dv": "ޑްރާފްޓް" },
                        "color": "secondary",
                        "prependIcon": "file"
                    },
                    "completed": {
                        "size": "sm",
                        "label": { "en": "Completed", "dv": "ނިމިފައި" },
                        "color": "success",
                        "prependIcon": "check-circle"
                    }
                },
                "sortable": true,
                "inlineEditable": true
            }
        }
    },

    "form": {
        "noModel": true,
        "lang": ["en", "dv"],
        "groups": [
            {
                "name": "PersonalDetailsEng",
                "title": { "en": "Check if the person is Maldivian", "dv": "..." },
                "numberOfColumns": 3
            },
            {
                "name": "PersonalDetails",
                "title": { "en": "Person Details", "dv": "ތަފްޞީލު" },
                "numberOfColumns": 3
            }
        ],
        "fields": [
            {
                "key": "ref_number_check",
                "inputType": "checkbox",
                "columnSpan": 3,
                "group": "PersonalDetailsEng",
                "label": { "en": "Check if the person has a reference number", "dv": "..." },
                "color": "primary"
            },
            {
                "key": "passport_id",
                "columnSpan": 3,
                "condition": "!ref_number_check",
                "group": "PersonalDetails",
                "label": { "en": "Passport Number", "dv": "ޕާސްޕޯޓު ނަންބަރު" }
            },
            {
                "key": "person__id",
                "columnSpan": 3,
                "condition": "ref_number_check",
                "group": "PersonalDetails",
                "label": { "en": "National ID", "dv": "އަ.އިޑީ ކާޑު" }
            },
            {
                "key": "person_name",
                "columnSpan": 3,
                "group": "PersonalDetails",
                "label": { "en": "Person Name", "dv": "ފުރިހަމަ ނަން" }
            },
            {
                "key": "details",
                "columnSpan": 3,
                "group": "PersonalDetails",
                "label": { "en": "Reason for checking", "dv": "ކުށުގެ ރެކޯޑު ސާފުކުރާ ސަބަބު" }
            }
        ],
        "variables": {}
    },

    "meta": {
        "noModel": true,
        "crudLink": "http://external-api.example.com/api/gapi/CriminalRecordRequest",
        "lang": ["en", "dv"]
    },

    "filterSection": {
        "noModel": true,
        "lang": ["en", "dv"],
        "filters": ["status"],
        "buttons": ["submit", "clear"],
        "columnsSchema": {
            "status": {
                "key": "status",
                "label": { "dv": "ސްޓޭޓަސް", "en": "Status" },
                "lang": ["en", "dv"],
                "inputType": "select",
                "select": {
                    "mode": "self",
                    "items": [
                        { "itemTitleEn": "Draft", "itemTitleDv": "ޑްރާފްޓް", "itemValue": "draft" },
                        { "itemTitleEn": "Submitted", "itemTitleDv": "ސަބްމިޓް", "itemValue": "submitted" },
                        { "itemTitleEn": "Completed", "itemTitleDv": "ނިމިފައި", "itemValue": "completed" }
                    ]
                }
            }
        }
    },

    "toolbar": {
        "noModel": true,
        "lang": ["en", "dv"],
        "title": { "en": "Criminal Records", "dv": "އައު ފޯމެއް" },
        "filters": ["status"],
        "buttons": ["search", "clear"],
        "columnsSchema": {
            "status": {
                "key": "status",
                "label": { "dv": "ސްޓޭޓަސް", "en": "Status" },
                "lang": ["en", "dv"],
                "inputType": "select",
                "select": {
                    "mode": "self",
                    "items": [
                        { "itemTitleEn": "Draft", "itemTitleDv": "ޑްރާފްޓް", "itemValue": "draft" },
                        { "itemTitleEn": "Completed", "itemTitleDv": "ނިމިފައި", "itemValue": "completed" }
                    ]
                }
            }
        }
    }
}
```

</details>

## 19. Quick Checklist

<details open>
<summary><strong>Model-Backed Config</strong></summary>

- [ ] File named correctly (lowercase, no separators, `.json`)
- [ ] At least one view block with `"components"` map
- [ ] Component references point to existing root-level keys
- [ ] `lang` defined (on view or on each component)
- [ ] `columns` array on the table component with fields to display
- [ ] `columnCustomizations` for display overrides (chips, dates, widths)
- [ ] `filters` array on toolbar/filterSection if you want filter UI
- [ ] `groups` and `fields` defined for the form
- [ ] `functions` block if using external JS or event handlers
- [ ] All `{dv}` labels filled in (not left as "TODO")
- [ ] `delete` config on table if delete action is enabled
- [ ] `cardLayout` configured if using a card view
- [ ] `meta` component included (for CRUD link)

</details>

<details open>
<summary><strong>noModel Config</strong></summary>

All the above, plus:

- [ ] `"noModel": true` on the view (propagates to components) or on individual components
- [ ] `columnsSchema` on table, toolbar, and filterSection — inline, root-level, or via integer reference
- [ ] If using card stacks with inherited `lang`/`label`/`align`, provide matching `columnsSchema` keys
- [ ] `lang` explicitly on every component (no model to inherit from)
- [ ] `datalink` manually set on table (custom URL)
- [ ] `crudLink` manually set on meta (custom URL)
- [ ] Form fields fully declared (no auto-generation from schema)
- [ ] If using root-level `columnsSchema`: numbered entries are consistent with component references

</details>

### Internal Keys (Stripped from Payload)

These keys are consumed by CCS during processing and never appear in the API response:

| Key | Purpose |
|---|---|
| `columnCustomizations` | Column display overrides |
| `columnsSchema` | noModel column definitions |
| `noModel` | noModel flag |
| `columns` | Column ordering |
| `per_page` | Pagination size |
| `filters` | Allowed filter columns |
| `lang` | Language support |
