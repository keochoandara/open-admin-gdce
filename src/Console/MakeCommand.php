<?php

namespace OpenAdmin\Admin\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use \Illuminate\Filesystem\Filesystem;

class MakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'admin:make {model}
        {--title=}
        {--name=}
        {--stub= : Path to the custom stub file. }
        {--namespace=}
        {--O|output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make admin controller';

    /**
     * @var ResourceGenerator
     */
    protected $generator;

    /**
     * @var string
     */
    protected $controllerName;

    /**
     * @var string
     */
    protected $modelName;

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->modelName = $this->getModelName();

        if (!$this->modelExists()) {
            $this->error('Model not found! use, command like: artisan admin:controller \\\\App\\\\Models\\\\ModelName');

            return false;
        }

        $this->controllerName = $this->getControllerName();
        $stub = $this->option('stub');

        if ($stub and !is_file($stub)) {
            $this->error('The stub file does not exist.');

            return false;
        }

        $this->generator = new ResourceGenerator($this->modelName);

        if ($this->option('output')) {
            return $this->output($this->modelName);
        }

        if (parent::handle() !== false) {
            $path = Str::plural(Str::kebab(class_basename($this->modelName)));

            $this->generateClientPages($path);

            $this->line('');
            $this->comment('Add the following route to app/CRUD/routes.php:');
            $this->line('');
            $this->info("    \$router->resource('{$path}', {$this->controllerName}::class);");
            $this->line('');
        }
    }

    /**
     * @throws \ReflectionException
     *
     * @return string
     */
    protected function getControllerName()
    {
        if (!empty($this->option('name'))) {
            return $this->option('name');
        }
        $name = (new \ReflectionClass($this->modelName))->getShortName();

        return $name.'Controller';
    }

    /**
     * @return array|string|null
     */
    protected function getModelName()
    {
        return $this->argument('model');
    }

    /**
     * @throws \ReflectionException
     *
     * @return array|bool|string|null
     */
    protected function getTitle()
    {
        if ($title = $this->option('title')) {
            return $title;
        }

        return (new \ReflectionClass($this->modelName))->getShortName();
    }

    /**
     * @param string $modelName
     */
    protected function output($modelName)
    {
        $this->alert("open-admin controller code for model [{$modelName}]");

        $this->info($this->generator->generateGrid());
        $this->info($this->generator->generateShow());
        $this->info($this->generator->generateForm());
    }

    /**
     * Determine if the model is exists.
     *
     * @return bool
     */
    protected function modelExists()
    {
        if (empty($this->modelName)) {
            return true;
        }

        return class_exists($this->modelName) && is_subclass_of($this->modelName, Model::class);
    }

    /**
     * Replace the class name for the given stub.
     *
     * @param string $stub
     * @param string $name
     *
     * @return string
     */
    protected function replaceClass($stub, $name)
    {
        $stub = parent::replaceClass($stub, $name);

        return str_replace(
            [
                'DummyName',
                'DummyModelNamespace',
                'DummyTitle',
                'DummyModel',
                'DummyGrid',
                'DummyShow',
                'DummyForm',
            ],
            [
                Str::of($this->getTitle())->snake(),
                $this->modelName,
                $this->getTitle(),
                class_basename($this->modelName),
                $this->indentCodes($this->generator->generateGrid()),
                $this->indentCodes($this->generator->generateShow()),
                $this->indentCodes($this->generator->generateForm()),
            ],
            $stub
        );
    }

    /**
     * @param string $code
     *
     * @return string
     */
    protected function indentCodes($code)
    {
        $indent = str_repeat(' ', 8);

        return rtrim($indent.preg_replace("/\r\n/", "\r\n{$indent}", $code));
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        if ($stub = $this->option('stub')) {
            return $stub;
        }

        if ($this->modelName) {
            return __DIR__.'/stubs/controller.stub';
        }

        return __DIR__.'/stubs/blank.stub';
    }

    /**
     * Get the default namespace for the class.
     *
     * @param string $rootNamespace
     *
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        if ($namespace = $this->option('namespace')) {
            return $namespace;
        }

        return config('admin.route.namespace');
    }

    /**
     * Get the desired class name from the input.
     *
     * @return string
     */
    protected function getNameInput()
    {
        $this->type = $this->qualifyClass($this->controllerName);

        return $this->controllerName;
    }

    private function generateClientPages($path)
    {
        $directory = config('admin.client_path') . DIRECTORY_SEPARATOR . "pages" . DIRECTORY_SEPARATOR . $path;
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        if (!file_exists($directory. DIRECTORY_SEPARATOR . "[id]")) {
            mkdir($directory . DIRECTORY_SEPARATOR . "[id]", 0755, true);
        }

        $pages = [
            'index' => __DIR__.'/stubs/client_pages/index.stub',
            'show' => __DIR__.'/stubs/client_pages/show.stub',
            'create' => __DIR__.'/stubs/client_pages/create.stub',
            'edit' => __DIR__.'/stubs/client_pages/edit.stub'
        ];

        foreach ($pages as $page => $stub) {
            $filesystem = new Filesystem();
            $stubContent = $filesystem->get($stub);
            
            $content = str_replace(
                [
                    'DummyResourceName',
                    'DummyPrefix',
                ],
                [
                    $path,
                    str_replace('api/', '', config('admin.route.prefix')),
                ],
                $stubContent
            );

            switch ($page) {
                case 'show':
                    $filePath = $directory . DIRECTORY_SEPARATOR . "[id]" . DIRECTORY_SEPARATOR . 'index.vue';
                    break;
                case 'edit':
                    $filePath = $directory . DIRECTORY_SEPARATOR . "[id]" . DIRECTORY_SEPARATOR . $page . '.vue';
                    break;
                default:
                    $filePath = $directory . DIRECTORY_SEPARATOR . $page . '.vue';
                    break;
            }
            if (!file_exists($filePath)) {
                file_put_contents($filePath, $content);
            }
        }
    }
}
