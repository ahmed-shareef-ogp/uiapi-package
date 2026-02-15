<?php

namespace Ogp\UiApi\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Ogp\UiApi\Services\ModelGeneratorService;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;

class GenerateModelCommand extends Command
{
    protected $signature = 'uiapi:generate
        {name? : The model name (e.g. CForm, Invoice)}
        {--migration= : Explicit path to a Laravel migration file}
        {--sql= : Explicit path to a raw SQL dump file (CREATE TABLE)}';

    protected $description = 'Generate a Model (with apiSchema, rules, relationships) and a view config JSON from a migration file or raw SQL dump.';

    /**
     * Artificial delay (ms) between progress steps for visual feedback.
     */
    protected int $stepDelayMs = 300;

    public function handle(ModelGeneratorService $generator): int
    {
        $this->newLine();
        intro('  UiApi Model Generator  ');

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

        $this->step("Source: migration file");
        $this->step("Model: <info>{$modelName}</info>");
        $this->newLine();

        $data = spin(
            callback: function () use ($generator, $path) {
                usleep(600_000);

                return $generator->parseMigration($path);
            },
            message: 'Parsing migration file...',
        );

        if (! $data) {
            $this->error('Failed to parse migration file.');

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

        $this->step("Source: SQL dump file");
        $this->step("Model: <info>{$modelName}</info>");
        $this->newLine();

        $data = spin(
            callback: function () use ($generator, $path) {
                usleep(600_000);

                return $generator->parseSqlDump($path);
            },
            message: 'Parsing SQL dump...',
        );

        if (! $data) {
            $this->error('Failed to parse SQL dump.');

            return self::FAILURE;
        }

        return $this->writeGeneratedFiles($generator, $modelName, $data);
    }

    protected function writeGeneratedFiles(ModelGeneratorService $generator, string $modelName, array $data): int
    {
        $table = $data['table'];
        $columns = $data['columns'];
        $columnCount = count($columns);
        $foreignKeys = array_filter($columns, fn ($col) => $col['foreign'] !== null);

        // ── Phase 1: Analysis summary (with spinner) ──────────────
        $analysis = spin(
            callback: function () use ($table, $columns, $foreignKeys) {
                usleep(500_000);

                return [
                    'table' => $table,
                    'columnCount' => count($columns),
                    'columnNames' => array_keys($columns),
                    'relationships' => array_map(
                        fn ($colName) => $colName . ' → ' . $columns[$colName]['foreign']['table'],
                        array_keys($foreignKeys)
                    ),
                ];
            },
            message: 'Analyzing schema structure...',
        );

        $this->newLine();
        $this->step("Table: <info>{$analysis['table']}</info>");
        $this->step("Columns ({$analysis['columnCount']}): <comment>" . implode(', ', $analysis['columnNames']) . '</comment>');
        if (! empty($analysis['relationships'])) {
            $this->step('Relationships: <comment>' . implode(', ', $analysis['relationships']) . '</comment>');
        }
        $this->newLine();

        // ── Phase 2: Generation progress bar ──────────────────────
        $modelContent = null;
        $viewConfigContent = null;

        $steps = [
            'Building apiSchema columns'       => fn () => null,
            'Building validation rules'         => fn () => null,
            'Building relationship methods'     => fn () => null,
            'Generating Model PHP'              => function () use ($generator, $modelName, $data, &$modelContent) {
                $modelContent = $generator->generateModel($modelName, $data);
            },
            'Generating view config JSON'       => function () use ($generator, $modelName, $data, &$viewConfigContent) {
                $viewConfigContent = $generator->generateViewConfig($modelName, $data);
            },
            'Finalizing output'                 => fn () => null,
        ];

        $delay = $this->stepDelayMs * 1000;

        progress(
            label: 'Generating files',
            steps: array_keys($steps),
            callback: function (string $step) use ($steps, $delay) {
                usleep($delay);
                $steps[$step]();
            },
            hint: 'This may take a moment...',
        );

        $this->newLine();

        // ── Phase 3: Write files ──────────────────────────────────
        $modelPath = app_path("Models/{$modelName}.php");

        if (file_exists($modelPath)) {
            if (! $this->confirm("Model file already exists at {$modelPath}. Overwrite?", false)) {
                $this->warn('  Skipped model generation.');
            } else {
                file_put_contents($modelPath, $modelContent);
                $this->step("Model written → <info>{$modelPath}</info>");
            }
        } else {
            if (! is_dir(dirname($modelPath))) {
                mkdir(dirname($modelPath), 0755, true);
            }
            file_put_contents($modelPath, $modelContent);
            $this->step("Model written → <info>{$modelPath}</info>");
        }

        $viewConfigsPath = base_path(config('uiapi.view_configs_path', 'app/Services/viewConfigs'));
        $normalizedName = strtolower(str_replace(['-', '_', ' '], '', $modelName));
        $jsonPath = rtrim($viewConfigsPath, '/') . '/' . $normalizedName . '.json';

        if (file_exists($jsonPath)) {
            if (! $this->confirm("View config already exists at {$jsonPath}. Overwrite?", false)) {
                $this->warn('  Skipped view config generation.');
            } else {
                file_put_contents($jsonPath, $viewConfigContent);
                $this->step("View config written → <info>{$jsonPath}</info>");
            }
        } else {
            if (! is_dir(dirname($jsonPath))) {
                mkdir(dirname($jsonPath), 0755, true);
            }
            file_put_contents($jsonPath, $viewConfigContent);
            $this->step("View config written → <info>{$jsonPath}</info>");
        }

        // ── Phase 4: Summary ──────────────────────────────────────
        $this->newLine();
        outro('  Generation complete!  ');
        $this->newLine();
        $this->line('  <fg=yellow>Review & adjust:</>');
        $this->line('    • Dhivehi (dv) labels marked as <comment>"TODO"</comment> in apiSchema() and view config');
        $this->line('    • Relationship model class references');
        $this->line('    • Validation rules for business-specific constraints');
        $this->line('    • View config component overrides as needed');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Print a styled step line with a bullet.
     */
    protected function step(string $message): void
    {
        $this->line("  <fg=cyan>▸</> {$message}");
    }
}
