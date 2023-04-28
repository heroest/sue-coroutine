<?php

namespace Sue\Coroutine\Tests\SystemCall;

use Closure;
use Sue\Coroutine\SystemCall\AbstractSystemCall;
use Sue\Coroutine\Coroutine;

class WarningCall extends AbstractSystemCall
{
    private $closure;

    public function __construct(Closure $closure)
    {
        $this->closure = $closure;
    }

    public function run(Coroutine $coroutine)
    {
        $closure = $this->closure;
        $closure();
    }
}