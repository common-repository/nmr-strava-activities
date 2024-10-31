<?php
include_once "base-nmr.php";
class BaseRepo extends BaseNmr
{
    protected $method;
    protected $data;
    protected $error;
    protected $result;
    protected $table;
    protected $columns;

    public function __construct($method, $data)
    {
        parent::__construct();
        $this->method = $method;
        $this->data = $data;
    }

    public function IsError()
    {
        if ($this->error)
            return true;
        return false;
    }

    public function GetError()
    {
        return $this->error;
    }

    public function GetResult()
    {
        return $this->result;
    }

    public function Execute()
    {
        //virtual
    }

    protected function get($key, $type = 1)
    {
        $type = intval($type);
        if (!$this->data || !array_key_exists($key, $this->data))
            return null;
        $result = $this->data[$key];
        switch ($type) {
            case 1:
                return intval($result);
            case 2:
                return sanitize_text_field($result);
        }
        return null;
    }
}
