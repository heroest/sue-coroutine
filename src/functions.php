<?php

namespace Sue\Coroutine;

/**
 * 以协程的方式执行
 *
 * @param callable $callable
 * @param mixed ...$params
 * @return \React\Promise\Promise|\React\Promise\PromiseInterface
 */
function co(callable $callable, ...$params)
{
    return \Sue\Coroutine\Scheduler::getInstance()
        ->execute($callable, ...$params);
}

/**
 * 以指定协程class的方式执行
 *
 * @param string $coroutine_class
 * @param callable $callable
 * @param mixed ...$params
 * @return \React\Promise\Promise|\React\Promise\PromiseInterface
 */
function coAs($coroutine_class, callable $callable, ...$params)
{
    return \Sue\Coroutine\Scheduler::getInstance()
        ->executeAs($coroutine_class, $callable, ...$params);
}

/**
 * 以阻塞的方式执行一段协程
 * *** 不能在eventloop已经启动情况下使用 ***
 *
 * @param callable $callable
 * @param integer $timeout
 * @return mixed
 * @throws \Exception
 */
function async(callable $callable, $timeout = 0)
{
    $timeout = (float) $timeout;

    return \Sue\EventLoop\await(co($callable), $timeout);
}

/**
 * 以协程方式运行，与co()方法一样，但是没有返回值
 *
 * @param callable $callable
 * @param mixed ...$params
 * @return void
 * @deprecated 2.0
 */
function go(callable $callable, ...$params)
{
    co($callable, ...$params);
}

/**
 * 延迟若干秒后以协程方式执行
 *
 * @param float $seconds
 * @param callable $callable
 * @param mixed ...$params
 * @return \React\Promise\Promise
 */
function defer($seconds, callable $callable, ...$params)
{
    $seconds = (float) $seconds;

    /** @var null|\React\Promise\Promise $promise */
    $promise = null;
    $deferred = new \React\Promise\Deferred(function ($_, $reject) use (&$promise) {
        $promise->otherwise(function ($e) use ($reject) {
            $reject($e);
        });
        $promise->cancel();
    });
    $closure = function (\React\Promise\Deferred $deferred, $seconds, callable $callable, array $params) {
        yield \Sue\Coroutine\SystemCall\pause($seconds);
        $deferred->resolve(co($callable, ...$params));
    };
    $promise = \Sue\Coroutine\Scheduler::getInstance()
        ->execute($closure, $deferred, $seconds, $callable, $params);
    return $deferred->promise();
}

namespace Sue\Coroutine\SystemCall;

/**
 * 系统调用：延迟执行
 * 可以用\Sue\Coroutine\SystemCall\pause()替代
 *
 * @param float $seconds 秒
 * @return \Sue\Coroutine\SystemCall\AbstractSystemCall
 * @deprecated 2.0
 */
function sleep($seconds)
{
    return pause($seconds);
}

/**
 * 系统调用：延迟执行
 *
 * @param float $seconds 秒
 * @return \Sue\Coroutine\SystemCall\AbstractSystemCall
 */
function pause($seconds)
{
    $seconds = (float) $seconds;

    return new \Sue\Coroutine\SystemCall\Sleep($seconds);
}

/**
 * 系统调用：设置当前协程最大运营时间
 *
 * @param float $seconds 秒
 * @return \Sue\Coroutine\SystemCall\AbstractSystemCall
 */
function timeout($seconds)
{
    $seconds = (float) $seconds;

    return new \Sue\Coroutine\SystemCall\Timeout($seconds);
}

/**
 * 系统调用：取消当前协程进行，并抛出异常
 *
 * @param string $message 错误消息
 * @param integer $code 错误编码
 * @return \Sue\Coroutine\SystemCall\AbstractSystemCall
 */
function cancel($message, $code = 500)
{
    $message = (string) $message;
    $code = (int) $code;

    return new \Sue\Coroutine\SystemCall\Cancel($message, $code);
}

/**
 * 系统调用，取消当前协程执行， 并返回指定的值
 * php7.0 以上没有必要，直接return就好
 *
 * @param mixed $value
 * @return void
 */
function returnValue($value)
{
    return new \Sue\Coroutine\SystemCall\ReturnValue($value);
}