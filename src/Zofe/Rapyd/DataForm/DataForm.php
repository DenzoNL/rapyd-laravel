<?php

namespace Zofe\Rapyd\DataForm;

use Illuminate\Database\Eloquent\Model;
use Zofe\Rapyd\DataForm\Field\Field;
use Zofe\Rapyd\DataForm\Field\Submit;
use Zofe\Rapyd\Widget;
use Illuminate\Support\Facades\Form;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Zofe\Rapyd\Rapyd;

/**
 * Class DataForm
 *
 * @method public text($name, $label, $validation = '')
 * @method public hidden($name, $label, $validation = '')
 * @method public password($name, $label, $validation = '')
 * @method public file($name, $label, $validation = '')
 * @method public textarea($name, $label, $validation = '')
 * @method public select($name, $label, $validation = '')
 * @method public radiogroup($name, $label, $validation = '')
 * @method public submit($name, $label, $validation = '')
 * @method public redactor($name, $label, $validation = '')
 * @method public autocomplete($name, $label, $validation = '')
 * @method public tags($name, $label, $validation = '')
 * @method public colorpicker($name, $label, $validation = '')
 * @method public date($name, $label, $validation = '')
 * @method public auto($name, $label, $validation = '')
 * @package Zofe\Rapyd\DataForm
 */
class DataForm extends Widget
{

    public $model;
    public $model_relations;
    public $validator; 
    
    public $output = "";
    public $fields = array();
    public $hash = "";
    public $error = "";

    public $open;
    public $close;

    protected $method = 'POST';
    protected $redirect = null;
    protected $source;
    protected $process_url = '';
    protected $view = 'rapyd::dataform';
    protected $orientation = 'horizontal';
    protected $form_callable = false;

    public function __construct()
    {
        parent::__construct();
        $this->process_url = Rapyd::linkRoute('process');
        $this->model_relations = new \ArrayObject();
    }

    /**
     * start form creation without model
     * 
     * @return static
     */
    public static function create()
    {
        $ins = new static();
        $ins->cid = $ins->getIdentifier();

        \Event::listen('dataform.save', array($ins, 'save'));
        
        return $ins;
    }

    /**
     * start form creation from model (bind  model to fields)
     * 
     * @param \Illuminate\Database\Eloquent\Model $source
     * @return static
     */
    public static function source($source = '')
    {
        $ins = new static();
        if (is_object($source) && is_a($source, "\Illuminate\Database\Eloquent\Model")) {
            $ins->model = $source;
        }
        $ins->cid = $ins->getIdentifier();
        
        \Event::listen('dataform.save', array($ins, 'save'));

        return $ins;
    }

    /**
     * add field to the form
     *
     * @param string $name
     * @param string $label
     * @param string $type
     * @param string $validation
     * @return mixed
     */
    public function add($name, $label, $type)
    {
        if (strpos($type, "\\") !== false) {
            $field_class = $type;
        } else {
            $field_class = '\Zofe\Rapyd\DataForm\Field\\' .  ucfirst($type);
        }

        //instancing
        if (isset($this->model)) {
            $field_obj = new $field_class($name, $label, $this->model, $this->model_relations);
        } else {
            $field_obj = new $field_class($name, $label);
        }

        if (!$field_obj instanceof Field) {
            throw new \InvalidArgumentException('Third argument («type») must point to class inherited Field class');
        }

        if ($field_obj->type == "file") {
            $this->multipart = true;
        }
        $this->fields[$name] = $field_obj;

        return $field_obj;
    }

    /**
     * remove field from fields list
     *
     * @param $fieldname
     * @return $this
     */
    public function remove($fieldname)
    {
        if (isset($this->fields[$fieldname]))
            unset($this->fields[$fieldname]);

        return $this;
    }

    /**
     * remove field where type==$type from field list and button container
     *
     * @param $type
     * @return $this
     */
    public function removeType($type)
    {
        foreach ($this->fields as $fieldname => $field) {
            if ($field->type == $type) {
                unset($this->fields[$fieldname]);
            }
        }
        foreach ($this->button_container as $container => $buttons) {
            foreach ($buttons as $key=>$button) {
                if (strpos($button, 'type="'.$type.'"')!==false) {
                    $this->button_container[$container][$key] = "";
                }
            }
        }

        return $this;
    }

