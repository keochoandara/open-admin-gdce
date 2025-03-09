<?php

namespace OpenAdmin\Admin\Controllers;

use Illuminate\Routing\Controller;
use OpenAdmin\Admin\Layout\Content;
use OpenAdmin\Admin\Traits\HasCustomHooks;

class AdminController extends Controller
{
    use HasResourceActions;
    use HasCustomHooks;

    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = "Title";

    protected $titles = [];

    /**
     * Set description for following 4 action pages.
     *
     * @var array
     */
    protected $description = [];

    /**
     * Get content title.
     *
     * @return string
     */
    protected function title()
    {
        return $this->title;
    }

    protected function titles($page)
    {
        return $this->titles[$page] ?? '';
    }

    protected function description($page)
    {
        return $this->description[$page] ?? '';
    }

    public function setTitle($page, $title)
    {
        $this->titles[$page] = $title;
    }

    public function setDescription($page, $description)
    {
        $this->description[$page] = $description;
    }

    /**
     * Index interface.
     *
     * @param Content $content
     *
     * @return Content
     */
    public function index(Content $content)
    {
        $page = "index";
        $grid = $this->grid();
        if ($this->hasHooks('alterGrid')) {
            $grid = $this->callHooks('alterGrid', $grid);
        }

        if (! request()->expectsJson()) {
            return $content
                ->title($this->titles($page))
                ->description($this->description($page))
                ->body($grid);
        }

        if (request()->has('__sort__')) {
            $sort = (object) request('__sort__');
            $grid->model()->orderBy($sort->id, $sort->desc ? 'desc' : 'asc');
        }

        $grid->render();

        $columns = $grid->getColumns()->map(function ($column) {
            return [
                'name' => $column->getName(),
                'label' => $column->getLabel(),
            ];
        });

        $filters = collect($grid->getFilter()->filters())->map(function ($filter) {
            $filter_type = strtolower(basename(str_replace('\\', '/', get_class($filter))));
            $presenter = $filter->getPresenter();
            $ft = $presenter->variables();

            if (@$ft['options']) {
                $map = collect($ft['options'])->map(function ($label, $value) {
                    return [
                        'label' => $label,
                        'value' => $value,
                    ];
                })->toArray(); 

                $ft['options'] = array_values($map);
            } 

            $ft['filter_type'] = $filter_type;
            $ft['label'] = $filter->getLabel();
            $ft['column'] = $filter->column;
            $ft['value'] = $filter->value;

            return $ft;
        });
        
        $paginator = $grid->paginator()->getPaginator()->toArray();

        $pagination = [
            'current_page' => $paginator['current_page'],
            'per_page' => $paginator['per_page'],
            'from' => $paginator['from'],
            'to' => $paginator['to'],
            'last_page' => $paginator['last_page'],
            'total' => $paginator['total'],
            'per_pages' => $grid->getPerPages(),
        ];

        $rows = $grid->rows()->map(function ($row) {
            $data = $row->getData();
            $data['__row_selector__'] = isset($data['__row_selector__']);
            $data['__actions__'] = $this->getAvailableActions($data['__actions__'], $data['id']);
           return $data; 
        })->toArray();

        $data = [
            'title' => $this->titles($page),
            'description' => $this->description($page),
            'columns' => $columns,
            'filters' => $filters,
            'search' => $this->getSearch($grid),
            'data' => $rows,
            'pagination' => $pagination,
        ];

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    /**
     * Show interface.
     *
     * @param mixed   $id
     * @param Content $content
     *
     * @return Content
     */
    public function show($id, Content $content)
    {
        $page = "show";
        $detail = $this->detail($id);
        if ($this->hasHooks('alterDetail')) {
            $detail = $this->callHooks('alterDetail', $detail);
        }

        if (!request()->expectsJson()) {
            return $content
                ->title($this->titles($page))
                ->description($this->description($page))
                ->body($detail);
        }

        $detail->render();

        $data = $detail->getFields()->map(function ($field) {
            return $field->getVariables();
        });

        $panel = $detail->getPanel()->getData();

        $data = [
            'title' => $this->titles($page) ?? $panel['title'],
            'description' => $this->description($page),
            'data' => $data,
            'tools' => $panel['tools']->getTools()
        ];

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    /**
     * Edit interface.
     *
     * @param mixed   $id
     * @param Content $content
     *
     * @return Content
     */
    public function edit($id, Content $content)
    {
        $page = "edit";
        $form = $this->form();
        if ($this->hasHooks('alterForm')) {
            $form = $this->callHooks('alterForm', $form);
        }

        if (!request()->expectsJson()) {
            return $content
                ->title($this->titles($page))
                ->description($this->description($page))
                ->body($form->edit($id));
        }

        $form->edit($id)->render();

        $data = $this->getFormData($form);

        $data = [
            'title' => $this->titles($page),
            'description' => $this->description($page),
            'data' => $data
        ];

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    /**
     * Create interface.
     *
     * @param Content $content
     *
     * @return Content
     */
    public function create(Content $content)
    {
        $page = "create";
        $form = $this->form();
        if ($this->hasHooks('alterForm')) {
            $form = $this->callHooks('alterForm', $form);
        }

        if (!request()->expectsJson()) {
            return $content
                ->title($this->titles($page))
                ->description($this->description($page))
                ->body($form);
        }

        $form->render();

        $data = $this->getFormData($form);

        $data = [
            'title' => $this->titles($page),
            'description' => $this->description($page),
            'data' => $data
        ];

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    private function getSearch($grid)
    {
        $result = [];
        $search = $grid->getQuickSearchColumns();
        foreach ($grid->getColumns() as $column) {
            if (!in_array($column->getName(), $search)) {
                continue;
            }
            array_push($result, [
                'name' => $column->getName(),
                'label' => $column->getLabel(),
            ]);
        }
        return $result;
    }

    public function destroy($id)
    {
        $detail = $this->detail($id);
        $model = $detail->getModel();
        $model->delete();

        return response()->json([
            'status' => true
        ]);
    }

    public function getAvailableActions($html, $id) 
    {
        $delete_path = request()->route()->uri . '/' . $id;

        // Initialize DOMDocument and suppress warnings for potentially malformed HTML
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
    
        // Use DOMXPath to query the DOM
        $xpath = new \DOMXPath($dom);

        $with_label = false;

        // Find the div with class "__actions__div"
        $actionDiv = $xpath->query('//div[@class="__actions__div "]')->item(0);

        if (!$actionDiv) {
            $actionDiv = $xpath->query('//div[@class="__actions__div with-labels "]')->item(0);
            $with_label = true;
        }

        if (!$actionDiv) {
            return [];
        }
        
        // Initialize the result array
        $actions = [];
    
        // Get all <a> tags inside the actions div
        $links = $xpath->query(".//a", $actionDiv);
        foreach ($links as $link) {
            // Extract the href attribute
            $href = $link->getAttribute('href');
    
            // Find the <span class="label"> inside this <a> tag
            $span = $xpath->query(".//span[@class='label']", $link)->item(0);
            if ($span) {
                // Get the action name, trim it, and convert to lowercase
                $actionName = strtolower(trim($span->textContent));
    
                // Extract the path from the URL
                $path = parse_url($href, PHP_URL_PATH);
                
                $prefix = config('admin.route.prefix');

                $relativePath = str_replace($prefix . "/", '', $path);

                $relativePath = $relativePath == 'void(0);' ? str_replace('api' . "/", '', $delete_path) : $relativePath;
    
                // Add the action to the result array
                $actions[] = [
                    'action' => $actionName,
                    'path' => $relativePath,
                    'with_label' => $with_label,
                ];
            }
        }
    
        return $actions;
    }

    private function getFieldOptions($field)
    {
        $options = [];
        foreach ($field->getOptions() as $key => $value) {
            $options[] = [
                'label' => $value,
                'value' => $key,
            ];
        }
        return $options;
    }

    private function getFormData($form)
    {
        return $form->fields()->map(function ($field) {
            $var = $field->variables();
            $rules = $field->getStrRules();
            if (gettype($rules) === 'string') {
                $rules = [$rules];
            }
            return [
                'type' => $field->getType(),
                'value' => $var['value'],
                'column' => $field->getColumn(),
                'label' => $field->getLabel(),
                'description' => @$var['help']['text'],
                'options' => $this->getFieldOptions($field),
                'rules' => $rules,
                'rules_messages' => $field->getValidationMessages(),
                'element_class' => $field->getElementClassString(),
            ];
        });
    }
}
