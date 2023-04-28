<?php

namespace Sue\Coroutine\Tests\Custom;

use Exception;
use BadMethodCallException;
use Sue\Coroutine\Tests\BaseTestCase;
use Sue\Coroutine\Tests\Custom\CustomCoroutine;
use Sue\Coroutine\Tests\Custom\InvalidCustomCoroutine;
use Sue\Coroutine\Tests\Custom\GetCoroutine;
use Sue\Coroutine\Scheduler;

use function Sue\Coroutine\co;
use function Sue\EventLoop\loop;

final class CustomCoroutineTest extends BaseTestCase
{
    public function testCustomClass()
    {
        Scheduler::getInstance()
            ->setCustomCoroutineClass(CustomCoroutine::class);
        $class = false;
        co(function () use (&$class) {
            $coroutine = yield new GetCoroutine();
            $class = get_class($coroutine);
        });
        loop()->run();
        $this->assertEquals(CustomCoroutine::class, $class);
    }

    public function testInvalidCustomClass()
    {
        try {
            Scheduler::getInstance()
                ->setCustomCoroutineClass(InvalidCustomCoroutine::class);
        } catch (Exception $e) {
        }
        $this->assertEquals(BadMethodCallException::class, get_class($e));
    }
}
