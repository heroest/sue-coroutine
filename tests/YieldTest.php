<?php

namespace Sue\Coroutine\Tests;

use Exception;
use Throwable;
use RuntimeException;
use SplFileObject;
use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;
use Sue\Coroutine\Exceptions\CancelException;
use Sue\Coroutine\Exceptions\TimeoutException as CoroutineTimeoutException;
use Sue\Coroutine\Tests\BaseTestCase;
use Sue\Coroutine\Tests\Custom\GetCoroutine;
use Sue\Coroutine\Tests\Custom\CustomCoroutine;

use function React\Promise\resolve;
use function Sue\EventLoop\loop;
use function Sue\EventLoop\setTimeout;
use function Sue\Coroutine\co;
use function Sue\Coroutine\coAs;
use function Sue\Coroutine\defer;
use function Sue\Coroutine\go;
use function Sue\Coroutine\async;
use function Sue\Coroutine\SystemCall\returnValue;
use function Sue\Coroutine\SystemCall\timeout;
use function React\Promise\Timer\sleep as TimerSleep;

final class YieldTest extends BaseTestCase
{
    public function testCoAs()
    {
        $class = CustomCoroutine::class;
        $coroutine = null;
        coAs($class, function () use (&$coroutine) {
            $coroutine = yield new GetCoroutine();
        })->otherwise(function ($e) {
            exit($e);
        });
        loop()->run();
        $this->assertNotNull($coroutine);
        $this->assertInstanceOf($class, $coroutine);
    }

    public function testCoAsNested()
    {
        $class = CustomCoroutine::class;
        $coroutine = null;
        $callable = function () use (&$coroutine) {
            $children = function () use (&$coroutine) {
                $coroutine = yield new GetCoroutine();
            };
            yield $children();
        };

        co($callable);
        loop()->run();
        $this->assertNotNull($coroutine);
        $this->assertFalse($coroutine instanceof $class);

        $coroutine = null;
        coAs($class, $callable);
        loop()->run();
        $this->assertNotNull($coroutine);
        $this->assertTrue($coroutine instanceof $class);
    }

    public function testCoAsNestArray()
    {
        $class = CustomCoroutine::class;
        $coroutine = null;
        $callable = function () use (&$coroutine) {
            $children = function () use (&$coroutine) {
                $coroutine = yield new GetCoroutine();
            };
            yield [$children(), resolve('foo'), resolve('bar')];
        };
        coAs($class, $callable);
        loop()->run();
        $this->assertNotNull($coroutine);
        $this->assertTrue($coroutine instanceof $class);
    }

    public function testAsync()
    {
        $promise = TimerSleep(0.2, loop())->then(function () {
            return 'foo';
        });
        $st = microtime(true);
        $result = async(function () use ($promise) {
            yield returnValue(yield $promise);
        });
        $time_used = $this->getTimeUsed($st);
        $this->assertEquals('foo', $result);
        $this->assertGreaterThanOrEqual(0.2, $time_used);
    }

    public function testAsyncWithReject()
    {
        $promise = TimerSleep(0.2, loop())->then(function () {
            throw new \Exception('bar');
        });
        $exception = null;
        $st = microtime(true);
        try {
            async(function () use ($promise) {
                yield returnValue(yield $promise);
            });
        } catch (Throwable $e) {
            $exception = $e;
        }
        
        $time_used = $this->getTimeUsed($st);
        $this->assertEquals($exception, new \Exception('bar'));
        $this->assertGreaterThanOrEqual(0.2, $time_used);
    }

    public function testAsyncWithTimeout()
    {
        $executed = false;
        $promise = TimerSleep(2, loop())->then(function () use (&$executed) {
            $executed = true;
        });
        $st = microtime(true);
        $exception = null;
        try {
            async(function () use ($promise) {
                $value = yield $promise;
                yield returnValue($value);
            }, 0.5);
        } catch (Throwable $e) {
            $exception = $e;
        }
        $time_used = $this->getTimeUsed($st);
        $this->assertTrue($exception instanceof TimeoutException);
        $this->assertFalse($executed);
        $this->assertGreaterThanOrEqual(0.5, $time_used);
    }

