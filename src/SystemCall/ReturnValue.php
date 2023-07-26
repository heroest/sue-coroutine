<?php

namespace Sue\Coroutine\SystemCall;

use Sue\Coroutine\Coroutine;

class ReturnValue extends AbstractSystemCall
{
    private $value;

    public function __construct($value)
    {
        $this->value = $value;
    }

    public function run(Coroutine $coroutine)
    {
        return $coroutine->fulfill($this->value);
    }
}