sue\coroutine
====================
提供基于sue/event-loop的协程组件

## What is sue\coroutine
`sue\coroutine`是基于`sue\event-loop`事件循环生态的协程方案，底层是php的迭代器，但是针对php5版本做了兼容处理以及一些针对协程的控制逻辑。 `sue\event-loop`是基于流程的ReactPHP进行的开发

## What is ReactPHP?

[ReactPHP](https://reactphp.org/)是一款基于PHP的事件驱动的组件。核心是提供EventLoop，然后提供基于EventLoop上的各种组件，比方说I/O处理等。sue/event-loop组件也是基于ReactPHP提供的EventLoop

**Table of Contents**
* [Install](#install)
* [Requirements](#requirements)
* [PHP5兼容方案](#php5兼容方案)
* [Quickstart example](#quickstart-example)
* [functions](#functions)
  * [\Sue\Coroutine\co()](#co)
  * [\Sue\Coroutine\coAs()](#coAs)
  * [\Sue\Coroutine\go()](#go)
  * [\Sue\Coroutine\defer()](#defer)
  * [\Sue\Coroutine\async()](#async)
  * [\Sue\Coroutine\SystemCall\pause()](#pause)
  * [\Sue\Coroutine\SystemCall\sleep()](#sleep)
  * [\Sue\Coroutine\SystemCall\timeout()](#timeout)
  * [\Sue\Coroutine\SystemCall\cancel()](#cancel)
  * [\Sue\Coroutine\SystemCall\returnValue()](#returnValue)
* [Tests](#tests)
* [License](#license)

## install
`$ composer require sue\coroutine` 进行安装

## requirements
> php: >= 5.6.0

## php5兼容方案
`sue\coroutine`是基于php generator实现。php7版本开始允许在迭代器中加入`return`用来控制返回，但是在php5的迭代器中使用return会报错。所以php5可以用`Sue\Coroutine\SystemCall\returnValue`来代替`return`

```php
co(function () {
    if (someCondition()) {
        yield \Sue\Coroutine\SystemCall\returnValue(false);
        //以上语句等同于在php7的 return false
    }

    yield someAsyncPromise();
    doSomethingAfterPromiseResolved();
});
```

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

## functions

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

## coAs
`\Sue\Coroutine\coAs($coroutine_class, $callable)`方法同`co`, 区别是可以使用自定义的协程class（需要继承\Sue\Coroutine\Coroutine)。用此方法启动的协程中的子协程也会自动继承父级使用的自定义协程class
```php
namespace App\Custom;

class CustomCoroutine extends \Sue\Coroutine\Coroutine
{
    public function get()
    {
        $result = parent::get();
        Log::info('result got', ['data' => $result]);
    }
}
coAs(\App\Custom\CustomCoroutine::class, $callable);
```

## go
*** 请不要使用, 计划未来移除 ***
`Sue\Coroutine\go($callable)`方法和作用同`co()`，只是没有返回值

## async
`\Sue\Coroutine\async($callable, $timeout)`方法可以临时启动一个eventloop来执行一段协程的代码, 当协程执行完毕或者超时后eventloop将被关闭. 该方法适用于传统的阻塞php模型（比如php-fpm）中使用异步的特性. 方法返回协程返回值或者抛出异常
*** 该方法无法在一个已启动eventloop中使用 ***
```php
try {
    $timeout = 15;
    $result = async(function () {
        $promise_a = someHeavyOperation();
        $promise_b = someHeavyOperation();
        //让两个promise并行处理
        list($value_a, $value_b) = yield [$promise_a, $promise_b];
        yield returnValue(['a' => $value_a, 'b' => $value_b]);
    }, $timeout);
    doSomething($result['a']);
    doSomething($result['b']);
    //handle result
} catch (Throwable $e) {
    //error handle
}
```

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

## pause
`Sue\Coroutine\pause($seconds)`可以生成一条系统指令，效果是让当前协程暂停执行并进行休眠，之后继续执行
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

## sleep
**建议用`\Sue\Coroutine\SystemCall\pause`方法代替，效果一致，此方法未来移除**
`Sue\Coroutine\SystemCall\sleep($seconds)`效果同`\Sue\Coroutine\SystemCall\pause($seconds)`


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

## returnValue
*** php7以上版本可以直接用`return` ***
`Sue\Coroutine\SystemCall\returnValue($value)`生成一条系统指令，可以中止当前协程及子协程，并返回value值

```php
$children = (function () {
    if (someCondition()) {
        yield \Sue\Coroutine\SystemCall\returnValue\returnValue('foo');
        //等同于php7+中的: return 'foo';
    }

    yield someAsyncPromise();
    return 'bar';
})();

co(function () use ($children) {
    $name = yield $children;
});
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