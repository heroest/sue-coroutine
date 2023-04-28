<?php

namespace Sue\Coroutine\SystemCall;

use React\Promise\Timer;
use Sue\Coroutine\Coroutine;

use function Sue\EventLoop\loop;

class Sleep extends AbstractSystemCall
{
    /** @var float $sleep sleep的时间 */
    private $seconds;

    public function __construct($seconds)
    {
        $this->seconds = (float) $seconds;
    }

    public function run(Coroutine $coroutine)
    {
        return Timer\sleep($this->seconds, loop());
    }
}
