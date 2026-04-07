# Generic API Guide (Frontend) V2.00

Base URL: https://uiapi.pgo.mv/api

This API provides generic CRUD endpoints for any Eloquent model by using the model name in the path. It also supports powerful query parameters for filtering, searching, sorting, selecting columns, and eager-loading relations.

## Endpoints

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/gapi/{model}` | List records (paginated, filterable, sortable) |
| `GET` | `/api/gapi/{model}/{id}` | Show a single record |
| `POST` | `/api/gapi/{model}` | Create a new record |
| `PUT` | `/api/gapi/{model}/{id}` | Update an existing record |
| `DELETE` | `/api/gapi/{model}/{id}` | Delete a record |

### `{id}` — Integer ID or UUID

The `{id}` segment in show, update, and delete endpoints accepts either an **integer ID** or a **UUID**. When a model has a `uuid` column, passing the UUID is preferred as it avoids exposing sequential integer IDs to users.

```
GET /api/gapi/cform/42                                    # integer ID
GET /api/gapi/cform/a1b2c3d4-e5f6-7890-abcd-ef1234567890  # UUID
```

> **Note:** When UUID enforcement is enabled on the server (`enforce_uuid: true` in config), integer ID lookups are rejected with a `404` for models that have a UUID column. Only UUID-based lookups are accepted.

### Model Names

Use the model class basename in lowercase. Examples from this app:

| Path segment | Resolves to |
|---|---|
| `cform` | `App\Models\CForm` |
| `person` | `App\Models\Person` |
| `country` | `App\Models\Country` |
| `entrytype` | `App\Models\EntryType` |

Note: Use letters only (no dashes/underscores). The API maps `/{model}` by lowercasing and then capitalizing.

---

## Listing: Query Parameters

Attach these to `GET /api/gapi/{model}`:

### `columns`
Comma-separated list of columns to return. Supports dot-notation for relation fields.

```
GET /api/gapi/cform?columns=id,uuid,ref_num,status
GET /api/gapi/cform?columns=id,ref_num,country.name_eng
```

### `search`
Advanced AND/OR search with `column:value` pairs.
- AND within a group: comma-separated
- OR across groups: pipe (`|`) separated

```
GET /api/gapi/person?search=name:Ali
GET /api/gapi/person?search=name:Ali,status:active
GET /api/gapi/person?search=name:Ali|name:Ahmed
```

### `searchAll`
Single term searched across all model-defined `searchable` columns.

```
GET /api/gapi/cform?searchAll=PGO-2024
```

### `filter`
Exact-match filters. AND logic across different fields, OR logic within a single field using `|`.

Special values:
- `!null` — matches NOT NULL: `filter=closed_at:!null`
- empty value — matches empty string OR NULL: `filter=status:`

```
# Single field filter
GET /api/gapi/cform?filter=status:draft

# OR within a field — status is 'draft' OR 'submitted'
GET /api/gapi/cform?filter=status:draft|submitted

# AND across fields — status is 'draft' AND country code is 'MV'
GET /api/gapi/cform?filter=status:draft,country.code:MV

# Records where closed_at is NOT NULL
GET /api/gapi/entry?filter=closed_at:!null

# Records where status is empty or NULL
GET /api/gapi/cform?filter=status:
```

### `withoutRow`
Exclude rows matching exact values. Uses the same AND/OR grammar as `search`.

```
GET /api/gapi/person?withoutRow=status:archived|role:guest
```

### `sort`
Comma-separated list of columns to sort by. Prefix `-` for descending.

```
GET /api/gapi/cform?sort=-created_at,ref_num
```

### `with`
Eager-load relations. Optionally restrict related columns using `:col1,col2`.

```
GET /api/gapi/cform?with=country
GET /api/gapi/cform?with=country:id,name_eng
GET /api/gapi/cform?with=country:id,name_eng,entryType:id,name
```

> The primary key of the related model is always auto-included even if not listed.

### `pivot`
Eager-load belongsToMany relations including pivot data.

```
GET /api/gapi/person?pivot=tags,categories
```

### `pagination`
Set to `off` to return all rows without pagination. Use `page` and `per_page` for paginated requests.

```
GET /api/gapi/country?pagination=off
GET /api/gapi/cform?per_page=25&page=2
```

### `wrap`
When set to `data` and used with `pagination=off`, wraps the response array in a top-level `data` key. Useful for option lists and components expecting `{ data: [...] }`.

```
GET /api/gapi/country?columns=id,name_eng&sort=name_eng&pagination=off&wrap=data
```

Response:
```json
{ "data": [ { "id": 1, "name_eng": "Maldives" }, ... ] }
```

Without `wrap`, `pagination=off` returns a plain array:
```json
[ { "id": 1, "name_eng": "Maldives" }, ... ]
```

---

## Response Shapes

### Paginated (default)
```json
{
  "data": [ { "id": 1, "ref_num": "PGO-001", "status": "draft" }, ... ],
  "pagination": {
    "current_page": 1,
    "per_page": 15,
    "total": 120,
    "last_page": 8
  }
}
```

### Without pagination (`pagination=off`)
```json
[ { "id": 1, "name_eng": "Maldives" }, ... ]
```

### Without pagination + wrapped (`pagination=off&wrap=data`)
```json
{ "data": [ { "id": 1, "name_eng": "Maldives" }, ... ] }
```

---

## Show

`GET /api/gapi/{model}/{id}`

Supports `columns`, `with`, and `pivot` like listing. `{id}` can be an integer ID or UUID.

```
GET /api/gapi/cform/42?with=country:id,name_eng
GET /api/gapi/cform/a1b2c3d4-e5f6-7890-abcd-ef1234567890?columns=id,uuid,ref_num,status
```

---

## Create

`POST /api/gapi/{model}` with a JSON body of the model fields.

```http
POST /api/gapi/cform
Content-Type: application/json

