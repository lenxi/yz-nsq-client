<?php

namespace Zan\Framework\Components\Nsq;


use Zan\Framework\Components\Contract\Nsq\ConnDelegate;
use Zan\Framework\Components\Contract\Nsq\NsqdDelegate;
use Zan\Framework\Foundation\Contract\Async;
use Zan\Framework\Foundation\Core\Debug;
use Zan\Framework\Foundation\Coroutine\Task;
use Zan\Framework\Network\Server\Timer\Timer;


class Producer implements ConnDelegate, NsqdDelegate, Async
{
    // 每个topic共享一个Producer实例
    // 单独保存parallel条件下每个async callback
    private $callbacks = [];

    private $topic;

    private $lookup;

    private $stats;

    public function __construct($topic, $maxConnectionNum = 1)
    {
        $this->topic = Command::checkTopicChannelName($topic);
        $this->lookup = new Lookup($this->topic, $maxConnectionNum);
        $this->lookup->setNsqdDelegate($this);

        $this->stats = [
            "messagesPublished" => 0,
        ];
    }

    public function connectToNSQLookupds(array $addresses)
    {
        foreach ($addresses as $address) {
            yield $this->connectToNSQLookupd($address);
        }
    }

    public function connectToNSQLookupd($address)
    {
        yield $this->lookup->connectToNSQLookupd($address);
    }

    public function disconnectFromNSQLookupd($addr)
    {
        $this->lookup->disconnectFromNSQLookupd($addr);
    }

    public function connectToNSQD($host, $port)
    {
        yield $this->lookup->connectToNSQD($host, $port);
    }

    public function disconnectFromNSQD($host, $port)
    {
        $this->lookup->disconnectFromNSQD($host, $port);
    }

    public function getNsqdConns()
    {
        return $this->lookup->getNSQDConnections();
    }

    /**
     * @return \Generator
     */
    private function take()
    {
        yield $this->lookup->take();
    }

    private function release(Connection $conn)
    {
        return $this->lookup->release($conn);
    }

    /**
     * @param Connection $conn
     * @return \Generator
     */
    private function reconnectToNSQD(Connection $conn)
    {
        yield $this->lookup->reconnect($conn);
    }

    /**
     * 统计信息
     * @return array
     */
    public function stats()
    {
        return $this->stats + $this->lookup->stats();
    }

    /**
     * Publish a message to a topic
     * @param string $message
     * @throws NsqException
     * @return \Generator
     *
     * Success Response:
     *  OK
     * Error Responses (NsqException):
     *  E_INVALID
     *  E_BAD_TOPIC
     *  E_BAD_MESSAGE
     *  E_MPUB_FAILED
     */
    public function publish($message)
    {
        /* @var Connection $conn */
        list($conn) = (yield $this->take());
        $conn->writeCmd(Command::publish($this->topic, $message));

        $this->stats["messagesPublished"]++;

        Timer::after(NsqConfig::getPublishTimeout(),
            $this->onPublishTimeout((yield getTaskId())),
            $this->getPublishTimeoutTimerId($conn));

        yield $this;
    }

    /**
     * Publish multiple messages to a topic (atomically)
     * (useful for high-throughput situations to avoid roundtrips and saturate the pipe)
     * @param array $messages
     * @throws NsqException
     * @return \Generator
     *
     * Success Response:
     *  OK
     * Error Responses (NsqException):
     *  E_INVALID
     *  E_BAD_TOPIC
     *  E_BAD_BODY
     *  E_BAD_MESSAGE
     *  E_MPUB_FAILED
     */
    public function multiPublish(array $messages)
    {
        /* @var Connection $conn */
        list($conn) = (yield $this->take());
        $conn->writeCmd(Command::multiPublish($this->topic, $messages));

        $this->stats["messagesPublished"] += count($messages);

        Timer::after(NsqConfig::getPublishTimeout(),
            $this->onPublishTimeout((yield getTaskId())),
            $this->getPublishTimeoutTimerId($conn));

        yield $this;
    }


