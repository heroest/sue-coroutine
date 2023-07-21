<?php

namespace Sue\Coroutine\Tests;

use React\Promise\Deferred;
use RuntimeException;

use function Sue\Coroutine\co;
use function Sue\Coroutine\Utils\isPhp7;
use function Sue\Eventloop\loop;
use function Sue\Eventloop\setTimeout;

class LegacyTest extends BaseTestCase
{
    public function testLastYieldAsReturn()
    {
        if (isPhp7()) {
            $this->markTestSkipped('php7 not supported');
            return;
        }
        
        $actual = 0;
        co(function () {
            $deferred = new Deferred();
            setTimeout(0.2, function () use ($deferred) {
                $deferred->resolve(1);
            });
            $count = yield $deferred->promise();
            yield ++$count;
        })->then(function ($value) use (&$actual) {
            $actual = $value;
        });
        loop()->run();
        $this->assertEquals(2, $actual);
    }

    public function testLastYieldException()
    {
        if (isPhp7()) {
            $this->markTestSkipped('php7 not supported');
            return;
        }

        $foo = new RuntimeException('foo');
        $throwable = $actual = null;
        co(function () use ($foo) {
            $deferred = new Deferred();
            setTimeout(0.2, function () use ($deferred) {
                $deferred->resolve(1);
            });
            yield $deferred->promise();
            yield $foo;
        })->then(function ($value) use (&$actual) {
            $actual = $value;
        }, function ($e) use (&$throwable) {
            $throwable = $e;
        });
        loop()->run();
        $this->assertNull($actual);
        $this->assertEquals($foo, $throwable);
    }
}