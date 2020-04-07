<?php

class ProxyCenter
{
    // 转发服务对象
    private $proxy = null;

    // 正在运行的PHP服务节点
    private $phpNodes = [];

    // 异步任务数量
    private $taskWorkingNum = 0;

    /**
     * ProxyServ constructor.
     * @param string  $host 主机
     * @param integer $port 端口
     * @param array   $sets 设置
     */
    public function __construct(string $host, int $port, array $sets = [])
    {
        // 初始化转发服务
        $this->proxy = $this->_initProxyServer($host, $port, $sets);
    }

    /**
     * @param  string  $host 主机
     * @param  integer $port 端口
     * @param  array   $sets 设置
     * @return object
     */
    private function _initProxyServer(string $host, int $port, array $sets = [])
    {
        // 创建Server对象
        $serv = new Swoole\Server($host, $port);
        if (!empty($sets)) {
            $serv->set($sets);
        }

        // 设置监听事件
        $serv->on('connect', [$this, 'onConnect']);
        $serv->on('receive', [$this, 'onReceive']);
        $serv->on('task', [$this, 'onTask']);
        $serv->on('finish', [$this, 'onFinish']);
        $serv->on('close', [$this, 'onClose']);

        // 启动服务
        $serv->start();

        return $serv;
    }

    /**
     * 监听新连接事件
     * @param object  $serv
     * @param integer $fd
     */
    public function onConnect($serv, $fd)
    {
        // 检测PHP服务节点
        $redis = new Redis();
        $redis->connect("127.0.0.1", 6379);
        $this->phpNodes = $redis->sMembers('php_serv_nodes');

        echo "ProxyServer started..." . PHP_EOL;
    }

    /**
     * 监听接收消息事件
     * @param object  $serv
     * @param integer $fd
     * @param integer $fromid
     * @param string  $data
     */
    public function onReceive($serv, $fd, $fromid, $data)
    {
        // 启动异步任务分发
        $taskData = json_encode([
            'fd'    => $fd,
            'nodes' => $this->phpNodes,
            'data'  => $data
        ]);
        $taskid = $serv->task($taskData);
        // 修改异步任务数量
        $this->taskWorkingNum++;

        echo "Dispatch AsyncTask: id={$taskid}" . PHP_EOL;
    }

    /**
     * 监听异步任务事件
     * @param object  $serv
     * @param integer $task
     */
    public function onTask($serv, $task)
    {
        echo "New AsyncTask: id={$task->id}, fromid={$task->worker_id}, data={$task->data}" . PHP_EOL;

        // 遍历PHP服务节点
        $taskResult = false;
        $taskData = json_decode($task->data, true);
        $phpServ = new Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
        foreach ($taskData['nodes'] as $node) {
            // 连接PHP服务节点
            $connRes = $this->connPhpServNode($node, $phpServ);
            if ($connRes) {
                continue;
            }
            // 转发数据
            $result = $phpServ->send($taskData['data']);
            if (!$result) {
                // 转发失败
                echo "TransData failed, host: {$node}" . PHP_EOL;
            }

            // 关闭连接
            $taskResult = $taskResult || $result;
            $phpServ->close();
        }

        // 响应数据给C++服务器
        $taskResult = (int) $taskResult;
        $statusCode = ['500 Internal Server Error', '200 Ok'];
        $serv->send($taskData['fd'], implode("\r\n", [
            "HTTP/1.1 {$statusCode[$taskResult]}",
            "Content-Length: 0"
        ])."\r\n\r\n");

        // 任务完成
        $task->finish('task finish');
    }

    /**
     * 监听异步任务完成事件
     * @param object  $serv
     * @param integer $taskid
     * @param string  $data
     */
    public function onFinish($serv, $taskid, $data)
    {
        // 修改异步任务数量
        $this->taskWorkingNum--;

        echo "AsyncTask Finish: id={$taskid}, task_num={$this->taskWorkingNum}" . PHP_EOL;
    }

    /**
     * 监听连接关闭事件
     * @param object  $serv
     * @param integer $fd
     */
    public function onClose($serv, $fd)
    {
        // 检查任务是否有异步任务进行
        while ($this->taskWorkingNum > 0) {
            Co::sleep(1);
        }

        echo "ProxyServer closed..." . PHP_EOL;
    }

    /**
     * 连接PHP服务节点
     * @param  string $node
     * @param  object $client
     * @return object|bool
     */
    public function connPhpServNode($node, &$client = null)
    {
        $client = $client ?: (new Swoole\Coroutine\Client());
        list($host, $port) = @explode(':', $node);
        if (!$client->connect($host, $port)) {
            echo "PhpServNode connect fail, host: {$node}" . PHP_EOL;
            return false;
        }
        return true;
    }
}
