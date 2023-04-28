<?php

namespace Sue\Coroutine\SystemCall;

use Sue\Coroutine\Coroutine;

class Timeout extends AbstractSystemCall
{
    /** @var float $seconds 超时的时间 */
    private $seconds;

    /**
     * @param float $seconds 超时的时间（秒）
     */
    public function __construct($seconds)
    {
        $this->seconds = (float) $seconds;
    }

    public function run(Coroutine $coroutine)
    {
        $coroutine->setTimeout($this->seconds);
    }
}