    /**
     * add a submit button
     *
     * @param string $name
     * @param string $position
     * @param array  $options
     * @return $this
     */
    public function submit($name, $position = "BL", $options = array())
    {
        $options = array_merge(array("class" => "btn btn-primary"), $options);
        $this->button_container[$position][] = Form::submit($name, $options);

        return $this;
    }

    /**
     * get field instance from fields array
     *
     * @param $field_name
     * @param  array                      $ttributes
     * @return \Zofe\Rapyd\DataForm\Field $field
     */
    public function field($field_name, array $attributes = array())
    {
        if (isset($this->fields[$field_name])) {
            $field = $this->fields[$field_name];
            if (count($attributes)) {
                $field->attributes($attributes);
                $field->build();
            }

            return $field;
        }
    }

    
    /**
     * field validation, check all validation rules
     * 
     * @return bool
     */
    protected function isValid()
    {
        if ($this->error != "") {
            return false;
        }
        foreach ($this->fields as $field) {
            $field->action = $this->action;
            if (isset($field->rule)) {
                $rules[$field->name] = $field->rule;
                $attributes[$field->name] = $field->label;
            }
        }
        if (isset($rules)) {

            $this->validator = Validator::make(Input::all(), $rules, array(), $attributes);

            return !$this->validator->fails();
        } else {
            return true;
        }
    }
    
    /**
     * append error (to be used in passed/saved closure)
     * 
     * @param string $url
     * @param string $name
     * @param string $position
     * @param array  $attributes
     *
     * @return $this
     */
    public function error($error)
    {
        $this->process_status = 'error';
        $this->message = '';
        $this->error .= $error;

        return $this;
    }
    
    /**
     * needed by DataEdit, to build standard action buttons
     */
    protected function buildButtons() { }

    
    /**
     * build each field and share some data from dataform to field (form status, validation errors)
     */
    protected function buildFields()
    {
        $messages = (isset($this->validator)) ? $this->validator->messages() : false;

        foreach ($this->fields as $field) {
            $field->status = $this->status;
            $field->orientation = $this->orientation;
            if ($messages and $messages->has($field->name)) {
                $field->messages = $messages->get($field->name);
                $field->has_error = " has-error";
            }
            $field->build();
        }
    }

    /**
     * save data on model or just fill fields values
     * 
     * @return bool
     */
    protected function save()
    {
        if ($this->isValid()) {
            
            //set field values
            if ($this->setValues())
            {
                if (isset($this->model)) {
                    return $this->model->save();
                } else {
                    return true;
                }
            }
        }
        
        $this->process_status = "error";
        return false;
    }


    /**
     * set field values using field autoUpdate
     * 
     * @return bool
     */
    protected function setValues()
    {
        foreach ($this->fields as $field)
        {
            $field->action = $this->action;
            if ( $field->autoUpdate())
            {
                return false;
            }
        }
        return true;
    } 
    
    
    protected function buildForm()
    {
        $this->prepareForm();
        $df = $this;

        return View::make($this->view, compact('df'));
    }

    public function prepareForm()
    {
        $form_attr = array('url' => $this->process_url, 'class' => "form-horizontal", 'role' => "form", 'method' => $this->method);
        $form_attr = array_merge($form_attr, $this->attributes);

        // See if we need a multipart form
        foreach ($this->fields as $field_obj) {
            if (in_array($field_obj->type, array('file','image'))) {
                $form_attr['files'] = 'true';
                break;
            }
        }
        // Set the form open and close
        if ($this->status == 'show') {
            $this->open = '<div class="form">';
            $this->close = '</div>';
        } else {

            $this->open = Form::open($form_attr);
            $this->close = Form::hidden('save', 1) . Form::close();

            if ($this->method == "GET") {
                $this->close = Form::hidden('search', 1) . Form::close();
            }
        }
        if (isset($this->validator)) {
            $this->errors = $this->validator->messages();
            $this->error .=  implode('<br />',$this->errors->all());
        }
    }

