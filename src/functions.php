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
 * 以协程方式运行，与co()方法一样，但是没有返回值
 *
 * @param callable $callable
 * @param mixed ...$params
 * @return void
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

    $closure = function ($seconds, callable $callable, array $params) {
        yield \Sue\Coroutine\SystemCall\sleep($seconds);
        return co($callable, ...$params);
    };
    return \Sue\Coroutine\Scheduler::getInstance()
        ->execute($closure, $seconds, $callable, $params);
}

namespace Sue\Coroutine\SystemCall;

/**
 * 系统调用：延迟执行
 *
 * @param float $seconds 秒
 * @return \Sue\Coroutine\SystemCall\AbstractSystemCall
 */
function sleep($seconds)
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