{
  "ref_num": "PGO-2024-001",
  "summary": "New case summary",
  "status": "draft"
}
```

Notes:
- Validation rules depend on the model. If validation fails, you'll get `422` with field-level error details.
- Some models handle file uploads; send those as `multipart/form-data`.

---

## Update

`PUT /api/gapi/{model}/{id}` with a JSON body of fields to update. `{id}` can be an integer ID or UUID.

```http
PUT /api/gapi/cform/42
Content-Type: application/json

{
  "status": "submitted",
  "summary": "Updated summary"
}
```

```http
PUT /api/gapi/cform/a1b2c3d4-e5f6-7890-abcd-ef1234567890
Content-Type: application/json

{
  "status": "submitted"
}
```

Notes:
- Returns the updated record in the response.
- Validation rules are applied on update as well.

---

## Delete

`DELETE /api/gapi/{model}/{id}`

`{id}` can be an integer ID or UUID.

```
DELETE /api/gapi/cform/42
DELETE /api/gapi/cform/a1b2c3d4-e5f6-7890-abcd-ef1234567890
```

---

## Errors & Status Codes

| Status | Meaning |
|---|---|
| `400` | Invalid `{model}` — model not found or not registered |
| `404` | Record not found for the given `{id}` |
| `404` | Integer ID used on a UUID-enabled model when UUID enforcement is active |
| `422` | Validation failed on create or update — response includes field-level `errors` |
| `500` | Unexpected server error |

### 422 Validation Error Shape
```json
{
  "message": "Validation failed.",
  "errors": {
    "ref_num": ["The ref num field is required."],
    "status": ["The selected status is invalid."]
  }
}
```

---

## Authentication

If your environment requires authentication, include a bearer token:

```
Authorization: Bearer <token>
```

---

## Quick cURL Examples

```bash
# List with columns, search, sort, and relations
curl -s "https://uiapi.pgo.mv/api/gapi/cform?columns=id,uuid,ref_num,status&searchAll=PGO-2024&sort=-created_at&with=country:id,name_eng"

# Filter by status (draft or submitted) and country
curl -s "https://uiapi.pgo.mv/api/gapi/cform?filter=status:draft|submitted,country.code:MV&sort=-created_at"

# Option list (all countries, wrapped, no pagination)
curl -s "https://uiapi.pgo.mv/api/gapi/country?columns=id,name_eng&sort=name_eng&pagination=off&wrap=data"

# Show by integer ID
curl -s "https://uiapi.pgo.mv/api/gapi/cform/42?with=country:id,name_eng"

# Show by UUID
curl -s "https://uiapi.pgo.mv/api/gapi/cform/a1b2c3d4-e5f6-7890-abcd-ef1234567890"

# Create
curl -s -X POST "https://uiapi.pgo.mv/api/gapi/cform" \
  -H "Content-Type: application/json" \
  -d '{"ref_num":"PGO-2024-001","summary":"New case","status":"draft"}'

# Update by UUID
curl -s -X PUT "https://uiapi.pgo.mv/api/gapi/cform/a1b2c3d4-e5f6-7890-abcd-ef1234567890" \
  -H "Content-Type: application/json" \
  -d '{"status":"submitted"}'

# Delete by UUID
curl -s -X DELETE "https://uiapi.pgo.mv/api/gapi/cform/a1b2c3d4-e5f6-7890-abcd-ef1234567890"
```
