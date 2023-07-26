<?php

namespace Sue\Coroutine;

use Throwable;
use Exception;
use Generator;
use SplObjectStorage;
use BadMethodCallException;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Promise\CancellationQueue;
use React\EventLoop\TimerInterface;
use Sue\Coroutine\Coroutine;
use Sue\Coroutine\Exceptions\TimeoutException;
use Sue\Coroutine\Exceptions\CancelException;
use Sue\Coroutine\SystemCall\AbstractSystemCall;
use Sue\Coroutine\SystemCall\ReturnValue;

use function React\Promise\resolve;
use function React\Promise\reject;
use function Sue\EventLoop\setInterval;
use function Sue\EventLoop\cancelTimer;

class Scheduler
{
    /** @var self $instance 实例 */
    private static $instance;

    /** @var string $coroutineClass */
    private $coroutineClass = Coroutine::class;

    /** @var SplObjectStorage $coroutineWorking */
    private $coroutineWorking;

    /** @var null|TimerInterface $ticker */
    private $ticker = null;

    private function __construct()
    {
        $this->coroutineWorking = new SplObjectStorage();
    }

    /**
     * 获取唯一实例
     *
     * @return self
     */
    public static function getInstance()
    {
        null === self::$instance and self::$instance = new self();
        return self::$instance;
    }

    /**
     * 设置自定义Coroutine Class
     *
     * @param string $custom_class
     * @return void
     */
    public function setCustomCoroutineClass(string $custom_class)
    {
        $base_class = Coroutine::class;
        if (!is_subclass_of($custom_class, $base_class)) {
            throw new BadMethodCallException("{$custom_class} is not a subclass of {$base_class}");
        }
        $this->coroutineClass = $custom_class;
        return $this;
    }

    /**
     * 在调度器里执行某个方法
     *
     * @param callable $callable
     * @param mixed ...$params
     * @return PromiseInterface|Promise
     */
    public function execute(callable $callable, ...$params)
    {
        try {
            $result = call_user_func_array($callable, $params);
            if ($result instanceof Generator) {
                $coroutine = $this->createCoroutine($result);
                $this->ticker or $this->ticker = setInterval(0, [$this, 'tick']);
                return $coroutine->promise();
            } else {
                return resolve($result);
            }
        } catch (Throwable $e) {
            return reject($e);
        } catch (Exception $e) {
            return reject($e);
        }
    }

    /**
     * 取消协程执行
     *
     * @param Coroutine $coroutine
     * @param Exception|null $exception
     * @return void
     */
    public function cancelCoroutine(Coroutine $coroutine, Exception $exception)
    {
        $coroutine->cancel($exception);
        $this->detachCoroutine($coroutine);
    }

    /**
     * 处理yield值
     *
     * @param Coroutine $coroutine
     * @param mixed $yielded
     * @return void
     */
    private function handleYielded(Coroutine $coroutine, $yielded)
    {
        switch (true) {
            case $yielded instanceof PromiseInterface:
                $this->handlePromise($yielded, $coroutine);
                break;

            case $yielded instanceof Generator:
                $this->handleGenerator($yielded, $coroutine);
                break;

            case $yielded instanceof AbstractSystemCall:
                /** @var AbstractSystemCall $yielded */
                $this->handleYielded($coroutine, $yielded->wrapRun($coroutine));
                break;

            case is_array($yielded):
                $this->handleArray($yielded, $coroutine);
                break;

            default:
                $coroutine->set($yielded);
                break;
        }
    }

    /**
     * 从working组中移除Coroutine
     *
     * @param Coroutine $coroutine
     * @return void
     */
    private function detachCoroutine(Coroutine $coroutine)
    {
        $this->coroutineWorking->detach($coroutine);
    }

    /**
     * 处理promise
     *
     * @param PromiseInterface $promise
     * @param Coroutine $coroutine
     * @return void
     */
    private function handlePromise(PromiseInterface $promise, Coroutine $coroutine)
    {
        $coroutine->progress($promise);
    }

    /**
     * 处理nested generator
     *
     * @param Generator $generator
     * @param Coroutine $parent
     * @return void
     */
    private function handleGenerator(Generator $generator, Coroutine $parent)
    {
        $this->handlePromise(
            $this->createCoroutine($generator)->promise(),
            $parent
        );
    }

    /**
     * 处理数组
     *
     * @param array $items
     * @param Coroutine $coroutine
     * @return Promise|PromiseInterface
     */
    private function handleArray(array $items, Coroutine $coroutine)
    {
        if (0 === count($items)) {
            $promise = resolve($items);
            $this->handlePromise($promise, $coroutine);
            return $promise;
        }

        $promises = [];
        foreach ($items as $key => $item) {
            switch (true) {
                case $item instanceof PromiseInterface:
                    $promises[$key] = $item;
                    break;

                case $item instanceof Generator:
                    $child = $this->createCoroutine($item);
                    $promises[$key] = $child->promise();
                    break;

                case is_array($item):
                    $generator = (static function () use ($item) {
                        yield new ReturnValue(yield $item);
                    })();
                    $child = $this->createCoroutine($generator);
                    $promises[$key] = $child->promise();
                    break;

                default:
                    $promises[$key] = resolve($item);
                    break;
            }
        }
        $promise = $this->await($promises);
        $this->handlePromise($promise, $coroutine);
        return $promise;
    }

    /**
     * 批量处理promise
     *
     * @param array|Promise[] $promises
     * @return Promise
     */
    private function await(array $promises)
    {
        $canceller = new CancellationQueue();
        $deferred = new Deferred(function ($_, $reject) use ($canceller) {
            $reject(new CancelException("Awaitable promise has been cancelled"));
            $canceller();
        });

        $todo_count = count($promises);
        $result = [];
        foreach ($promises as $index => $promise) {
            $handler = function ($value) use ($index, $deferred, &$result, &$todo_count) {
                $result[$index] = $value;
                if (0 === --$todo_count) {
                    $deferred->resolve($result);
                }
            };
            $promise->done($handler, $handler);
            $canceller->enqueue($promise);
        }
        return $deferred->promise();
    }

    /**
     * 创建协程
     *
     * @param Generator $generator
     * @return Coroutine
     */
    private function createCoroutine(Generator $generator)
    {
        $class = $this->coroutineClass;
        $coroutine = new $class($generator);
        $this->coroutineWorking->attach($coroutine);
        return $coroutine;
    }

    /**
     * 在eventloop上注册的tick方法， interval = 0
     *
     * @return \Closure
     */
    public function tick()
    {
        if (0 === $count = $this->coroutineWorking->count()) {
            cancelTimer($this->ticker);
            $this->ticker = null;
            return;
        }

        $this->coroutineWorking->rewind();
        while ($count--) {
            /** @var Coroutine $coroutine */
            if (null === $coroutine = $this->coroutineWorking->current()) {
                return;
            }

            $this->coroutineWorking->next();
            switch (true) {
                case $coroutine->in(Coroutine::SETTLED):
                    $this->detachCoroutine($coroutine);
                    break;

                case $coroutine->isTimeout():
                    $e = new TimeoutException("Coroutine is timeout: " . $coroutine->getTimeout());
                    $this->cancelCoroutine($coroutine, $e);
                    break;

                case $coroutine->in(Coroutine::PROGRESS):
                    break;

                default:
                    try {
                        $this->handleYielded($coroutine, $coroutine->get());
                    } catch (Throwable $e) {
                        $this->cancelCoroutine($coroutine, $e);
                    } catch (Exception $e) {
                        $this->cancelCoroutine($coroutine, $e);
                    }
                    break;
            }
        }
    }
}
