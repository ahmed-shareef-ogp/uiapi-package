<?php

namespace Ogp\UiApi\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Ogp\UiApi\Services\ModelGeneratorService;

class GenerateModelCommand extends Command
{
    protected $signature = 'uiapi:generate
        {name? : The model name (e.g. CForm, Invoice)}
        {--migration= : Explicit path to the migration file}';

    protected $description = 'Generate a Model (with apiSchema, rules, relationships) and a view config JSON from a migration file.';

    public function handle(ModelGeneratorService $generator): int
    {
        $name = $this->argument('name');
        $migrationPath = $this->option('migration');

        // If no name given, prompt for it
        if (! $name || $name === '') {
            $name = $this->ask('What is the model name? (e.g. Invoice, CourtCase)');
            if (! $name) {
                $this->error('Model name is required.');

                return self::FAILURE;
            }
        }

        $modelName = Str::studly($name);

        // If no migration path given, prompt for it
        if (! $migrationPath) {
            $migrationPath = $this->ask('Enter the migration file path (relative to project root or absolute)');
            if (! $migrationPath) {
                $this->error('Migration file path is required.');

                return self::FAILURE;
            }
        }

        // Resolve relative paths
        if (! str_starts_with($migrationPath, '/')) {
            $migrationPath = base_path($migrationPath);
        }

        if (! file_exists($migrationPath)) {
            $this->error("Migration file not found: {$migrationPath}");

            return self::FAILURE;
        }

        $this->info("Generating from migration: {$migrationPath}");
        $this->info("Model name: {$modelName}");

        try {
            $migrationData = $generator->parseMigration($migrationPath);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Detected table: {$migrationData['table']}");
        $this->info('Detected columns: ' . implode(', ', array_keys($migrationData['columns'])));

        // Foreign keys detected
        $foreignKeys = array_filter($migrationData['columns'], fn ($col) => $col['foreign'] !== null);
        if (! empty($foreignKeys)) {
            $this->info('Detected relationships: ' . implode(', ', array_map(
                fn ($colName) => $colName . ' → ' . $migrationData['columns'][$colName]['foreign']['table'],
                array_keys($foreignKeys)
            )));
        }

        // Generate Model
        $modelContent = $generator->generateModel($modelName, $migrationData);
        $modelPath = app_path("Models/{$modelName}.php");

        if (file_exists($modelPath)) {
            if (! $this->confirm("Model file already exists at {$modelPath}. Overwrite?", false)) {
                $this->warn('Skipped model generation.');
            } else {
                file_put_contents($modelPath, $modelContent);
                $this->info("Model written to: {$modelPath}");
            }
        } else {
            // Ensure directory exists
            if (! is_dir(dirname($modelPath))) {
                mkdir(dirname($modelPath), 0755, true);
            }
            file_put_contents($modelPath, $modelContent);
            $this->info("Model written to: {$modelPath}");
        }

        // Generate view config JSON
        $viewConfigContent = $generator->generateViewConfig($modelName, $migrationData);
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
