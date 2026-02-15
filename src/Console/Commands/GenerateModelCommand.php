<?php

namespace Ogp\UiApi\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Ogp\UiApi\Services\ModelGeneratorService;

class GenerateModelCommand extends Command
{
    protected $signature = 'uiapi:generate
        {name? : The model name (e.g. CForm, Invoice)}
        {--migration= : Explicit path to a Laravel migration file}
        {--sql= : Explicit path to a raw SQL dump file (CREATE TABLE)}';

    protected $description = 'Generate a Model (with apiSchema, rules, relationships) and a view config JSON from a migration file or raw SQL dump.';

    public function handle(ModelGeneratorService $generator): int
    {
        $name = $this->argument('name');
        $migrationPath = $this->option('migration');
        $sqlPath = $this->option('sql');

        // If no name given, prompt for it
        if (! $name || $name === '') {
            $name = $this->ask('What is the model name? (e.g. Invoice, CourtCase)');
            if (! $name) {
                $this->error('Model name is required.');

                return self::FAILURE;
            }
        }

        $modelName = Str::studly($name);

        // Determine source: --sql takes precedence if both provided
        if ($sqlPath) {
            return $this->generateFromSql($generator, $modelName, $sqlPath);
        }

        if ($migrationPath) {
            return $this->generateFromMigration($generator, $modelName, $migrationPath);
        }

        // Neither provided: ask the user which source to use
        $source = $this->choice(
            'What source do you want to generate from?',
            ['migration' => 'Laravel migration file', 'sql' => 'Raw SQL dump (CREATE TABLE)'],
            'migration'
        );

        if ($source === 'sql') {
            $sqlPath = $this->ask('Enter the SQL dump file path (relative to project root or absolute)');
            if (! $sqlPath) {
                $this->error('SQL file path is required.');

                return self::FAILURE;
            }

            return $this->generateFromSql($generator, $modelName, $sqlPath);
        }

        $migrationPath = $this->ask('Enter the migration file path (relative to project root or absolute)');
        if (! $migrationPath) {
            $this->error('Migration file path is required.');

            return self::FAILURE;
        }

        return $this->generateFromMigration($generator, $modelName, $migrationPath);
    }

    protected function generateFromMigration(ModelGeneratorService $generator, string $modelName, string $path): int
    {
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        if (! file_exists($path)) {
            $this->error("Migration file not found: {$path}");

            return self::FAILURE;
        }

        $this->info("Generating from migration: {$path}");
        $this->info("Model name: {$modelName}");

        try {
            $data = $generator->parseMigration($path);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->writeGeneratedFiles($generator, $modelName, $data);
    }

    protected function generateFromSql(ModelGeneratorService $generator, string $modelName, string $path): int
    {
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        if (! file_exists($path)) {
            $this->error("SQL file not found: {$path}");

            return self::FAILURE;
        }

        $this->info("Generating from SQL dump: {$path}");
        $this->info("Model name: {$modelName}");

        try {
            $data = $generator->parseSqlDump($path);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->writeGeneratedFiles($generator, $modelName, $data);
    }

    protected function writeGeneratedFiles(ModelGeneratorService $generator, string $modelName, array $data): int
    {
        $this->info("Detected table: {$data['table']}");
        $this->info('Detected columns: ' . implode(', ', array_keys($data['columns'])));

        // Foreign keys detected
        $foreignKeys = array_filter($data['columns'], fn ($col) => $col['foreign'] !== null);
        if (! empty($foreignKeys)) {
            $this->info('Detected relationships: ' . implode(', ', array_map(
                fn ($colName) => $colName . ' → ' . $data['columns'][$colName]['foreign']['table'],
                array_keys($foreignKeys)
            )));
        }

        // Generate Model
        $modelContent = $generator->generateModel($modelName, $data);
        $modelPath = app_path("Models/{$modelName}.php");

        if (file_exists($modelPath)) {
            if (! $this->confirm("Model file already exists at {$modelPath}. Overwrite?", false)) {
                $this->warn('Skipped model generation.');
            } else {
                file_put_contents($modelPath, $modelContent);
                $this->info("Model written to: {$modelPath}");
            }
        } else {
            if (! is_dir(dirname($modelPath))) {
                mkdir(dirname($modelPath), 0755, true);
            }
            file_put_contents($modelPath, $modelContent);
            $this->info("Model written to: {$modelPath}");
        }

        // Generate view config JSON
        $viewConfigContent = $generator->generateViewConfig($modelName, $data);
        $viewConfigsPath = base_path(config('uiapi.view_configs_path', 'app/Services/viewConfigs'));
        $normalizedName = strtolower(str_replace(['-', '_', ' '], '', $modelName));
        $jsonPath = rtrim($viewConfigsPath, '/') . '/' . $normalizedName . '.json';

        if (file_exists($jsonPath)) {
            if (! $this->confirm("View config already exists at {$jsonPath}. Overwrite?", false)) {
                $this->warn('Skipped view config generation.');
            } else {
                file_put_contents($jsonPath, $viewConfigContent);
                $this->info("View config written to: {$jsonPath}");
            }
        } else {
            if (! is_dir(dirname($jsonPath))) {
                mkdir(dirname($jsonPath), 0755, true);
            }
            file_put_contents($jsonPath, $viewConfigContent);
            $this->info("View config written to: {$jsonPath}");
        }

        $this->newLine();
        $this->info('Generation complete! Review the generated files and adjust:');
        $this->line('  • Dhivehi (dv) labels marked as "TODO" in apiSchema() and view config');
        $this->line('  • Relationship model class references');
        $this->line('  • Validation rules for business-specific constraints');
        $this->line('  • View config component overrides as needed');

        return self::SUCCESS;
    }
}
