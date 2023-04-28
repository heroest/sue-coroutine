<?php

namespace Sue\Coroutine\Tests\Custom;

use Sue\Coroutine\Coroutine;
use Sue\Coroutine\SystemCall\AbstractSystemCall;

class GetCoroutine extends AbstractSystemCall
{
    public function run(Coroutine $coroutine)
    {
        return $coroutine;
    }
}