    public function testAsyncWithSystemCallTimeout()
    {
        $executed = false;
        $promise = TimerSleep(2, loop())->then(function () use (&$executed) {
            $executed = true;
        });
        $st = microtime(true);
        $exception = null;
        try {
            async(function () use ($promise) {
                yield timeout(0.5);
                yield returnValue(yield $promise);
            });
        } catch (Throwable $e) {
            $exception = $e;
        }
        $time_used = $this->getTimeUsed($st);
        $this->assertTrue($exception instanceof CoroutineTimeoutException);
        $this->assertFalse($executed);
        $this->assertGreaterThanOrEqual(0.5, $time_used);
    }

    public function testPromise()
    {
        $yielded = null;
        $word = 'hello-world';
        co(function ($promise) use (&$yielded) {
            $yielded = yield $promise;
        }, resolve($word));
        loop()->run();
        $this->assertEquals($word, $yielded);
    }

    public function testValue()
    {
        $yielded = null;
        $word = 'foo';
        co(function ($input_word) use (&$yielded) {
            $yielded = yield $input_word;
        }, $word);
        loop()->run();
        $this->assertEquals($word, $yielded);
    }

    public function testNormalYield()
    {
        $total = 0;
        $generator = (function () {
            $count = 3;
            while ($count) {
                yield $count--;
            }
        })();
        co(function ($generator) use (&$total) {
            foreach ($generator as $i) {
                $total += $i;
            }
        }, $generator);
        loop()->run();
        $this->assertEquals(6, $total);
    }

    public function testThrowable()
    {
        $yielded = false;
        $reject = false;
        $exception = new \Exception('foo');
        co(function () use (&$yielded, $exception) {
            $yielded = yield $exception;
        })->then(null, function ($error) use (&$reject) {
            $reject = $error;
        });
        loop()->run();
        $this->assertEquals($yielded, null);
        $this->assertEquals($exception, $reject);
    }

    public function testThrowableHandling()
    {
        $yielded = false;
        $exception = new \Exception('foo');
        co(function () use (&$yielded, $exception) {
            try {
                yield $exception;
            } catch (Exception $e) {
                $yielded = $e;
            }
        });
        loop()->run();
        $this->assertEquals($exception, $yielded);
    }

    public function testWithError()
    {
        $yielded = false;
        $reject = false;
        co(function () use (&$yielded) {
            $yielded = yield new SplFileObject(); //故意写个语法错误
        })->done(null, function ($error) use (&$reject) {
            $reject = $error;
        });
        loop()->run();
        $this->assertEquals(null, $yielded);
        $this->assertTrue($reject instanceof \ArgumentCountError);
    }

    public function testException()
    {
        $yielded = false;
        $reject = false;
        co(function () use (&$yielded) {
            $yielded = yield new Exception('error');
        })->then(null, function ($error) use (&$reject) {
            $reject = $error;
        });
        loop()->run();
        $this->assertEquals(null, $yielded);
        $this->assertEquals(new \Exception('error'), $reject);
    }

    public function testErrorWithHandling()
    {
        $yielded = false;
        $reject = false;
        co(function () use (&$yielded, &$reject) {
            try {
                $yielded = yield new Exception('error');
            } catch (Exception $e) {
                $reject = $e;
            }
        });
        loop()->run();
        $this->assertEquals(null, $yielded);
        $this->assertEquals(new \Exception('error'), $reject);
    }

    public function testGeneratorWithNoReturn()
    {
        $child = function () {
            yield 'foo';
        };
        $yielded = false;
        co(function () use (&$yielded, $child) {
            $yielded = yield $child();
        });
        loop()->run();
        $this->assertEquals(null, $yielded);
    }

