<?php

/**
 * VS Code Bridge for UiApi Model Generator.
 *
 * This script is called by the UiApi VS Code extension to interact with
 * the ModelGeneratorService without needing an interactive artisan session.
 *
 * Usage:
 *   php vscode-bridge.php <laravel-root> <action> [args...]
 *
 * Actions:
 *   list-tables           <sql-file>                       — List all CREATE TABLE names
 *   generate              <sql-file> <table> <model-name>  — Generate model + view config
 *   generate-model        <sql-file> <table> <model-name>  — Generate model only
 *   generate-viewconfig   <sql-file> <table> <model-name>  — Generate view config only
 *   check-files           <model-name>                     — Check if output files already exist
 *   check-model           <model-name>                     — Check if model class exists and has apiSchema()
 *   viewconfig-from-model <model-name>                     — Generate view config from existing model's apiSchema()
 *   viewconfig-from-db    <model-name> <table-name>        — Generate view config via DB introspection (model has no apiSchema)
 *   apischema-from-db     <model-name> <table-name>        — Return generated apiSchema() snippet from DB introspection
 */

// ─── Bootstrap ──────────────────────────────────────────────────────
$laravelRoot = $argv[1] ?? null;
$action = $argv[2] ?? null;

if (! $laravelRoot || ! $action) {
    echo json_encode(['error' => 'Usage: php vscode-bridge.php <laravel-root> <action> [args...]']);
    exit(1);
}

// Bootstrap Laravel
$autoload = rtrim($laravelRoot, '/').'/vendor/autoload.php';
if (! file_exists($autoload)) {
    echo json_encode(['error' => "Autoload not found at: {$autoload}"]);
    exit(1);
}

require $autoload;

$app = require rtrim($laravelRoot, '/').'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// ─── Route to action ────────────────────────────────────────────────

$generator = new \Ogp\UiApi\Services\ModelGeneratorService;

try {
    match ($action) {
        'list-tables' => handleListTables($generator, $argv),
        'generate' => handleGenerate($generator, $argv, $laravelRoot, 'both'),
        'generate-model' => handleGenerate($generator, $argv, $laravelRoot, 'model'),
        'generate-viewconfig' => handleGenerate($generator, $argv, $laravelRoot, 'viewconfig'),
        'check-files' => handleCheckFiles($argv, $laravelRoot),
        'check-model' => handleCheckModel($generator, $argv, $laravelRoot),
        'viewconfig-from-model' => handleViewConfigFromModel($generator, $argv, $laravelRoot),
        'viewconfig-from-db' => handleViewConfigFromDb($generator, $argv, $laravelRoot),
        'apischema-from-db' => handleApiSchemaFromDb($generator, $argv),
        default => throw new \InvalidArgumentException("Unknown action: {$action}"),
    };
} catch (\Throwable $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    exit(1);
}

// ─── Action handlers ────────────────────────────────────────────────

function handleListTables(\Ogp\UiApi\Services\ModelGeneratorService $generator, array $argv): void
{
    $sqlFile = $argv[3] ?? null;
    if (! $sqlFile) {
        echo json_encode(['error' => 'SQL file path required']);
        exit(1);
    }

    $tables = $generator->extractAllTableNames($sqlFile);

    echo json_encode([
        'success' => true,
        'tables' => $tables,
    ]);
}

/**
 * Generate model and/or view config from a SQL file.
 *
 * @param  string  $mode  'both', 'model', or 'viewconfig'
 */
