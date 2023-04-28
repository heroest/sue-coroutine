<?php

namespace Sue\Coroutine\Tests;

use Sue\Coroutine\Tests\BaseTestCase;
use Sue\Coroutine\Exceptions;

use function React\Promise\reject;
use function React\Promise\resolve;
use function Sue\Coroutine\co;
use function Sue\EventLoop\loop;

final class PromiseCancelTest extends BaseTestCase
{
    public function testCoroutinePromiseCancel()
    {
        $deferred_cancelled = false;
        $deferred = new \React\Promise\Deferred(function () use (&$deferred_cancelled) {
            $deferred_cancelled = true;
        });
        /** @var \React\Promise\CancellablePromiseInterface $coroutine_promise */
        $coroutine_promise = co(function ($promise) {
            yield $promise;
            return 'foo';
        }, $deferred->promise());
        loop()->addTimer(0.1, function () use ($coroutine_promise) {
            $coroutine_promise->cancel();
        });
        loop()->run();
        $this->assertCancel($coroutine_promise);
        $this->assertEquals(true, $deferred_cancelled, 'promise-cancelled');
    }

    public function testAwaitPromiseCancel()
    {
        $deferred_cancelled = false;
        $deferred = new \React\Promise\Deferred(function () use (&$deferred_cancelled) {
            $deferred_cancelled = true;
        });

        /** @var \React\Promise\CancellablePromiseInterface $coroutine_promise */
        $coroutine_promise = co(function ($promise) {
            yield [
                reject(new \Exception('foo')),
                resolve('bar'),
                $promise
            ];
        }, $deferred->promise());

        loop()->addTimer(0.5, function () use ($coroutine_promise) {
            $coroutine_promise->cancel();
        });
        loop()->run();
        $this->assertCancel($coroutine_promise);
        $this->assertEquals(true, $deferred_cancelled, 'promise-cancelled');
    }

    private function assertCancel($value)
    {
        if ($value instanceof \React\Promise\PromiseInterface) {
            $value = self::unwrapSettledPromise($value);
        }
        $this->assertTrue($value instanceof Exceptions\CancelException);
    }
}