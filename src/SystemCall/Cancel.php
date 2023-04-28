<?php

namespace Sue\Coroutine\SystemCall;

use Sue\Coroutine\Coroutine;
use Sue\Coroutine\Scheduler;
use Sue\Coroutine\Exceptions\CancelException;

class Cancel extends AbstractSystemCall
{
    /** @var string $message 错误信息 */
    private $message = '';

    /** @var int $code 错误编码 */
    private $code = 0;

    /**
     * @param string $message
     * @param integer $code
     */
    public function __construct($message, $code = 500)
    {
        $this->message = (string) $message;
        $this->code = (int) $code;
    }

    public function run(Coroutine $coroutine)
    {
        $exception = new CancelException($this->message, $this->code);
        Scheduler::getInstance()->cancelCoroutine($coroutine, $exception);
    }
}
