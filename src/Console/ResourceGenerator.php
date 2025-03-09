<?php

namespace OpenAdmin\Admin\Console;

use Illuminate\Database\Eloquent\Model;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Types\Type;
use Illuminate\Support\Str;
use \Illuminate\Filesystem\Filesystem;

class ResourceGenerator
{
    /**
     * @var Model
     */
    protected $model;

    protected $resource;

    /**
     * @var array
     */
    protected $formats = [
        'form_field'  => "\$form->%s('%s', trans('%s.%s'))",
        'show_field'  => "\$show->field('%s', trans('%s.%s'))",
        'grid_column' => "\$grid->column('%s', trans('%s.%s'))",
    ];

    /**
     * @var array
     */
    private $doctrineTypeMapping = [
        'string' => [
            'enum', 'geometry', 'geometrycollection', 'linestring',
            'polygon', 'multilinestring', 'multipoint', 'multipolygon',
            'point',
        ],
    ];

    /**
     * @var array
     */
    protected $fieldTypeMapping = [
        'ip'          => 'ip',
        'email'       => 'email|mail',
        'password'    => 'password|pwd',
        'url'         => 'url|link|src|href',
        'phonenumber' => 'mobile|phone',
        'color'       => 'color|rgb',
        'image'       => 'image|img|avatar|pic|picture|cover',
        'file'        => 'file|attachment',
    ];

    /**
     * ResourceGenerator constructor.
     *
     * @param mixed $model
     */
    public function __construct($model)
    {
        $this->model = $this->getModel($model);
        
        $this->resource = $this->getResourceName($model);

        $this->makeTranslations();
    }

    /**
     * @param mixed $model
     *
     * @return mixed
     */
    protected function getModel($model)
    {
        if ($model instanceof Model) {
            return $model;
        }

        if (!class_exists($model) || !is_string($model) || !is_subclass_of($model, Model::class)) {
            throw new \InvalidArgumentException("Invalid model [$model] !");
        }

        return new $model();
    }

    /**
     * @return string
     */
    public function generateForm()
    {
        $reservedColumns = $this->getReservedColumns();

        $output = '';

        foreach ($this->getTableColumns() as $column) {
            $name = $column->getName();
            if (in_array($name, $reservedColumns)) {
                continue;
            }
            
            // $type = $column->getType()->getName();
            $type_obj = $column->getType();
            $type = Type::lookupName($type_obj);

            $default = $column->getDefault();

            $defaultValue = '';

            // set column fieldType and defaultValue
            switch ($type) {
                case 'boolean':
                case 'bool':
                    $fieldType = 'switch';
                    break;
                case 'json':
                case 'array':
                case 'object':
                    $fieldType = 'text';
                    break;
                case 'string':
                    $fieldType = 'text';
                    foreach ($this->fieldTypeMapping as $type => $regex) {
                        if (preg_match("/^($regex)$/i", $name) !== 0) {
                            $fieldType = $type;
                            break;
                        }
                    }
                    $defaultValue = "'{$default}'";
                    break;
                case 'integer':
                case 'bigint':
                case 'smallint':
                case 'timestamp':
                    $fieldType = 'number';
                    break;
                case 'decimal':
                case 'float':
                case 'real':
                    $fieldType = 'decimal';
                    break;
                case 'datetime':
                    $fieldType = 'datetime';
                    $defaultValue = "date('Y-m-d H:i:s')";
                    break;
                case 'date':
                    $fieldType = 'date';
                    $defaultValue = "date('Y-m-d')";
                    break;
                case 'time':
                    $fieldType = 'time';
                    $defaultValue = "date('H:i:s')";
                    break;
                case 'text':
                case 'blob':
                    $fieldType = 'textarea';
                    break;
                default:
                    $fieldType = 'text';
                    $defaultValue = "'{$default}'";
            }

            $defaultValue = $defaultValue ?: $default;

            $label = $this->formatLabel($name);

            $output .= sprintf($this->formats['form_field'], $fieldType, $name, $this->resource, $label);

            if (trim($defaultValue, "'\"")) {
                $output .= "->default({$defaultValue})";
            }

            $output .= ";\r\n";
        }

        return $output;
    }

