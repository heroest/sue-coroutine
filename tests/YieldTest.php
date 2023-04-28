<?php

namespace Sue\Coroutine\Tests;

use Exception;
use Sue\Coroutine\Tests\BaseTestCase;

use function React\Promise\resolve;
use function Sue\EventLoop\loop;
use function Sue\Coroutine\co;
use function Sue\Coroutine\defer;
use function Sue\Coroutine\go;

final class YieldTest extends BaseTestCase
{
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

    public function testThorwable()
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

    public function testError()
    {
        $yielded = false;
        $reject = false;
        co(function () use (&$yielded) {
            $yielded = yield 1 / 0;
        })->then(null, function ($error) use (&$reject) {
            $reject = $error;
        });
        loop()->run();
        $this->assertEquals(null, $yielded);
        $this->assertEquals(new \ErrorException('Division by zero', 2, E_USER_ERROR), $reject);
    }

    public function testErrorWithHandling()
    {
        $yielded = false;
        $reject = false;
        co(function () use (&$yielded, &$reject) {
            try {
                $yielded = yield 1 / 0;
            } catch (Exception $e) {
                $reject = $e;
            }
        });
        loop()->run();
        $this->assertEquals(null, $yielded);
        $this->assertEquals(new \ErrorException('Division by zero', 2, E_USER_ERROR), $reject);
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
            return 'bar';
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
            return $result;
        };
        $l1 = function () use ($l2) {
            yield 'foo';
            return yield $l2();
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
                    return $result;
                })(),
                (function () {
                    $result = yield 'bar';
                    return $result;
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
                    return yield 'foo';
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
}