function handleGenerate(\Ogp\UiApi\Services\ModelGeneratorService $generator, array $argv, string $laravelRoot, string $mode): void
{
    $sqlFile = $argv[3] ?? null;
    $tableName = $argv[4] ?? null;
    $modelName = $argv[5] ?? null;

    if (! $sqlFile || ! $tableName || ! $modelName) {
        echo json_encode(['error' => 'Usage: generate[-model|-viewconfig] <sql-file> <table-name> <model-name>']);
        exit(1);
    }

    // Parse the specific table
    $data = $generator->parseSqlDumpForTable($sqlFile, $tableName);

    // Determine output paths
    $modelPath = rtrim($laravelRoot, '/').'/app/Models/'.$modelName.'.php';
    $viewConfigsPath = config('uiapi.view_configs_path', 'app/Services/viewConfigs');
    $normalizedName = strtolower(str_replace(['-', '_', ' '], '', $modelName));
    $jsonPath = rtrim($laravelRoot, '/').'/'.trim($viewConfigsPath, '/').'/'.$normalizedName.'.json';

    // Check if target files exist (prevent overwrite)
    $existing = [];
    if (in_array($mode, ['both', 'model']) && file_exists($modelPath)) {
        $existing[] = $modelPath;
    }
    if (in_array($mode, ['both', 'viewconfig']) && file_exists($jsonPath)) {
        $existing[] = $jsonPath;
    }

    if (! empty($existing)) {
        echo json_encode([
            'error' => 'Files already exist',
            'existingFiles' => $existing,
        ]);
        exit(1);
    }

    $result = [
        'success' => true,
        'table' => $data['table'],
        'columnCount' => count($data['columns']),
        'columns' => array_keys($data['columns']),
        'timestamps' => $data['timestamps'],
    ];

    // Generate & write model
    if (in_array($mode, ['both', 'model'])) {
        $modelContent = $generator->generateModel($modelName, $data);
        if (! is_dir(dirname($modelPath))) {
            mkdir(dirname($modelPath), 0755, true);
        }
        file_put_contents($modelPath, $modelContent);
        $result['modelPath'] = $modelPath;
    }

    // Generate & write view config
    if (in_array($mode, ['both', 'viewconfig'])) {
        $viewConfigContent = $generator->generateViewConfig($modelName, $data);
        if (! is_dir(dirname($jsonPath))) {
            mkdir(dirname($jsonPath), 0755, true);
        }
        file_put_contents($jsonPath, $viewConfigContent);
        $result['viewConfigPath'] = $jsonPath;
    }

    echo json_encode($result);
}

function handleCheckFiles(array $argv, string $laravelRoot): void
{
    $modelName = $argv[3] ?? null;
    if (! $modelName) {
        echo json_encode(['error' => 'Model name required']);
        exit(1);
    }

    $modelPath = rtrim($laravelRoot, '/').'/app/Models/'.$modelName.'.php';
    $viewConfigsPath = config('uiapi.view_configs_path', 'app/Services/viewConfigs');
    $normalizedName = strtolower(str_replace(['-', '_', ' '], '', $modelName));
    $jsonPath = rtrim($laravelRoot, '/').'/'.trim($viewConfigsPath, '/').'/'.$normalizedName.'.json';

    echo json_encode([
        'success' => true,
        'modelPath' => $modelPath,
        'modelExists' => file_exists($modelPath),
        'viewConfigPath' => $jsonPath,
        'viewConfigExists' => file_exists($jsonPath),
    ]);
}

/**
 * Check if a model class exists and has apiSchema().
 */
function handleCheckModel(\Ogp\UiApi\Services\ModelGeneratorService $generator, array $argv, string $laravelRoot): void
{
    $modelName = $argv[3] ?? null;
    if (! $modelName) {
        echo json_encode(['error' => 'Model name required']);
        exit(1);
    }

    $fqcn = 'App\\Models\\'.$modelName;
    $modelPath = rtrim($laravelRoot, '/').'/app/Models/'.$modelName.'.php';
    $classExists = class_exists($fqcn);
    $hasApiSchema = $classExists && $generator->modelHasApiSchema($fqcn);

    // Also get the table name if the model exists
    $tableName = null;
    if ($classExists) {
        try {
            $model = new $fqcn;
            $tableName = $model->getTable();
        } catch (\Throwable $e) {
            // Ignore — the model might not be instantiable
        }
    }

    echo json_encode([
        'success' => true,
        'modelName' => $modelName,
        'fqcn' => $fqcn,
        'modelPath' => $modelPath,
        'classExists' => $classExists,
        'hasApiSchema' => $hasApiSchema,
        'tableName' => $tableName,
    ]);
}

/**
 * Generate a view config JSON from an existing model's apiSchema().
 */