    public function testGeneratorWithReturn()
    {
        $child = function () {
            yield 'foo';
            yield returnValue('bar');
        };
        $yielded = false;
        co(function () use (&$yielded, $child) {
            $yielded = yield $child();
        });
        loop()->run();
        $this->assertEquals('bar', $yielded);
        $this->assertNotEquals('foo', $yielded);
    }

    public function testNestedGenerator()
    {
        $l2 = function () {
            $result = yield 'bar';
            yield returnValue($result);
        };
        $l1 = function () use ($l2) {
            yield 'foo';
            yield returnValue(yield $l2());
        };

        $yielded = false;
        co(function () use (&$yielded, $l1) {
            $yielded = yield $l1();
        });
        loop()->run();
        $this->assertEquals('bar', $yielded);
        $this->assertNotEquals('foo', $yielded);
    }

    public function testYieldPromiseArray()
    {
        $yielded = false;
        co(function () use (&$yielded) {
            $yielded = yield [
                \React\Promise\resolve('foo'),
                \React\Promise\resolve('bar')
            ];
        });
        loop()->run();
        $this->assertEquals(['foo', 'bar'], $yielded);
        $this->assertNotEquals(['bar', 'foo'], $yielded); //reverse
    }

    public function testYieldPromiseMap()
    {
        $yielded = false;
        co(function () use (&$yielded) {
            $yielded = yield [
                'foo' => \React\Promise\resolve('foo'),
                'bar' => \React\Promise\resolve('bar')
            ];
        });
        loop()->run();
        $this->assertEquals(['foo' => 'foo', 'bar' => 'bar'], $yielded);
        $this->assertNotEquals(['bar' => 'foo', 'foo' => 'bar'], $yielded); //reverse
    }

    public function testYieldGeneratorArray()
    {
        $yielded = false;
        co(function () use (&$yielded) {
            $yielded = yield [
                (function () {
                    $result = yield 'foo';
                    yield returnValue($result);
                })(),
                (function () {
                    $result = yield 'bar';
                    yield returnValue($result);
                })(),
            ];
        });
        loop()->run();
        $this->assertEquals(['foo', 'bar'], $yielded);
    }

    public function testYieldMixedArray()
    {
        $yielded = false;
        $exception = new \Exception('some-error');
        co(function () use (&$yielded, $exception) {
            $yielded = yield [
                (function () {
                    yield returnValue(yield 'foo');
                })(),
                \React\Promise\resolve('bar'),
                \React\Promise\reject($exception)
            ];
        });
        loop()->run();
        $this->assertEquals(['foo', 'bar', $exception], $yielded);
        $this->assertNotEquals(['bar', $exception, 'foo'], $yielded);
    }

    public function testNestedArray()
    {
        $yielded = false;
        co(function () use (&$yielded) {
            $yielded = yield [
                'aaa' => [
                    'aaa2' => [
                        \React\Promise\resolve('aaa-foo')
                    ],
                    \React\Promise\resolve('aaa-bar')
                ],
                'bar' => \React\Promise\resolve('bar')
            ];
        });
        loop()->run();
        $equal = [
            'aaa' => [
                'aaa2' => ['aaa-foo'],
                'aaa-bar'
            ],
            'bar' => 'bar'
        ];
        $not_equal = [
            'aaa' => [
                'aaa2',
                'aaa-bar'
            ],
            'bar' => 'bar'
        ];
        $this->assertEquals($equal, $yielded);
        $this->assertNotEquals($not_equal, $yielded);
    }

    public function testEmptyArray()
    {
        $yielded = false;
        co(function () use (&$yielded) {
            $yielded = yield [];
        });
        loop()->run();
        $this->assertEquals([], $yielded);
    }

