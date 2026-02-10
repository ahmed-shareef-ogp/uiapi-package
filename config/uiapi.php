<?php

return [
    // Path in the host app where view config JSONs will be published
    'view_configs_path' => 'app/Services/viewConfigs',

    // Prefix for API routes provided by this package
    'route_prefix' => 'api',
    'logging_enabled' => true,

    // Allow arbitrary custom keys in component overrides (view configs)
    // When true, unknown keys like 'variables' under a component will be passed through into payloads.
    // Default: false (ignore unknown keys in overrides)
    'allow_custom_component_keys' => true,

    // Optional: allow future model binding overrides
    // 'model_bindings' => [
    //     'Person' => \Ogp\UiApi\Models\Person::class,
    // ],
];
