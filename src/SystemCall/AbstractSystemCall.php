<?php

namespace Sue\Coroutine\SystemCall;

use Exception;
use Sue\Coroutine\Coroutine;

use function React\Promise\reject;

abstract class AbstractSystemCall
{
    abstract public function run(Coroutine $coroutine);

    /**
     * 安全封装一下
     *
     * @param Coroutine $coroutine
     * @return mixed|\React\Promise\PromiseInterface|\React\Promise\Promise
     */
    final public function wrapRun(Coroutine $coroutine)
    {
        try {
            return call_user_func([$this, 'run'], $coroutine);
        } catch (Exception $e) {
            return reject($e);
        }
    }
}
