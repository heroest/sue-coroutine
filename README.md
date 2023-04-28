## ReactPHP协程组件

## What is ReactPHP?

[ReactPHP](https://reactphp.org/)是一款基于PHP的事件驱动的组件。核心是提供EventLoop，然后提供基于EventLoop上的各种组件，比方说I/O处理等。ReactPHP协程组件也是基于ReactPHP提供的EventLoop

**Table of Contents**
* [Install](#install)
* [Requirements](#requirements)
* [Quickstart example](#quickstart-example)
* [Methods](#methods)
  * [\Sue\Coroutine\co](#co)
  * [\Sue\Coroutine\go](#go)
  * [\Sue\Coroutine\defer](#defer)
  * [\Sue\Coroutine\SystemCall\sleep](#sleep)
  * [\Sue\Coroutine\SystemCall\timeout](#timeout)
  * [\Sue\Coroutine\SystemCall\cancel](#cancel)
* [Tests](#tests)
* [License](#license)

## install
`$ composer require sue\coroutine` 进行安装

## requirements
> php: >= 5.6.0

## quickstart-example

```php
use React\Promise\Deferred;

use function Sue\EventLoop\loop;
use function Sue\EventLoop\setTimeout;
use function Sue\Coroutine\co;


$deferred = new Deferred();
setTimeout(3, function () use ($deferred) {
    $deferred->resolve('foo');
})

//1. 传统基于thenable方法处理promise
$deferred->promise()->then(function ($value) {
    echo "promise value: {$value}\r\n";
});

//2. 使用协程的方式处理promise
co(function ($promise) {
    echo "start waiting promise to be resolved\r\n";
    $value = yield $promise; //协程会在此处暂停，直到promise被resolve或者reject
    echo "promise value: {$value}\r\n"
}, $deferred->promise());
loop()->run();
```

## methods

## co
`\Sue\Coroutine\co($callable)` 可以将一段callable且是迭代器的代码以协程方式执行，如果callable非迭代器(generator)的话，那么会直接返回结果，使用方法如下:

```php
use function Sue\EventLoop\loop;
use function Sue\Coroutine\co;

$callable = function ($worker_id) {
    $count = 3;
    while ($count--) {
        echo "{$worker_id}: " . yield $count . "\r\n";
    }
};
co($callable, 'foo');
co($callable, 'bar');
loop()->run();
/** expect out:
 foo: 2
 bar: 2
 foo: 1
 bar: 1
 foo: 0
 bar: 0
**/
```

## go
`Sue\Coroutine\go($callable)`方法和作用同`co()`，只是没有返回值

## defer
`Sue\Coroutine\defer($interval, $callable, ...$callable_params)` 可以延迟一段时间再执行协程
```php
use function Sue\EventLoop\loop;
use function Sue\EventLoop\setInterval;
use function Sue\Coroutine\defer;

setInterval(1, function () {
    echo "tick\r\n";
});
$callable = function ($worker_id) {
    $count = 3;
    while ($count--) {
        echo "{$worker_id}: " . yield $count . "\r\n";
    }
};
defer(5, $callable, 'foo');
defer(5, $callable, 'bar');
loop()->run();
/** expect out:
 tick
 tick
 tick
 tick
 tick
 foo: 2
 bar: 2
 foo: 1
 bar: 1
 foo: 0
 bar: 0
 tick
 tick
 ...
**/
```

## sleep
`Sue\Coroutine\SystemCall\sleep($seconds)`生成一个系统指令，可以让当前协程进行休眠指定X秒，之后继续执行
```php
use Sue\Coroutine\SystemCall;

use function Sue\Coroutine\SystemCall\sleep;
use function Sue\EventLoop\loop;
use function Sue\EventLoop\setInterval;

setInterval(1, function () {
    echo "tick\r\n";
});
$callable = function ($worker_id) {
    echo "before-sleep\n";
    yield SystemCall\sleep(3); //协程会在这里sleep 3秒后再执行
    $count = 3;
    while ($count--) {
        echo "{$worker_id}: " . yield $count . "\r\n";
    }
};
co($callable, 'foo');
loop()->run();
/** expect out:
 before-sleep
 tick
 tick
 tick
 foo: 2
 foo: 1
 foo: 0
 tick
 tick
 ...
**/
```

## timeout
`\Sue\Coroutine\SystemCall\timeout($seconds)`生成一个系统指令，可以为当前协程设置最大运行时间，如果超过运行时间，则会抛出异常
```php
use React\Promise\Deferred;
use Sue\Coroutine\SystemCall;
use Sue\Coroutine\Exceptions\TimeoutException;

use function Sue\EventLoop\loop;
use function Sue\EventLoop\setTimeout;
use function Sue\Coroutine\co;

$deferred = new Deferred();
$loop->addTimer(3, function () use ($deferred) {
    $deferred->resolve('foo'); //3秒后promise fulfill
});
$promise = $deferred->promise();

$children = (function () use ($promise) {
    yield SystemCall\timeout(2); //当前协程最多运行2秒
    return yield $promise;
})();

co(function () use ($children) {
    try {
        yield $children;
    } catch (TimeoutException $e) {
        //子协程超出最大运行时间时的异常处理
    }
});

loop()->run();
```

## cancel
`Sue\Coroutine\SystemCall\cancel($message, $code)`生成一个系统指令，可以取消当前协程及其子协程，并抛出异常

```php
use Sue\Coroutine\SystemCall;
use Sue\Coroutine\Exceptions\CancelException;

use function Sue\Coroutine\co;
use function Sue\EventLoop\loop;

$children = (function () {
    if (someConditionNotMatch()) {
        yield SystemCall\cancel('condition not match', 500);
    }
});
co(function () use ($children) {
    try {
        yield $children;
    } catch (CancelException $e) {
        //子协程取消异常处理
    }
});
loop()->run();
```

## tests
先安装dependencies
```bash
composer install
```
然后执行unit
```bash
./vendor/bin/phpunit
```

## License

The MIT License (MIT)

Copyright (c) 2023 Donghai Zhang

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is furnished
to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.