    /**
     * onConnected is called when nsqd connects
     * @param Connection $conn
     * @return void
     */
    public function onConnect(Connection $conn)
    {
        $conn->setDelegate($this);
    }

    /**
     * OnResponse is called when the connection
     * receives a FrameTypeResponse from nsqd
     * @param Connection $conn
     * @param string $bytes
     * @return void
     */
    public function onResponse(Connection $conn, $bytes)
    {
        $this->onPublishResponse($conn, $bytes);

        if ($bytes === "CLOSE_WAIT") {
            // nsqd ack客户端的StartClose, 准备关闭
            // 可以假定不会再收到任何nsqd的消息
            $conn->tryClose();
        }
    }

    /**
     * OnError is called when the connection
     * receives a FrameTypeError from nsqd
     * @param Connection $conn
     * @param $bytes
     * @return void
     */
    public function onError(Connection $conn, $bytes)
    {
        $this->onPublishResponse($conn, "CONN_ERROR", new NsqException($bytes));
    }

    /**
     * OnIOError is called when the connection experiences
     * a low-level TCP transport error
     * @param Connection $conn
     * @param \Exception $ex
     * @return void
     */
    public function onIOError(Connection $conn, \Exception $ex)
    {
        $this->onPublishResponse($conn, "IO_ERROR", $ex);

        try {
            $this->lookup->removeConnection($conn);
            $conn->tryClose();
        } catch (\Exception $ignore) {
            sys_echo("nsq({$conn->getAddr()}) onIOError exception: {$ex->getMessage()}");
        }
    }

    /**
     * OnClose is called when the connection
     * closes, after all cleanup
     * @param Connection $conn
     * @throws NsqException
     * @return void
     */
    public function onClose(Connection $conn)
    {
        $this->onPublishResponse($conn, "CONN_CLOSED", new NsqException("connection close, maybe disposable connection timeout"));

        if (!$conn->isDisposable()) {
            $this->lookup->removeConnection($conn);

            $remainConnNum = count($this->getNsqdConns());
            sys_echo("nsq({$conn->getAddr()}) producer onClose: there are $remainConnNum connections left alive");

            if ($this->lookup->isStopped()/* && $remainConnNum === 0*/) {
                return;
            }

            Task::execute($this->reconnectToNSQD($conn));
        }
    }

    private function onPublishResponse(Connection $conn, $retval, \Exception $ex = null)
    {
        Timer::clearAfterJob($this->getPublishTimeoutTimerId($conn));
        $this->release($conn);
        if ($this->callbacks) {
            foreach ($this->callbacks as $i => $callback) {
                unset($this->callbacks[$i]);
                call_user_func($callback, $retval, $ex);
            }
        }
    }

    private function onPublishTimeout($tid)
    {
        return function() use($tid) {
            if (isset($this->callbacks[$tid])) {
                call_user_func($this->callbacks[$tid], "TIME_OUT", new NsqException("publish timeout"));
                unset($this->callbacks[$tid]);
            }
        };
    }

    public function onReceive(Connection $conn, $bytes) {
        if (Debug::get()) {
            sys_echo("nsq({$conn->getAddr()}) recv:" . str_replace("\n", "\\n", $bytes));
        }
    }

    public function onSend(Connection $conn, $bytes) {
        if (Debug::get()) {
            sys_echo("nsq({$conn->getAddr()}) send:" . str_replace("\n", "\\n", $bytes));
        }
    }

    public function execute(callable $callback, $task)
    {
        /* @var Task $task */
        $this->callbacks[$task->getTaskId()] = $callback;
    }

    private function getPublishTimeoutTimerId(Connection $conn)
    {
        return sprintf("%s_%s_public_time_out", spl_object_hash($this), spl_object_hash($conn));
    }

    public function onHeartbeat(Connection $conn) {}
    public function onMessage(Connection $conn, Message $msg) {}
    public function onMessageFinished(Connection $conn, Message $msg) {}
    public function onMessageRequeued(Connection $conn, Message $msg) {}
    public function onBackoff(Connection $conn) {}
    public function onContinue(Connection $conn) {}
    public function onResume(Connection $conn) {}
}