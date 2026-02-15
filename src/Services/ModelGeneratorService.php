<?php

namespace Ogp\UiApi\Services;

use Illuminate\Support\Str;

class ModelGeneratorService
{
    /**
     * Parse a migration file and extract column definitions.
     *
     * @return array{table: string, columns: array<string, array{type: string, nullable: bool, default: mixed, unique: bool, foreign: ?array{table: string, column: string}}>, timestamps: bool}
     */
    public function parseMigration(string $migrationPath): array
    {
        if (! file_exists($migrationPath)) {
            throw new \InvalidArgumentException("Migration file not found: {$migrationPath}");
        }

        $content = file_get_contents($migrationPath);

        $table = $this->extractTableName($content);
        if (! $table) {
            throw new \InvalidArgumentException('Could not determine table name from migration.');
        }

        $columns = $this->extractColumns($content);
        $timestamps = (bool) preg_match('/\$table->timestamps\s*\(\s*\)/', $content);

        return [
            'table' => $table,
            'columns' => $columns,
            'timestamps' => $timestamps,
        ];
    }

    protected function extractTableName(string $content): ?string
    {
        // Match Schema::create('table_name', ...)
        if (preg_match('/Schema::create\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<string, array{type: string, nullable: bool, default: mixed, unique: bool, foreign: ?array{table: string, column: string}}>
     */
    protected function extractColumns(string $content): array
    {
        $columns = [];

        // Match column definitions: $table->type('name', ...) with optional chained methods
        $pattern = '/\$table\s*->\s*(id|uuid|string|text|longText|integer|bigInteger|unsignedBigInteger|unsignedInteger|tinyInteger|smallInteger|boolean|date|dateTime|timestamp|decimal|float|double|json|foreignId)\s*\(\s*(?:[\'"]([^\'"]*)[\'"])?\s*(?:,\s*[^)]+)?\s*\)([^;]*);/';

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $colType = $match[1];
            $colName = $match[2] ?? '';
            $chain = $match[3] ?? '';

            // id() with no name defaults to 'id'
            if ($colType === 'id' && $colName === '') {
                $colName = 'id';
            }

            if ($colName === '') {
                continue;
            }

            $nullable = (bool) preg_match('/->nullable\s*\(/', $chain);
            $unique = (bool) preg_match('/->unique\s*\(/', $chain);

            $default = null;
            if (preg_match('/->default\s*\(\s*[\'"]([^\'"]*)[\'"]\s*\)/', $chain, $dm)) {
                $default = $dm[1];
            } elseif (preg_match('/->default\s*\(\s*([^)]+)\s*\)/', $chain, $dm)) {
                $default = trim($dm[1]);
            }

            // Detect foreign key references
            $foreign = null;
            if ($colType === 'foreignId') {
                // foreignId('user_id')->constrained() => references users table
                if (preg_match('/->constrained\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $chain, $fm)) {
                    $foreign = ['table' => $fm[1], 'column' => 'id'];
                } elseif (preg_match('/->constrained\s*\(\s*\)/', $chain)) {
                    // Infer table from column name: user_id -> users
                    $base = Str::beforeLast($colName, '_id');
                    $foreign = ['table' => Str::plural(Str::snake($base)), 'column' => 'id'];
                }
                // Also check ->references('id')->on('table')
                if (! $foreign && preg_match('/->references\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)\s*->on\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $chain, $fm)) {
                    $foreign = ['table' => $fm[2], 'column' => $fm[1]];
                }
            }

            // Also check for standalone foreign() declarations referencing this column
            // $table->foreign('col')->references('id')->on('table')
            // This is handled separately below

            $columns[$colName] = [
                'type' => $this->normalizeMigrationColType($colType),
                'nullable' => $nullable,
                'default' => $default,
                'unique' => $unique,
                'foreign' => $foreign,
            ];
        }

        // Parse standalone foreign key declarations
        $foreignPattern = '/\$table\s*->foreign\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)\s*->references\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)\s*->on\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/';
        preg_match_all($foreignPattern, $content, $fMatches, PREG_SET_ORDER);
        foreach ($fMatches as $fm) {
            $colName = $fm[1];
            if (isset($columns[$colName]) && $columns[$colName]['foreign'] === null) {
                $columns[$colName]['foreign'] = ['table' => $fm[3], 'column' => $fm[2]];
            }
        }

        return $columns;
    }

    protected function normalizeMigrationColType(string $migType): string
    {
        return match ($migType) {
            'id', 'bigInteger', 'unsignedBigInteger', 'unsignedInteger', 'integer', 'tinyInteger', 'smallInteger', 'foreignId' => 'integer',
            'boolean' => 'boolean',
            'decimal', 'float', 'double' => 'number',
            'date', 'dateTime', 'timestamp' => 'date',
            'json' => 'json',
            'uuid' => 'string',
            'text', 'longText' => 'string',
            default => 'string',
        };
    }

    // ─── SQL Dump Parsing ───────────────────────────────────────────

    /**
     * Parse a raw SQL dump file and extract column definitions.
     *
     * Accepts output from mysqldump --no-data or phpMyAdmin SQL export.
     * Returns the same structure as parseMigration() so generateModel()
     * and generateViewConfig() work unchanged.
     *
     * @return array{table: string, columns: array<string, array{type: string, nullable: bool, default: mixed, unique: bool, foreign: ?array{table: string, column: string}}>, timestamps: bool}
     */
    public function parseSqlDump(string $sqlPath): array
    {
        if (! file_exists($sqlPath)) {
            throw new \InvalidArgumentException("SQL file not found: {$sqlPath}");
        }

        $content = file_get_contents($sqlPath);

        $table = $this->extractTableNameFromSql($content);
        if (! $table) {
            throw new \InvalidArgumentException('Could not determine table name from SQL dump.');
        }

        $columns = $this->extractColumnsFromSql($content);
        if (empty($columns)) {
            throw new \InvalidArgumentException('No columns could be parsed from SQL dump.');
        }

        $foreignKeys = $this->extractForeignKeysFromSql($content);
        foreach ($foreignKeys as $colName => $fk) {
            if (isset($columns[$colName]) && $columns[$colName]['foreign'] === null) {
                $columns[$colName]['foreign'] = $fk;
            }
        }

        // Infer FK from _id suffix columns that have an index but no explicit constraint
        foreach ($columns as $colName => $colDef) {
            if ($colDef['foreign'] === null && Str::endsWith($colName, '_id')) {
                $base = Str::beforeLast($colName, '_id');
                $columns[$colName]['foreign'] = [
                    'table' => Str::plural(Str::snake($base)),
                    'column' => 'id',
                ];
            }
        }

        $timestamps = isset($columns['created_at']) && isset($columns['updated_at']);

        return [
            'table' => $table,
            'columns' => $columns,
            'timestamps' => $timestamps,
        ];
    }

    protected function extractTableNameFromSql(string $content): ?string
    {
        if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\']?(\w+)[`"\']?\s*\(/i', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<string, array{type: string, nullable: bool, default: mixed, unique: bool, foreign: ?array{table: string, column: string}}>
     */
    protected function extractColumnsFromSql(string $content): array
    {
        // Extract the CREATE TABLE body between the parentheses
        if (! preg_match('/CREATE\s+TABLE\s+[^(]+\((.*)\)\s*(ENGINE|DEFAULT|CHARSET|COLLATE|;)/si', $content, $m)) {
            return [];
        }

        $body = $m[1];
        $columns = [];

        // Split by lines and process each column definition
        $lines = preg_split('/\n/', $body);
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip empty lines, constraints, keys, indexes
            if ($line === '' || preg_match('/^\s*(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN|CHECK|ADD)\s/i', $line)) {
                continue;
            }
            // Skip lines that are just closing parentheses or commas
            if (preg_match('/^\s*[),]?\s*$/', $line)) {
                continue;
            }

            $parsed = $this->parseSqlColumnLine($line);
            if ($parsed !== null) {
                $columns[$parsed['name']] = [
                    'type' => $parsed['type'],
                    'nullable' => $parsed['nullable'],
                    'default' => $parsed['default'],
                    'unique' => $parsed['unique'],
                    'foreign' => null,
                ];
            }
        }

        return $columns;
    }

    /**
     * Parse a single SQL column definition line.
     *
     * @return array{name: string, type: string, nullable: bool, default: mixed, unique: bool}|null
     */
    protected function parseSqlColumnLine(string $line): ?array
    {
        // Remove trailing comma
        $line = rtrim($line, ',');

        // Match: `column_name` type(...) [UNSIGNED] [NOT NULL|NULL] [DEFAULT ...] [UNIQUE] ...
        $pattern = '/^[`"\']?(\w+)[`"\']?\s+(\w+)(?:\(([^)]*)\))?\s*(.*)/i';
        if (! preg_match($pattern, $line, $m)) {
            return null;
        }

        $name = $m[1];
        $sqlType = strtolower($m[2]);
        $typeParam = $m[3] ?? '';
        $rest = $m[4] ?? '';

        $normalizedType = $this->normalizeSqlColType($sqlType, $typeParam);

        // Determine nullable: NOT NULL means not nullable; otherwise nullable
        $nullable = ! preg_match('/NOT\s+NULL/i', $rest);

        // Extract default value
        $default = null;
        if (preg_match("/DEFAULT\s+'([^']*)'/i", $rest, $dm)) {
            $default = $dm[1];
        } elseif (preg_match('/DEFAULT\s+NULL/i', $rest)) {
            $default = null;
        } elseif (preg_match('/DEFAULT\s+(\S+)/i', $rest, $dm)) {
            $val = rtrim($dm[1], ',');
            if ($val !== 'NULL') {
                $default = $val;
            }
        }

        $unique = (bool) preg_match('/UNIQUE/i', $rest);

        return [
            'name' => $name,
            'type' => $normalizedType,
            'nullable' => $nullable,
            'default' => $default,
            'unique' => $unique,
        ];
    }

    /**
     * Extract explicit FOREIGN KEY constraints from ALTER TABLE or CREATE TABLE.
     *
     * @return array<string, array{table: string, column: string}>
     */
    protected function extractForeignKeysFromSql(string $content): array
    {
        $foreignKeys = [];

        // ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY (`col`) REFERENCES `table` (`col`)
        // Also covers inline FOREIGN KEY in CREATE TABLE
        $pattern = '/FOREIGN\s+KEY\s*\(\s*[`"\']?(\w+)[`"\']?\s*\)\s*REFERENCES\s+[`"\']?(\w+)[`"\']?\s*\(\s*[`"\']?(\w+)[`"\']?\s*\)/i';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $foreignKeys[$match[1]] = [
                'table' => $match[2],
                'column' => $match[3],
            ];
        }

        return $foreignKeys;
    }

    protected function normalizeSqlColType(string $sqlType, string $typeParam = ''): string
    {
        // tinyint(1) is conventionally boolean in MySQL
        if ($sqlType === 'tinyint' && $typeParam === '1') {
            return 'boolean';
        }

        return match ($sqlType) {
            'int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint' => 'integer',
            'decimal', 'float', 'double', 'numeric', 'real' => 'number',
            'date' => 'date',
            'datetime', 'timestamp' => 'date',
            'json', 'jsonb' => 'json',
            'bool', 'boolean' => 'boolean',
            'text', 'longtext', 'mediumtext', 'tinytext' => 'string',
            'varchar', 'char', 'enum', 'set' => 'string',
            'blob', 'longblob', 'mediumblob', 'tinyblob', 'binary', 'varbinary' => 'string',
            'uuid' => 'string',
            default => 'string',
        };
    }

    /**
     * Generate the Model PHP file content.
     */
    public function generateModel(string $modelName, array $migrationData): string
    {
        $table = $migrationData['table'];
        $columns = $migrationData['columns'];
        $timestamps = $migrationData['timestamps'];

        $searchable = $this->inferSearchable($columns);
        $casts = $this->buildCasts($columns, $timestamps);
        $apiSchema = $this->buildApiSchemaString($columns);
        $baseRules = $this->buildBaseRulesString($columns, $table);
        $createRules = $this->buildCreateRulesString($table);
        $updateRules = $this->buildUpdateRulesString($table);
        $validationMessages = $this->buildValidationMessagesString($columns, $modelName);
        $relationships = $this->buildRelationshipsString($columns);

        $searchableStr = $this->arrayToPhpString($searchable, 2);
        $castsStr = $this->buildCastsMethodString($casts);

        $timestampsProperty = '';
        if (! $timestamps) {
            $timestampsProperty = "\n    public \$timestamps = false;\n";
        }

        $useStatements = "use Illuminate\\Validation\\Rule;\n";

        return <<<PHP
<?php

namespace App\\Models;

use Ogp\\UiApi\\Models\\BaseModel;
{$useStatements}
class {$modelName} extends BaseModel
{
    protected \$table = '{$table}';
{$timestampsProperty}
    protected array \$searchable = {$searchableStr};

{$castsStr}
{$apiSchema}

{$baseRules}

{$createRules}

{$updateRules}

{$validationMessages}
{$relationships}
}

PHP;
    }

    /**
     * Generate the view config JSON content.
     */
    public function generateViewConfig(string $modelName, array $migrationData): string
    {
        $columns = $migrationData['columns'];

        $visibleColumns = $this->getVisibleColumnKeys($columns);
        $allColumns = array_keys($columns);

        // Add relation dot-columns for foreign keys
        $dotColumns = [];
        foreach ($columns as $colName => $colDef) {
            if ($colDef['foreign'] !== null) {
                $relationName = $this->foreignKeyToRelationName($colName);
                $relatedTable = $colDef['foreign']['table'];
                // Guess a display column on the related table
                $displayCol = $this->guessRelatedDisplayColumn($relatedTable);
                $dotColumns[] = $relationName . '.' . $displayCol;
            }
        }

        $tableColumns = array_merge(
            array_filter($allColumns, fn ($c) => ! in_array($c, ['created_at', 'updated_at'])),
            $dotColumns
        );

        $rootColumns = $tableColumns;

        // Build columnCustomizations for the table component
        $tableCustomizations = $this->buildColumnCustomizationsForConfig($columns);

        // Build form fields
        $formFields = $this->buildFormFieldsForConfig($columns);

        // Build filters list (enum-like + FK columns)
        $filters = $this->inferFilters($columns);

        $config = [
            'listView' => [
                'components' => [
                    'table' => [
                        'columns' => array_values($tableColumns),
                        'columnCustomizations' => $tableCustomizations,
                    ],
                    'form' => [
                        'createTitle' => [
                            'en' => 'Create ' . $this->humanize($modelName),
                            'dv' => 'TODO',
                        ],
                        'editTitle' => [
                            'en' => 'Edit ' . $this->humanize($modelName),
                            'dv' => 'TODO',
                        ],
                        'groups' => [
                            [
                                'name' => 'General',
                                'title' => [
                                    'en' => 'General Information',
                                    'dv' => 'TODO',
                                ],
                                'numberOfColumns' => 2,
                            ],
                        ],
                        'fields' => $formFields,
                    ],
                    'toolbar' => [
                        'title' => [
                            'en' => Str::plural($this->humanize($modelName)),
                            'dv' => 'TODO',
                        ],
                        'filters' => array_values($filters),
                        'buttons' => ['search', 'clear'],
                    ],
                    'filterSection' => [
                        'filters' => array_values($filters),
                        'buttons' => ['submit', 'clear'],
                    ],
                    'meta' => new \stdClass,
                ],
                'columns' => array_values($rootColumns),
                'columnCustomizations' => $tableCustomizations,
                'filters' => array_values($filters),
                'per_page' => 15,
                'lang' => ['en', 'dv'],
            ],
        ];

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ─── Internal helpers ───────────────────────────────────────────

    /**
     * @return array<int, string>
     */
    protected function inferSearchable(array $columns): array
    {
        $searchable = [];
        foreach ($columns as $name => $def) {
            if (in_array($name, ['id', 'uuid'])) {
                $searchable[] = $name;

                continue;
            }
            if ($def['type'] === 'string' && ! Str::endsWith($name, '_id')) {
                $searchable[] = $name;
            }
        }

        return $searchable;
    }

    /**
     * @return array<string, string>
     */
    protected function buildCasts(array $columns, bool $timestamps): array
    {
        $casts = [];
        foreach ($columns as $name => $def) {
            if ($name === 'id') {
                continue;
            }
            if (in_array($name, ['created_at', 'updated_at'])) {
                continue;
            }
            if ($def['type'] === 'date' && ! in_array($name, ['created_at', 'updated_at'])) {
                $casts[$name] = 'datetime';
            }
            if ($def['type'] === 'boolean') {
                $casts[$name] = 'boolean';
            }
            if ($def['type'] === 'json') {
                $casts[$name] = 'array';
            }
        }
        if ($timestamps) {
            $casts['created_at'] = 'datetime:Y-m-d H:i';
        }

        return $casts;
    }

    protected function buildCastsMethodString(array $casts): string
    {
        if (empty($casts)) {
            return <<<'PHP'
    protected function casts(): array
    {
        return [];
    }
PHP;
        }

        $lines = [];
        foreach ($casts as $key => $cast) {
            $lines[] = "            '{$key}' => '{$cast}',";
        }
        $body = implode("\n", $lines);

        return <<<PHP
    protected function casts(): array
    {
        return [
{$body}
        ];
    }
PHP;
    }

    protected function buildApiSchemaString(array $columns): string
    {
        $entries = [];
        foreach ($columns as $colName => $colDef) {
            $entry = $this->buildApiSchemaColumnEntry($colName, $colDef);
            $entries[] = $entry;
        }

        $body = implode("\n", $entries);

        return <<<PHP
    public function apiSchema(): array
    {
        return [
            'columns' => [
{$body}
            ],
            'searchable' => \$this->searchable,
        ];
    }
PHP;
    }

    protected function buildApiSchemaColumnEntry(string $colName, array $colDef): string
    {
        $hidden = $this->isHiddenColumn($colName) ? 'true' : 'false';
        $label = $this->humanize($colName);
        $type = $this->apiSchemaType($colDef['type']);
        $displayType = $this->apiSchemaDisplayType($colDef['type'], $colName);
        $inputType = $this->apiSchemaInputType($colDef['type'], $colName, $colDef);
        $formField = $this->isFormField($colName) ? 'true' : 'false';
        $sortable = $this->isSortableColumn($colName, $colDef) ? 'true' : 'false';

        $lines = [];
        $lines[] = "                '{$colName}' => [";
        $lines[] = "                    'hidden' => {$hidden},";
        $lines[] = "                    'key' => '{$colName}',";
        $lines[] = "                    'label' => ['en' => '{$label}', 'dv' => 'TODO'],";
        $lines[] = "                    'lang' => ['en', 'dv'],";
        $lines[] = "                    'type' => '{$type}',";
        $lines[] = "                    'displayType' => '{$displayType}',";
        $lines[] = "                    'inputType' => '{$inputType}',";
        $lines[] = "                    'formField' => {$formField},";
        $lines[] = "                    'sortable' => {$sortable},";

        // Add select config for FK columns
        if ($colDef['foreign'] !== null) {
            $relTable = $colDef['foreign']['table'];
            $relModelName = Str::studly(Str::singular($relTable));
            $displayCol = $this->guessRelatedDisplayColumn($relTable);
            $lines[] = "                    'filterable' => [";
            $lines[] = "                        'mode' => 'relation',";
            $lines[] = "                        'relationship' => '" . $this->foreignKeyToRelationName($colName) . "',";
            $lines[] = "                        'itemTitle' => '{$displayCol}',";
            $lines[] = "                        'itemValue' => 'id',";
            $lines[] = "                    ],";
        }

        $lines[] = '                ],';

        return implode("\n", $lines);
    }

    protected function buildBaseRulesString(array $columns, string $table): string
    {
        $lines = [];
        foreach ($columns as $colName => $colDef) {
            $rule = $this->buildValidationRule($colName, $colDef, $table);
            $lines[] = "            '{$colName}' => [{$rule}],";
        }
        $body = implode("\n", $lines);

        return <<<PHP
    public static function rules(?int \$id = null): array
    {
        return \$id === null ? static::rulesForCreate() : static::rulesForUpdate(\$id);
    }

    public static function baseRules(): array
    {
        return [
{$body}
        ];
    }
PHP;
    }

    protected function buildCreateRulesString(string $table): string
    {
        return <<<PHP
    public static function rulesForCreate(): array
    {
        \$rules = static::baseRules();
        \$rules['id'] = ['sometimes', 'integer', Rule::unique('{$table}', 'id')];

        return \$rules;
    }
PHP;
    }

    protected function buildUpdateRulesString(string $table): string
    {
        return <<<PHP
    public static function rulesForUpdate(?int \$id = null): array
    {
        \$rules = static::baseRules();
        \$rules['id'] = \$id !== null
            ? ['required', 'integer', Rule::unique('{$table}', 'id')->ignore(\$id)]
            : ['required', 'integer'];

        return \$rules;
    }
PHP;
    }

    protected function buildValidationMessagesString(array $columns, string $modelName): string
    {
        $lines = [];
        foreach ($columns as $colName => $colDef) {
            if ($colName === 'id') {
                $lines[] = "            'id.unique' => [";
                $lines[] = "                'en' => 'This record already exists.',";
                $lines[] = "                'dv' => 'TODO',";
                $lines[] = '            ],';

                continue;
            }
            if (! $colDef['nullable'] && ! in_array($colName, ['created_at', 'updated_at'])) {
                $label = $this->humanize($colName);
                $lines[] = "            '{$colName}.required' => [";
                $lines[] = "                'en' => '{$label} is required.',";
                $lines[] = "                'dv' => 'TODO',";
                $lines[] = '            ],';
            }
        }
        if (empty($lines)) {
            return <<<'PHP'
    public static function validationMessages(): array
    {
        return [];
    }
PHP;
        }
        $body = implode("\n", $lines);

        return <<<PHP
    public static function validationMessages(): array
    {
        return [
{$body}
        ];
    }
PHP;
    }

    protected function buildRelationshipsString(array $columns): string
    {
        $methods = [];
        foreach ($columns as $colName => $colDef) {
            if ($colDef['foreign'] === null) {
                continue;
            }
            $relationName = $this->foreignKeyToRelationName($colName);
            $relatedTable = $colDef['foreign']['table'];
            $relatedModel = Str::studly(Str::singular($relatedTable));

            // Try package model first, then app model
            $relatedClass = "\\Ogp\\UiApi\\Models\\{$relatedModel}";
            $fallbackClass = "\\App\\Models\\{$relatedModel}";

            $methods[] = <<<PHP

    public function {$relationName}(): \\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo
    {
        // Adjust the related model class if needed ({$relatedClass} or {$fallbackClass})
        return \$this->belongsTo({$relatedModel}::class, '{$colName}');
    }
PHP;
        }

        return implode("\n", $methods);
    }

    protected function buildValidationRule(string $colName, array $colDef, string $table = ''): string
    {
        $parts = [];

        if ($colName === 'id') {
            return "'sometimes', 'integer'";
        }

        if (in_array($colName, ['created_at', 'updated_at'])) {
            return "'nullable', 'date'";
        }

        if ($colDef['nullable']) {
            $parts[] = "'nullable'";
        } else {
            $parts[] = "'required'";
        }

        $parts[] = match ($colDef['type']) {
            'integer' => "'integer'",
            'number' => "'numeric'",
            'boolean' => "'boolean'",
            'date' => "'date'",
            'json' => "'array'",
            default => "'string'",
        };

        if ($colDef['type'] === 'string') {
            $parts[] = "'max:255'";
        }

        if ($colDef['unique']) {
            $tbl = $table !== '' ? $table : 'TODO_TABLE';
            $parts[] = "'unique:{$tbl},{$colName}'";
        }

        if ($colDef['foreign'] !== null) {
            $fTable = $colDef['foreign']['table'];
            $fCol = $colDef['foreign']['column'];
            $parts[] = "'exists:{$fTable},{$fCol}'";
        }

        return implode(', ', $parts);
    }

    /**
     * @return array<int, string>
     */
    protected function getVisibleColumnKeys(array $columns): array
    {
        return array_values(array_filter(
            array_keys($columns),
            fn ($c) => ! $this->isHiddenColumn($c)
        ));
    }

    /**
     * Build columnCustomizations for the view config JSON.
     */
    protected function buildColumnCustomizationsForConfig(array $columns): array
    {
        $customizations = [];
        foreach ($columns as $colName => $colDef) {
            if ($colDef['type'] === 'date' && ! in_array($colName, ['created_at', 'updated_at'])) {
                $customizations[$colName] = [
                    'displayType' => 'date',
                    'sortable' => true,
                ];
            }
            if (in_array($colName, ['created_at'])) {
                $customizations[$colName] = [
                    'hidden' => false,
                    'width' => '180px',
                    'displayType' => 'date',
                    'sortable' => true,
                ];
            }
            if ($colDef['type'] === 'boolean') {
                $customizations[$colName] = [
                    'displayType' => 'checkbox',
                    'sortable' => true,
                    'inlineEditable' => true,
                ];
            }
        }

        return $customizations;
    }

    /**
     * Build form fields for the view config JSON.
     *
     * @return array<int, array{key: string, group: string}>
     */
    protected function buildFormFieldsForConfig(array $columns): array
    {
        $fields = [];
        foreach ($columns as $colName => $colDef) {
            if (! $this->isFormField($colName)) {
                continue;
            }
            $field = [
                'key' => $colName,
                'group' => 'General',
            ];
            if ($this->isHiddenColumn($colName)) {
                $field['hidden'] = true;
            }
            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Infer filter columns from migration data.
     *
     * @return array<int, string>
     */
    protected function inferFilters(array $columns): array
    {
        $filters = [];
        foreach ($columns as $colName => $colDef) {
            // Include FK columns and boolean columns as filters
            if ($colDef['foreign'] !== null) {
                $filters[] = $colName;
            }
            if ($colDef['type'] === 'boolean') {
                $filters[] = $colName;
            }
        }

        return $filters;
    }

    protected function isHiddenColumn(string $colName): bool
    {
        return in_array($colName, ['id', 'uuid', 'updated_at'])
            || Str::endsWith($colName, '_id')
            || Str::endsWith($colName, '_by');
    }

    protected function isFormField(string $colName): bool
    {
        return ! in_array($colName, ['id', 'created_at', 'updated_at'])
            && ! Str::endsWith($colName, '_by');
    }

    protected function isSortableColumn(string $colName, array $colDef): bool
    {
        return in_array($colName, ['id', 'created_at', 'updated_at'])
            || in_array($colDef['type'], ['integer', 'number', 'date', 'boolean']);
    }

    protected function foreignKeyToRelationName(string $colName): string
    {
        $base = Str::beforeLast($colName, '_id');

        return Str::camel($base);
    }

    protected function guessRelatedDisplayColumn(string $relatedTable): string
    {
        // Common display column names
        $common = ['name', 'title', 'name_eng', 'name_en', 'first_name_eng', 'label'];
        // For now return 'name' as a safe default; the developer should adjust
        return 'name';
    }

    protected function humanize(string $name): string
    {
        return Str::title(str_replace(['_', '-'], ' ', $name));
    }

    protected function apiSchemaType(string $type): string
    {
        return match ($type) {
            'integer' => 'number',
            'number' => 'number',
            'boolean' => 'boolean',
            'date' => 'date',
            'json' => 'json',
            default => 'string',
        };
    }

    protected function apiSchemaDisplayType(string $type, string $colName): string
    {
        return match (true) {
            $type === 'date' => 'date',
            $type === 'boolean' => 'checkbox',
            default => 'text',
        };
    }

    protected function apiSchemaInputType(string $type, string $colName, array $colDef): string
    {
        if ($colDef['foreign'] !== null) {
            return 'select';
        }

        return match ($type) {
            'integer', 'number' => 'numberField',
            'boolean' => 'checkbox',
            'date' => 'dateField',
            default => 'textField',
        };
    }

    protected function arrayToPhpString(array $arr, int $indent = 1): string
    {
        if (empty($arr)) {
            return '[]';
        }

        $pad = str_repeat('    ', $indent);
        $innerPad = str_repeat('    ', $indent + 1);
        $lines = ["["];
        foreach ($arr as $val) {
            $lines[] = "{$innerPad}'{$val}',";
        }
        $lines[] = "{$pad}]";

        return implode("\n", $lines);
    }
}
