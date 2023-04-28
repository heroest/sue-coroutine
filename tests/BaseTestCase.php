<?php

namespace Sue\Coroutine\Tests;

use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase
{

    protected static function unwrapSettledPromise($promise)
    {
        $result = null;
        $closure = function ($val) use (&$result) {
            $result = $val;
        };
        $promise->done($closure, $closure);
        return $result;
    }
}
