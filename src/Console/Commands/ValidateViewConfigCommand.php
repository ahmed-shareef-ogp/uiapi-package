<?php

namespace Ogp\UiApi\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Ogp\UiApi\Services\ViewConfigValidator;

class ValidateViewConfigCommand extends Command
{
    protected $signature = 'uiapi:validate
        {model? : The model name to validate (e.g. person, cform). If omitted, validates all view configs.}';

    protected $description = 'Validate view config JSON files for structural issues, missing keys, and interdependency problems.';

    public function handle(ViewConfigValidator $validator): int
    {
        $model = $this->argument('model');

        if ($model) {
            return $this->validateSingle($validator, (string) $model);
        }

        return $this->validateAll($validator);
    }

    /**
     * Validate a single model's view config.
     */
    protected function validateSingle(ViewConfigValidator $validator, string $modelName): int
    {
        $this->newLine();
        $this->components->info("Validating view config for: <fg=white;options=bold>{$modelName}</>");

        $viewConfig = $this->loadViewConfigByName($modelName);
        if ($viewConfig === null) {
            $this->components->error("View config file not found for '{$modelName}'.");

            return self::FAILURE;
        }

        $results = $validator->validate($viewConfig, $modelName);

        $this->renderResults($modelName, $results);

        return $validator->hasErrors() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Validate all view config files in the configured directory.
     */
    protected function validateAll(ViewConfigValidator $validator): int
    {
        $base = base_path(config('uiapi.view_configs_path', 'app/Services/viewConfigs'));

        if (! File::isDirectory($base)) {
            $this->components->error("View configs directory not found: {$base}");

            return self::FAILURE;
        }

        $files = File::glob($base.'/*.json');
        if (empty($files)) {
            $this->components->warn('No view config JSON files found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info('Validating all view config files...');
        $this->newLine();

        $totalErrors = 0;
        $totalWarnings = 0;
        $hasAnyErrors = false;

        foreach ($files as $file) {
            $filename = basename($file);

            // Skip copy files
            if (Str::contains($filename, ' copy')) {
                continue;
            }

            $modelName = pathinfo($filename, PATHINFO_FILENAME);
            $json = File::get($file);
            $viewConfig = json_decode($json, true);

            if ($viewConfig === null && json_last_error() !== JSON_ERROR_NONE) {
                $this->components->twoColumnDetail(
                    "<fg=white;options=bold>{$modelName}</>",
                    '<fg=red;options=bold>JSON PARSE ERROR</>'
                );
                $this->line('    <fg=red>✗</> '.json_last_error_msg());
                $this->newLine();
                $totalErrors++;
                $hasAnyErrors = true;

                continue;
            }

            $results = $validator->validate($viewConfig ?? [], $modelName);
            $errorCount = count($results['errors']);
            $warningCount = count($results['warnings']);
            $totalErrors += $errorCount;
            $totalWarnings += $warningCount;

            if ($errorCount > 0) {
                $hasAnyErrors = true;
            }

            $this->renderResults($modelName, $results);
        }

        // Summary
        $this->newLine();
        $this->components->info('Validation Summary');
        $summaryColor = $hasAnyErrors ? 'red' : ($totalWarnings > 0 ? 'yellow' : 'green');
        $this->line("  <fg={$summaryColor}>{$totalErrors} error(s), {$totalWarnings} warning(s)</>");
        $this->newLine();

        return $hasAnyErrors ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Render validation results for a single model.
     */
    protected function renderResults(string $modelName, array $results): void
    {
        $errors = $results['errors'];
        $warnings = $results['warnings'];
        $errorCount = count($errors);
        $warningCount = count($warnings);

        if ($errorCount === 0 && $warningCount === 0) {
            $this->components->twoColumnDetail(
                "<fg=white;options=bold>{$modelName}</>",
                '<fg=green;options=bold>PASS</>'
            );

            return;
        }

        $statusParts = [];
        if ($errorCount > 0) {
            $statusParts[] = "<fg=red;options=bold>{$errorCount} error(s)</>";
        }
        if ($warningCount > 0) {
            $statusParts[] = "<fg=yellow;options=bold>{$warningCount} warning(s)</>";
        }

        $this->components->twoColumnDetail(
            "<fg=white;options=bold>{$modelName}</>",
            implode(', ', $statusParts)
        );

        foreach ($errors as $error) {
            $this->line("    <fg=red>✗</> <fg=gray>[{$error['path']}]</> {$error['message']}");
        }

        foreach ($warnings as $warning) {
            $this->line("    <fg=yellow>⚠</> <fg=gray>[{$warning['path']}]</> {$warning['message']}");
        }

        $this->newLine();
    }

    /**
     * Load a view config JSON by model name, matching the CCS naming convention.
     */
    protected function loadViewConfigByName(string $modelName): ?array
    {
        $base = base_path(config('uiapi.view_configs_path', 'app/Services/viewConfigs'));
        $normalized = str_replace(['-', '_', ' '], '', Str::lower($modelName));
        $path = rtrim($base, '/').'/'.$normalized.'.json';

        if (! File::exists($path)) {
            return null;
        }

        $json = File::get($path);
        $cfg = json_decode($json, true);

        if ($cfg === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->components->error("JSON parse error in {$path}: ".json_last_error_msg());

            return null;
        }

        return $cfg ?: [];
    }
}
