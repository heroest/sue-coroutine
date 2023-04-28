<?php

namespace Sue\Coroutine;

use Exception;
use Generator;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use Sue\Coroutine\Exceptions\CancelException;

use function Sue\EventLoop\call;

class Coroutine
{
    const IDLE = 1;
    const WORKING = 2;
    const PROGRESS = 3;
    const SETTLED = 4;

    /** @var Generator $generator 迭代器 */
    private $generator;

    /** @var Deferred $deferred Deferred */
    private $deferred;

    /** @var int $state 状态 */
    private $state;

    /** @var Promise $progress 挂起的Promise */
    private $progress;

    /** @var float $timeExpired 过期时间，默认0(不过期) */
    private $timeExpired = 0;

    /** @var float $timeout */
    private $timeout = 0;

    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
        $this->deferred = new Deferred(function () {
            $this->cancel(new CancelException('Coroutine is cancelled by promise cancellation'));
        });
        $this->state = self::WORKING;
    }

    /**
     * 获取Promise
     *
     * @return PromiseInterface|Promise
     */
    public function promise()
    {
        return $this->deferred->promise();
    }

    /**
     * 是否处于某个额状态
     *
     * @param integer $state
     * @return bool
     */
    public function in($state)
    {
        $state = (int) $state;

        return $this->state === $state;
    }

    /**
     * 是否已超时
     *
     * @return bool
     */
    public function isTimeout()
    {
        return $this->timeExpired and microtime(true) > $this->timeExpired;
    }

    /**
     * 设置超时时间
     *
     * @param float $timeout
     * @return self
     */
    public function setTimeout($timeout)
    {
        $timeout = (float) $timeout;

        $this->timeout = $timeout;
        $this->timeExpired = (float) bcadd(microtime(true), $timeout, 4);
        return $this;
    }

    /**
     * 获取超时的时间
     *
     * @return float
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * 从Generator上fetch一个数据
     *
     * @return mixed|null
     */
    public function get()
    {
        return call(function () {
            if ($this->generator->valid()) {
                return $this->generator->current();
            } elseif (method_exists($this->generator, 'getReturn')) { //php7开始才允许在generator里使用return方法
                $this->generator->next();
                return call_user_func([$this->generator, 'getReturn']);
            } else {
                return null;
            }
        });
    }

    /**
     * 将处理后的数据塞回到Generator上
     *
     * @param mixed $value
     * @return void
     */
    public function set($value)
    {
        if ($this->generator->valid() and !$this->in(self::SETTLED)) {
            try {
                $method = $value instanceof Exception ? 'throw' : 'send';
                call([$this->generator, $method], $value);
            } catch (Exception $e) {
                $this->settle($e);
            }
        } else {
            $this->settle($value);
        }
    }

    /**
     * 用promise将协程挂起，直到promise被fulfilled or rejected
     *
     * @param PromiseInterface $promise
     * @return void
     */
    public function progress(PromiseInterface $promise)
    {
        /** @var \React\Promise\ExtendedPromiseInterface $promise */
        $this->progress = $promise;
        $this->state = self::PROGRESS;
        $closure = function ($value) {
            $this->progress = null;
            if ($this->in(self::PROGRESS)) {
                $this->state = self::WORKING;
            }
            $this->set($value);
        };
        $promise->done($closure, $closure);
    }

    /**
     * 取消协程继续运行
     *
     * @param Exception|null $exception
     * @return void
     */
    public function cancel(Exception $exception = null)
    {
        if (null !== $exception) {
            $this->settle($exception);
        }

        if ($this->progress) {
            $this->progress->cancel();
            $this->progress = null;
        }
    }

    /**
     * 将Coroutine置为已完成状态
     *
     * @param mixed $value
     * @return void
     */
    private function settle($value)
    {
        $this->state = self::SETTLED;
        $value instanceof Exception
            ? $this->deferred->reject($value)
            : $this->deferred->resolve($value);
    }
}
