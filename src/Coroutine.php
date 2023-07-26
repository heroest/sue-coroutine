<?php

namespace Sue\Coroutine;

use Throwable;
use Exception;
use Generator;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use Sue\Coroutine\Exceptions\CancelException;

use function Sue\Coroutine\Utils\isPhp7;

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

    /** @var PromiseInterface|Promise $promise */
    private $promise;

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
        $this->state = self::WORKING;

        $that = $this;
        $this->deferred = new Deferred(static function () use (&$that) {
            $that->cancel(new CancelException('Coroutine is cancelled by promise cancellation'));
            $that = null;
        });

        /** @var Promise $promise */
        $promise = $this->deferred->promise();
        $that = $this;
        $this->promise = $promise->always(static function () use (&$that) {
            if ($progress = $that->progress) {
                $that->progress = null;
                $progress->cancel();
            };
            $that = null;
        });
    }

    /**
     * 获取Promise
     *
     * @return PromiseInterface|Promise
     */
    public function promise()
    {
        return $this->promise;
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
        $result = null;
        try {
            if ($this->generator->valid()) {
                $result = $this->generator->current();
            } elseif (isPhp7()) { //php7开始才允许在generator里使用return方法
                /**
                 * 以免generator没有return语句
                 * https://www.php.net/manual/en/generator.getreturn.php#121449
                 */
                $this->generator->next();
                $result = call_user_func([$this->generator, 'getReturn']);
            }
        } catch (Throwable $e) {
            $result = $e;
        } catch (Exception $e) {
            $result = $e;
        }
        return $result;
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
                ($value instanceof Throwable or $value instanceof Exception)
                    ? $this->generator->throw($value)
                    : $this->generator->send($value);
            } catch (Throwable $e) {
                $this->settle($e);
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
        if (!$this->in(self::WORKING)) { //非working状态下不允许通过promise挂起协程
            return;
        }

        /** @var \React\Promise\ExtendedPromiseInterface $promise */
        $this->progress = $promise;
        $this->state = self::PROGRESS;
        $that = $this;
        $closure = static function ($value) use (&$that) {
            $that->progress = null;
            if ($that->in(self::PROGRESS)) {
                $that->state = self::WORKING;
            }
            $that->set($value);
            $that = null;
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
        $exception = $exception
            ?: new CancelException("Coroutine is cancelled");
        $this->settle($exception);
    }

    /**
     * 取消协程运行，并返回填充值
     *
     * @param mixed $value
     * @return void
     */
    public function fulfill($value)
    {
        $this->settle($value);
    }

    /**
     * 将Coroutine置为已完成状态
     *
     * @param mixed $value
     * @return void
     */
    protected function settle($value)
    {
        $this->state = self::SETTLED;
        ($value instanceof Throwable or $value instanceof Exception)
            ? $this->deferred->reject($value)
            : $this->deferred->resolve($value);
    }
}