    public function generateShow()
    {
        $output = '';

        foreach ($this->getTableColumns() as $column) {
            $name = $column->getName();

            // set column label
            $label = $this->formatLabel($name);

            $output .= sprintf($this->formats['show_field'], $name, $this->resource, $label);

            $output .= ";\r\n";
        }

        return $output;
    }

    public function generateGrid()
    {
        $output = '';
        foreach ($this->getTableColumns() as $column) {
            $name = $column->getName();
            $label = $this->formatLabel($name);

            $output .= sprintf($this->formats['grid_column'], $name, $this->resource, $label);
            $output .= ";\r\n";
        }

        return $output;
    }

    protected function getReservedColumns()
    {
        return [
            $this->model->getKeyName(),
            $this->model->getCreatedAtColumn(),
            $this->model->getUpdatedAtColumn(),
            'deleted_at',
        ];
    }

    /**
     * Get columns of a giving model.
     *
     * @throws \Exception
     *
     * @return \Doctrine\DBAL\Schema\Column[]
     */
    protected function getTableColumns()
    {
         // Check if Doctrine is available
        if (!class_exists(\Doctrine\DBAL\Connection::class)) {
            throw new \Exception(
                'You need to require doctrine/dbal in your composer.json to get database columns.'
            );
        }

        // Get the database connection details
        $connection = $this->model->getConnection();
        $config = new Configuration();
        $connectionParams = [
            'dbname' => $connection->getDatabaseName(),
            'user' => $connection->getConfig('username'),
            'password' => $connection->getConfig('password'),
            'host' => $connection->getConfig('host'),
            'driver' => $connection->getDriverName(),
            'port' => $connection->getConfig('port'),
        ];

        // Create the Doctrine DBAL connection
        $doctrineConnection = DriverManager::getConnection($connectionParams, $config);

        // Get the Schema Manager from Doctrine
        $schemaManager = $doctrineConnection->createSchemaManager();

        // Map custom database types to Doctrine types
        $databasePlatform = $doctrineConnection->getDatabasePlatform();

        foreach ($this->doctrineTypeMapping as $doctrineType => $dbTypes) {
            foreach ($dbTypes as $dbType) {
                $databasePlatform->registerDoctrineTypeMapping($dbType, $doctrineType);
            }
        }

        // Handle table names with database prefixes
        $table = $this->model->getConnection()->getTablePrefix() . $this->model->getTable();
        $database = $connection->getDatabaseName();
        if (strpos($table, '.') !== false) {
            [$database, $table] = explode('.', $table);
        }

        // Return the table columns
        return $schemaManager->listTableColumns($table, $database);
    }

    /**
     * Format label.
     *
     * @param string $value
     *
     * @return string
     */
    protected function formatLabel($value)
    {
        return Str::of($value)->snake();
    }

    private function getResourceName($model)
    {
        return strtolower(class_basename($model));
    }

    private function getTitle()
    {
        return Str::of($this->resource)->headline();
    }

    private function getTitlePlural()
    {
        return Str::of($this->getTitle())->plural()->lower();
    }

    private function getLabels()
    {
        $output = '';
        foreach ($this->getTableColumns() as $column) {
            $name = $column->getName();
            $label = Str::of($this->formatLabel($name))->headline();

            $output .= "'$name'          => '$label'";
            $output .= ",\r\n";
        }

        return $output;
    }

    protected function indentCodes($code)
    {
        $indent = str_repeat(' ', 4);

        return rtrim($indent.preg_replace("/\r\n/", "\r\n{$indent}", $code));
    }

    private function makeTranslations()
    {
        $filesystem = new Filesystem();

        $stub = $filesystem->get(__DIR__.'/stubs/translations.stub');

        $content = str_replace(
            [
                'DummyTitle',
                'DummyLowerTitles',
                'DummyLabels',
            ],
            [
                $this->getTitle(),
                $this->getTitlePlural(),
                $this->indentCodes($this->getLabels()),
            ],
            $stub
        );

        $langDirectories = $filesystem->directories(resource_path('lang'));

        foreach ($langDirectories as $directory) {
            $filePath = $directory . DIRECTORY_SEPARATOR . $this->resource . '.php';

            if (!$filesystem->exists($filePath)) {
                $filesystem->put($filePath, $content);
            }
        }
    }
}
