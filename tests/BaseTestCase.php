<?php

namespace Sue\Coroutine\Tests;

use PHPUnit\Framework\TestCase;
use Sue\Coroutine\Scheduler;
use Sue\Coroutine\Coroutine;

use function Sue\EventLoop\loop;

abstract class BaseTestCase extends TestCase
{   
    /** @inheritDoc */
    public function setUp(): void
    {
        parent::setUp();
        loop()->stop();
        Scheduler::getInstance()->setCustomCoroutineClass(Coroutine::class);
    }

    /**
     * 解析promise的返回值
     *
     * @param \React\Promise\PromiseInterface $promise
     * @return void
     */
    protected static function unwrapSettledPromise($promise)
    {
        $result = null;
        $closure = function ($val) use (&$result) {
            $result = $val;
        };
        $promise->done($closure, $closure);
        return $result;
    }

    /**
     * 获取耗时
     *
     * @param float $time_start
     * @return float
     */
    protected function getTimeUsed($time_start)
    {
        return (float) bcsub(microtime(true), $time_start, 6);
    }
}
