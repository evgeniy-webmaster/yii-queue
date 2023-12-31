<?php
/**
 * @link https://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\queue\cli;

use Yii;
use yii\base\BootstrapInterface;
use yii\base\InvalidConfigException;
use yii\console\Application as ConsoleApp;
use yii\helpers\Inflector;
use yii\queue\Queue as BaseQueue;

/**
 * Queue with CLI.
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
abstract class Queue extends BaseQueue
{
    /**
     * @event WorkerEvent that is triggered when the worker is started.
     * @since 2.0.2
     */
    const EVENT_WORKER_START = 'workerStart';
    /**
     * @event WorkerEvent that is triggered each iteration between requests to queue.
     * @since 2.0.3
     */
    const EVENT_WORKER_LOOP = 'workerLoop';
    /**
     * @event WorkerEvent that is triggered when the worker is stopped.
     * @since 2.0.2
     */
    const EVENT_WORKER_STOP = 'workerStop';

    /**
     * @var array|string
     * @since 2.0.2
     */
    public $loopConfig = SignalLoop::class;
    /**
     * @var string command class name
     */
    public $commandClass = Command::class;
    /**
     * @var callable|null
     * @internal for worker command only
     */
    public $messageHandler;

    /**
     * @var int|null current process ID of a worker.
     * @since 2.0.2
     */
    private $_workerPid;

    /**
     * Runs worker.
     *
     * @param callable $handler
     * @return null|int exit code
     * @since 2.0.2
     */
    protected function runWorker(callable $handler)
    {
        $this->_workerPid = getmypid();
        /** @var LoopInterface $loop */
        $loop = new $this->loopConfig($this);

        $event = new WorkerEvent(['loop' => $loop]);
        $this->trigger(self::EVENT_WORKER_START, $event);
        if ($event->exitCode !== null) {
            return $event->exitCode;
        }

        $exitCode = null;
        try {
            call_user_func($handler, function () use ($loop, $event) {
                $this->trigger(self::EVENT_WORKER_LOOP, $event);
                return $event->exitCode === null && $loop->canContinue();
            });
        } finally {
            $this->trigger(self::EVENT_WORKER_STOP, $event);
            $this->_workerPid = null;
        }

        return $event->exitCode;
    }

    /**
     * Gets process ID of a worker.
     *
     * @inheritdoc
     * @return int|null
     * @since 2.0.2
     */
    public function getWorkerPid()
    {
        return $this->_workerPid;
    }

    /**
     * @inheritdoc
     */
    protected function handleMessage($id, $message, $ttr, $attempt, $finishProcessCallback = null)
    {
        if ($this->messageHandler) {
            return call_user_func($this->messageHandler, $id, $message, $ttr, $attempt, $finishProcessCallback);
        }

        return parent::handleMessage($id, $message, $ttr, $attempt, $finishProcessCallback);
    }

    /**
     * @param string $id of a message
     * @param string $message
     * @param int $ttr time to reserve
     * @param int $attempt number
     * @param int|null $workerPid of worker process
     * @return bool
     * @internal for worker command only
     */
    public function execute($id, $message, $ttr, $attempt, $workerPid)
    {
        $this->_workerPid = $workerPid;
        return parent::handleMessage($id, $message, $ttr, $attempt);
    }
}
