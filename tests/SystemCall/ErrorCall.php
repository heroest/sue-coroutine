<?php

namespace Sue\Coroutine\Tests\SystemCall;

use RuntimeException;
use Sue\Coroutine\SystemCall\AbstractSystemCall;
use Sue\Coroutine\Coroutine;

class ErrorCall extends AbstractSystemCall
{
    private $exception;

    public function __construct(RuntimeException $e)
    {
        $this->exception = $e;
    }

    public function run(Coroutine $coroutine)
    {
        throw $this->exception;
    }
}