function handleViewConfigFromModel(\Ogp\UiApi\Services\ModelGeneratorService $generator, array $argv, string $laravelRoot): void
{
    $modelName = $argv[3] ?? null;
    if (! $modelName) {
        echo json_encode(['error' => 'Model name required']);
        exit(1);
    }

    $fqcn = 'App\\Models\\'.$modelName;

    // Check view config doesn't already exist
    $viewConfigsPath = config('uiapi.view_configs_path', 'app/Services/viewConfigs');
    $normalizedName = strtolower(str_replace(['-', '_', ' '], '', $modelName));
    $jsonPath = rtrim($laravelRoot, '/').'/'.trim($viewConfigsPath, '/').'/'.$normalizedName.'.json';

    if (file_exists($jsonPath)) {
        echo json_encode([
            'error' => 'View config already exists',
            'existingFiles' => [$jsonPath],
        ]);
        exit(1);
    }

    // Extract migration data from the model's apiSchema
    $data = $generator->extractMigrationDataFromModel($fqcn);

    // Generate view config
    $viewConfigContent = $generator->generateViewConfig($modelName, $data);

    if (! is_dir(dirname($jsonPath))) {
        mkdir(dirname($jsonPath), 0755, true);
    }
    file_put_contents($jsonPath, $viewConfigContent);

    echo json_encode([
        'success' => true,
        'viewConfigPath' => $jsonPath,
        'table' => $data['table'],
        'columnCount' => count($data['columns']),
        'columns' => array_keys($data['columns']),
    ]);
}

/**
 * Generate a view config JSON via DB introspection (for models without apiSchema).
 */
function handleViewConfigFromDb(\Ogp\UiApi\Services\ModelGeneratorService $generator, array $argv, string $laravelRoot): void
{
    $modelName = $argv[3] ?? null;
    $tableName = $argv[4] ?? null;

    if (! $modelName || ! $tableName) {
        echo json_encode(['error' => 'Usage: viewconfig-from-db <model-name> <table-name>']);
        exit(1);
    }

    // Check view config doesn't already exist
    $viewConfigsPath = config('uiapi.view_configs_path', 'app/Services/viewConfigs');
    $normalizedName = strtolower(str_replace(['-', '_', ' '], '', $modelName));
    $jsonPath = rtrim($laravelRoot, '/').'/'.trim($viewConfigsPath, '/').'/'.$normalizedName.'.json';

    if (file_exists($jsonPath)) {
        echo json_encode([
            'error' => 'View config already exists',
            'existingFiles' => [$jsonPath],
        ]);
        exit(1);
    }

    // Extract migration data from the database
    $data = $generator->extractMigrationDataFromDatabase($tableName);

    // Generate view config
    $viewConfigContent = $generator->generateViewConfig($modelName, $data);

    if (! is_dir(dirname($jsonPath))) {
        mkdir(dirname($jsonPath), 0755, true);
    }
    file_put_contents($jsonPath, $viewConfigContent);

    echo json_encode([
        'success' => true,
        'viewConfigPath' => $jsonPath,
        'table' => $data['table'],
        'columnCount' => count($data['columns']),
        'columns' => array_keys($data['columns']),
    ]);
}

/**
 * Return a generated apiSchema() method snippet from DB introspection.
 *
 * This doesn't write any files — it returns the PHP code that the user
 * should add to their model manually (or we inject it via the extension).
 */
function handleApiSchemaFromDb(\Ogp\UiApi\Services\ModelGeneratorService $generator, array $argv): void
{
    $modelName = $argv[3] ?? null;
    $tableName = $argv[4] ?? null;

    if (! $modelName || ! $tableName) {
        echo json_encode(['error' => 'Usage: apischema-from-db <model-name> <table-name>']);
        exit(1);
    }

    // Extract migration data from DB
    $data = $generator->extractMigrationDataFromDatabase($tableName);

    // Generate a full temporary model to extract just the apiSchema portion
    $fullModel = $generator->generateModel($modelName, $data);

    // Extract the apiSchema() method from the generated model
    if (preg_match('/( {4}public function apiSchema\(\): array\n {4}\{[\s\S]*?\n {4}\})/m', $fullModel, $match)) {
        $apiSchemaSnippet = $match[1];
    } else {
        $apiSchemaSnippet = null;
    }

    echo json_encode([
        'success' => true,
        'table' => $data['table'],
        'columnCount' => count($data['columns']),
        'columns' => array_keys($data['columns']),
        'apiSchemaSnippet' => $apiSchemaSnippet,
    ]);
}