    /**
     * build form output and prepare form partials (header / footer / ..)
     * @param string $view
     */
    public function build($view = '')
    {
        \Event::flush('dataform.save');
        
        if (isset($this->attributes['class']) and strpos($this->attributes['class'], 'form-inline') !== false) {
            $this->view = 'rapyd::dataform_inline';
            $this->orientation = 'inline';
        }
        if ($this->output != '') return;
        if ($view != '') $this->view = $view;
        
        //callable
        if ($this->form_callable && $this->process_status == "success") {
            $callable = $this->form_callable;
            $result = $callable($this);
            if ($result && is_a($result, 'Illuminate\Http\RedirectResponse')) {
                $this->redirect = $result;
            }
            //reprocess if an error is added in closure
            if ($this->process_status == 'error') {
                $this->process();
            }
        }
        //cleanup submits if success
        if ($this->process_status == 'success') {
            $this->removeType('submit');
        }
        $this->buildButtons();
        $this->buildFields();
        $dataform = $this->buildForm();
        $this->output = $dataform->render();

        $sections = $dataform->renderSections();
        $this->header = $sections['df.header'];
        $this->footer = $sections['df.footer'];
        $this->body = @$sections['df.fields'];
        Rapyd::setForm($this);
    }

    /**
     * get single field output (label, output, and messages) in a bootstrap-3 block
     * you can place it on col-x div to customize form output (grouping etc..)
     * 
     * @param $field_name
     * @param  array  $ttributes
     * @return string
     */
    public function render($field_name, array $attributes = array())
    {
        $field = $this->field($field_name, $attributes);

        return $field->all();
    }

    
    /**
     * @param  string $view
     * @return string
     */
    public function getForm($view = '')
    {
        $this->build($view);

        return $this->output;
    }

    public function __toString()
    {
        if ($this->output == "") {
            try {
                $this->getForm();
            }
                //to avoid the error "toString() must not throw an exception" (PHP limitation)
                //just return error as string
            catch (\Exception $e) {
                return '<div class="alert alert-danger">'.
                $e->getMessage() ."<br>\n".
                "File: <small>".$e->getFile() . "</small><br>\n".
                "Line: " . $e->getLine().'</div>';
            }
        }

        return $this->output;
    }

    /**
     * @return bool
     */
    public function hasRedirect()
    {
        return ($this->redirect != null) ? true : false;
    }

    /**
     * @return string
     */
    public function getRedirect()
    {
        return $this->redirect;
    }

    /**
     * view method enable you to manage redirect  (on save)
     * it is used mainly by dataedit but can work also with dataform  
     * 
     * @param string $viewname
     * @param array  $array    of values for view
     *
     * @return View|Redirect
     */
    public function view($viewname, $array = array())
    {
        if (!isset($array['form'])) {
            $form = $this->getForm();
            $array['form'] = $form;
        }
        if ($this->hasRedirect()) {
            return (is_a($this->redirect, 'Illuminate\Http\RedirectResponse')) ? $this->redirect : Redirect::to($this->redirect);
        }

        return View::make($viewname, $array);
    }

    /**
     * build form and check if process status is "success" 
     * execute a callable
     * 
     * @param callable $callable
     */
    public function saved(\Closure $callable)
    {
        $this->form_callable = $callable;
    }

    /**
     * alias for saved
     * 
     * @param callable $callable
     */
    public function passed(\Closure $callable)
    {
        $this->saved($callable);
    }

    /**
     * Magic method to catch all appends using $form->{fieldtype}(...)
     *
     * @param  string $name
     * @param  Array  $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        $classname = '\Zofe\Rapyd\DataForm\Field\\'.ucfirst($name);
        $legacy = '\Zofe\Rapyd\DataForm\Field\\'.'add'.ucfirst($name);

        if (class_exists($classname) || class_exists($legacy))
        {
            array_push($arguments, $name);
            return  call_user_func_array(array($this, "add"), $arguments);
        }
    }
}
