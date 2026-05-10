<?php

return [
    // Path in the host app where view config JSONs will be published
    'view_configs_path' => 'app/Services/viewConfigs',

    // Prefix for API routes provided by this package
    'route_prefix' => 'api',
    'logging_enabled' => false,

    // Debug level for error messages:
    //   0 = minimal (generic error messages)
    //   1 = standard (error type and file path)
    //   2 = verbose (includes line number, column, and surrounding context)
    'debug_level' => 2,

    // When true, models that have a UUID column will reject show/update/destroy
    // requests made with an integer ID — only UUID-based lookups are accepted.
    // Default: false (both integer ID and UUID are accepted)
    'enforce_uuid' => true,

    // Allow arbitrary custom keys in component overrides (view configs)
    // When true, unknown keys like 'variables' under a component will be passed through into payloads.
    // Default: false (ignore unknown keys in overrides)
    'allow_custom_component_keys' => true,

    // Path in the host app where JS script files are stored for external function loading
    'js_scripts_path' => 'app/Services/jsScripts',

    // Optional: allow future model binding overrides
    // 'model_bindings' => [
    //     'Person' => \Ogp\UiApi\Models\Person::class,
    // ],

    // ── Access Control ────────────────────────────────────────────────────────

    // Toggle role-based access filtering on view config sections, fields, and filters.
    'access_control_roles' => true,

    // Toggle org-based access filtering on view config sections, fields, and filters.
    'access_control_orgs' => true,

    // Callable: fn(Request $request): string[]
    // Returns the current user's role names as an array of strings.
    // If null, falls back to auth()->user()->roles (Collection or array) or ->role (string).
    'roles_resolver' => null,

    // Callable: fn(Request $request): ?string
    // Returns the current user's org identifier as a string, or null.
    // If null, falls back to auth()->user()->org_id or ->organization_id.
    'org_resolver' => null,
];
