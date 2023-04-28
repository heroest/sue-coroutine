<?php

namespace Sue\Coroutine\Tests;

use RuntimeException;
use Sue\Coroutine\Exceptions;
use Sue\Coroutine\SystemCall;
use Sue\Coroutine\Tests\BaseTestCase;
use Sue\Coroutine\Tests\SystemCall\ErrorCall;
use Sue\Coroutine\Tests\SystemCall\WarningCall;


use function Sue\Coroutine\co;
use function Sue\EventLoop\loop;

final class SystemCallTest extends BaseTestCase
{
    public function testSystemCallSleep()
    {
        $time_start = microtime(true);
        $time_end = 0;
        $promise = co(function () use (&$time_end) {
            yield SystemCall\sleep(2);
            $time_end = microtime(true);
            return 'foo';
        });
        loop()->run();

        $time_used = (float) bcsub($time_end, $time_start, 4);
        $this->assertGreaterThanOrEqual(2, $time_used);
        $this->assertLessThanOrEqual(2.1, $time_used);

        $result = self::unwrapSettledPromise($promise);
        $this->assertEquals('foo', $result);
    }

    public function testSystemCallTimeout()
    {
        $yielded = false;
        $cancelled = false;
        $deferred = new \React\Promise\Deferred(function () use (&$cancelled) {
            $cancelled = true;
        });
        $coroutine_promise = co(function ($promise) use (&$yielded) {
            yield SystemCall\timeout(1);
            $yielded = yield $promise;
        }, $deferred->promise());
        loop()->addTimer(2, function () use ($deferred) {
            $deferred->resolve('foo');
        });
        loop()->run();
        $this->assertTimeout(self::unwrapSettledPromise($coroutine_promise));
        $this->assertEquals(false, $yielded);
        $this->assertEquals(true, $cancelled);
    }

    public function testSystemCallNotTimeout()
    {
        $cancelled = false;
        $deferred = new \React\Promise\Deferred(function () use (&$cancelled) {
            $cancelled = true;
        });
        $coroutine_promise = co(function ($promise) {
            yield SystemCall\timeout(2);
            return yield $promise;
        }, $deferred->promise());
        loop()->addTimer(1, function () use ($deferred) {
            $deferred->resolve('foo');
        });
        loop()->run();
        $this->assertEquals('foo', self::unwrapSettledPromise($coroutine_promise));
        $this->assertEquals(false, $cancelled);
    }

    public function testSystemCallCancel()
    {
        $before = false;
        $after = false;
        $msg = 'foo';
        $code = 907;
        $coroutine_promise = co(function ($reason, $code) use (&$before, &$after) {
            $before = yield 'foo';
            yield SystemCall\cancel($reason, $code);
            $after = yield 'bar';
        }, $msg, $code);
        loop()->run();
        $this->assertEquals(
            new Exceptions\CancelException($msg, $code),
            self::unwrapSettledPromise($coroutine_promise)
        );
        $this->assertEquals('foo', $before);
        $this->assertNotEquals('bar', $after);
        $this->assertEquals(false, $after);
    }

    public function testNestedSystemcallTimeout()
    {
        $child = function () {
            yield SystemCall\timeout(2);
            yield \React\Promise\Timer\resolve(3, loop());
        };
        $promise = co(function ($child) {
            yield $child();
        }, $child);
        loop()->run();
        $this->assertTimeout(self::unwrapSettledPromise($promise));
    }

    public function testNestedSystemcallTimeoutWithHandling()
    {
        $child = function () {
            yield SystemCall\timeout(2);
            yield \React\Promise\Timer\resolve(3, loop());
        };
        $throwable = false;
        co(function ($child) use (&$throwable) {
            try {
                yield $child();
            } catch (\Exception $e) {
                $throwable = $e;
            }
        }, $child);
        loop()->run();
        $this->assertTimeout($throwable);
    }

    public function testNestedTimeoutWithShortParent()
    {
        $yield_z = false;
        $z = function () use (&$yield_z) {
            yield SystemCall\Timeout(5);
            $result = yield \React\Promise\Timer\resolve(4, loop());
            $yield_z = 'foo';
            return $result;
        };
        $yield_y = false;
        $y = function () use ($z, &$yield_y) {
            yield SystemCall\Timeout(3);
            $yield_y = 'bar';
            return $z();
        };
        $promise = co(function () use ($y) {
            yield SystemCall\Timeout(1);
            return $y();
        });
        loop()->run();
        $this->assertTimeout(self::unwrapSettledPromise($promise));
        $this->assertFalse($yield_z);
        $this->assertEquals('bar', $yield_y);
    }

    public function testNestedSystemCallCancel()
    {
        $child = function () {
            yield 'foo';
            yield SystemCall\cancel('bar', 422);
        };
        $throwable = false;
        co(function ($child) use (&$throwable) {
            try {
                yield $child();
            } catch (\Exception $e) {
                $throwable = $e;
            }
        }, $child);
        loop()->run();
        $this->assertEquals(new Exceptions\CancelException('bar', 422), $throwable);
    }
    
    public function testErrorSystemCall()
    {
        $throwable = null;
        $exception = new RuntimeException('foo');
        co(function () use (&$throwable, $exception) {
            try {
                yield new ErrorCall($exception);
            } catch (\Exception $e) {
                $throwable = $e;
            }
        });
        loop()->run();
        $this->assertEquals($throwable, $exception);
    }

    public function testWarningSystemCall()
    {
        $throwable = null;
        co(function () use (&$throwable) {
            try {
                yield new WarningCall(function () {
                    return 1 / 0;
                });
            } catch (\Exception $e) {
                $throwable = $e;
            }
        });
        loop()->run();
        $this->assertEquals(new \ErrorException('Division by zero', 2, E_USER_ERROR), $throwable);
    }

    private function assertTimeout($value)
    {
        $this->assertTrue($value instanceof Exceptions\TimeoutException);
    }
}
