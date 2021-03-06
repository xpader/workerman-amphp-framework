<?php


namespace Framework\Db\Event;


class QueryError extends \Framework\Event\Event
{

    public $sql = '';
    public $exception;

    /**
     * QueryError constructor.
     * @param string $sql
     * @param \Exception $exception
     */
    public function __construct($sql, $exception)
    {
        $this->sql = $sql;
        $this->exception = $exception;
    }

    public function __toString()
    {
        return $this->sql."\n".get_class($this->exception).': '.$this->exception->getMessage()."\n".$this->exception->getTraceAsString();
    }

}