    public function testNestArrayWithError()
    {
        $yielded = false;
        $exception = false;
        $message = md5(uniqid(time(), true));
        co(function () use (&$yielded, &$exception, $message) {
            try {
                $yielded = yield [
                    'aaa' => [
                        'aaa2' => [
                            'error' => (function () use ($message) {
                                throw new \RuntimeException($message);
                            })(),
                        ],
                        \React\Promise\resolve('aaa-bar')
                    ],
                    'bar' => \React\Promise\resolve('bar')
                ];
            } catch (Exception $e) {
                $exception = $e;
            }
        });
        loop()->run();
        $this->assertEquals(new \RuntimeException($message), $exception);
        $this->assertNotEquals(new \RuntimeException($message . 'a'), $exception);
    }

    public function testDefer()
    {
        $yielded = false;
        $st = microtime(true);
        defer(2, function () use (&$yielded) {
            $yielded = yield resolve('foo');
        });
        loop()->run();
        $this->assertEquals('foo', $yielded);
        $time_used = microtime(true) - $st;
        $this->assertGreaterThanOrEqual(2, $time_used);
        $this->assertLessThanOrEqual(2.2, $time_used);
    }

    public function testDeferWithResult()
    {
        $st = microtime(true);
        $promise = defer(2, function () {
            return resolve('foo');
        });
        loop()->run();
        $value = self::unwrapSettledPromise($promise);
        $this->assertEquals('foo', $value);
        $time_used = microtime(true) - $st;
        $this->assertGreaterThanOrEqual(2, $time_used);
    }

    public function testDeferWithCancel()
    {
        $st = microtime(true);
        $promise = defer(2, function () {
            yield resolve('foo');
        });
        $exception = null;
        $promise->otherwise(function ($e) use (&$exception) {
            $exception = $e;
        });
        $promise->cancel();
        loop()->run();
        $this->assertNotNull($exception);
        $time_used = microtime(true) - $st;
        $this->assertLessThanOrEqual(1, $time_used);
    }

    public function testDeferWithParams()
    {
        $yielded = false;
        $st = microtime(true);
        $content = md5(uniqid(time(), true));
        defer(2, function ($content) use (&$yielded) {
            $yielded = yield resolve($content);
        }, $content);
        loop()->run();
        $this->assertEquals($content, $yielded);
        $time_used = microtime(true) - $st;
        $this->assertGreaterThanOrEqual(2, $time_used);
        $this->assertLessThanOrEqual(2.2, $time_used);
    }

    public function testGo()
    {
        $yielded = null;
        $word = 'hello-world';
        go(function ($promise) use (&$yielded) {
            $yielded = yield $promise;
        }, resolve($word));
        loop()->run();
        $this->assertEquals($word, $yielded);
    }

    public function testCancelDuringProgress()
    {
        $foo = new RuntimeException('foo');
        $bar = new RuntimeException('bar');
        $throwable = null;
        co(function ($foo, $bar) {
            $deferred = new Deferred(function ($_, $reject) use ($foo) {
                $reject($foo);
            });
            $coroutine = yield new GetCoroutine();
            setTimeout(0.5, function () use ($coroutine, $bar) {
                $coroutine->cancel($bar);
            });
            yield $deferred->promise();
            yield returnValue(true);
        }, $foo, $bar)
        ->otherwise(function ($e) use (&$throwable) {
                $throwable = $e;
            }
        );
        loop()->run();
        $this->assertEquals($bar, $throwable);
    }

    public function testCancelDuringProgressWithNull()
    {
        $foo = new RuntimeException('foo');
        $expected = new CancelException('Coroutine is cancelled');
        $throwable = null;
        co(function ($foo) {
            $deferred = new Deferred(function ($_, $reject) use ($foo) {
                $reject($foo);
            });
            $coroutine = yield new GetCoroutine();
            setTimeout(0.5, function () use ($coroutine) {
                $coroutine->cancel(null);
            });
            yield $deferred->promise();
            yield returnValue(true);
        }, $foo)
        ->otherwise(function ($e) use (&$throwable) {
                $throwable = $e;
            }
        );
        loop()->run();
        $this->assertEquals($expected, $throwable);
    }
}
