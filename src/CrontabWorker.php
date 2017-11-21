<?php

namespace CrontabWorker;


use \CrontabWorker\MsgQueue;
use \SuperClosure\Serializer;

class CrontabWorker {

    private $_running = true;

    public $timezone = "Asia/Shanghai";
    // 进程
    static public $workers = null;

    // 任务
    static public $tasks = array();

    // 定时任务,消息队列
    static public $queue = null;

    // 用于序列化函数的对象
    static public $serializer = null;

    // config目录下的所有配置
    static public $config = array();

    // 主进程ID,用于任务队列的KEY
    private $pidFile;

    // 记录任务状态的文件
    private $taskStatusFile;

    //进程名称
    public $title = 'php-crontab';

    public $output = '/dev/null';

    // 启用worker数
    public $proccessNum = 5;

    public $daemon = false;

    // worker进程运行多少次自动回收
    public $proccessRunNum = 10000;
    // 临时进程运行多少次自动回收
    public $tempProccessRunNum = 10;

    public function __construct()
    {
        date_default_timezone_set($this->timezone);

        $this->pidFile = sys_get_temp_dir() . '/' . basename($_SERVER['PHP_SELF']) . '.pid';

        $this->taskStatusFile = sys_get_temp_dir() . '/' . basename($_SERVER['PHP_SELF']) . '.task';

        self::$serializer = new Serializer();

    }
    

    public function run()
    {
        $this->checkEnv();

        // 接收命令
        if (false === $this->runCommand()) {
            return ;
        }

        if (file_exists($this->pidFile)) {
            die("error: php {$_SERVER['PHP_SELF']} is Running!\n");
        }
        
        if ($this->daemon) {
            $this->daemonize();
        }

        $this->createPidFile();
        // CrontabConsole::welcome();
        $this->resetStd();
        $this->installSignalHandler();

        @cli_set_process_title($this->title . '-' . 'master');
        
        self::$queue = MsgQueue::create(ftok($this->pidFile, 'a'));

        pcntl_alarm(1);
        while ($this->_running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            sleep(1);
        }
        $this->quit();
    }

    /**
     * 检查环境
     */
    public function checkEnv()
    {
        // 只允许在cli下面运行
        if (php_sapi_name() != "cli"){
            die("only run in command line mode\n");
        }
        if (version_compare("5.3", PHP_VERSION, ">")) {
            die("PHP版本至少要大于5.3\n");
        }
        if (!function_exists('pcntl_fork')) {
            die('需要安装PHP的pcntl扩展\n');
        }
        if (!function_exists('pcntl_signal_dispatch')) {
            declare(ticks = 1);
        }
        
    }

    /**
     * 接收命令行参数
     */
    public function runCommand()
    {

        $args = \CommandLine::parseArgs($_SERVER['argv']);

        if (empty($args)) {
            return true;
        }
        // -h
        if (@$args['h']) {
            CrontabConsole::usage();
            return false;
        }

        

        // -d start ||start
        if (@$args['d'] == 'start' || in_array('start', $args, true)) {
            if (@$args['d'] == 'start') {
                $this->daemon = true;
            }
            return true;
        }

        // stop
        if (in_array('stop', $args, true)) {
            if (file_exists($this->pidFile)) {
                $pid = file_get_contents($this->pidFile);
                posix_kill($pid, SIGTERM);
            }
            $this->quit();
            return false;
        }
        // status
        if (in_array('status', $args, true)) {
            $this->status();
            return false;
        }

        // -d restart || restart
        if (@$args['d'] == 'restart' || in_array('restart', $args, true)) {
            if (file_exists($this->pidFile)) {
                $pid = file_get_contents($this->pidFile);
                posix_kill($pid, SIGTERM);
            }
            while (file_exists($this->pidFile)) {
            }
            $this->quit(false);
            if (@$args['d'] == 'restart') {
                $this->daemon = true;
            }
            return true;
        }

        CrontabConsole::usage();

        return false;
       
    }

    /**
     * 守护进程化
     */
    public function daemonize()
    {
        
        
        umask(0);

        $pid = pcntl_fork();
        if (-1 === $pid) {
            die('fork fail');
        } elseif ($pid > 0) {
            exit(0);
        }
        
        if (-1 === posix_setsid()) {
            die("setsid fail");
        }

        $pid = pcntl_fork();
        if (-1 === $pid) {
            die("fork fail");
        } elseif (0 !== $pid) {
            exit(0);
        }

    }
    /**
     * 重定向输出
     * @return [type] [description]
     */
    public function resetStd()
    {
        global $stdin, $stdout, $stderr;
        //关闭打开的文件描述符
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);


        if (!file_exists($this->output)) {
            touch($this->output);
            chmod($this->output, 0755);
        }

        $stdin  = fopen($this->output, 'r');
        $stdout = fopen($this->output, 'a');
        $stderr = fopen($this->output, 'a');

    }

    /**
     * 安装信号
     */
    public function installSignalHandler()
    {
        pcntl_signal(SIGTERM, array($this, "signalHandler"),false); 
        pcntl_signal(SIGQUIT, array($this, "signalHandler"),false); 
        pcntl_signal(SIGCHLD, array($this, "signalHandler"),false); 
        pcntl_signal(SIGINT, array($this, "signalHandler"),false); 
        pcntl_signal(SIGALRM, array($this, 'signalHandler'));
    }

    /**
     * 处理信号
     */
    public function signalHandler($signo){
        switch($signo){
            case SIGALRM:
                if ($this->_running) {

                    pcntl_alarm(1);

                    while ($this->proccessNum > count(self::$workers)) {
                        $this->execTask($this->proccessRunNum, $this->title . '-' . 'child');
                    }

                    $status = self::$queue->status();
                    // 分发任务
                    $this->dispatchTask();

                    // 进程不够用的话,扩容进程
                    $this->autoProccess();
                }
                
                break;
            //子进程结束信号
            case SIGCHLD:
                while(($pid=pcntl_waitpid(-1, $status, WNOHANG)) > 0){ 
                    unset(self::$workers[$pid]);
                }
                break;
            //中断进程
            case SIGTERM:
            case SIGINT:
            case SIGQUIT:
                $this->_running = false;
                break;
            default:
                return false;
        }
    }

    /**
     * 数据清理
     */
    public function quit($exit=true)
    {

        if (file_exists($this->pidFile)) {
            echo "unlink {$this->pidFile} [OK]\n";
            @unlink($this->pidFile);
        }

        if (file_exists($this->taskStatusFile)) {
            echo "unlink {$this->taskStatusFile} [OK]\n";
            @unlink($this->taskStatusFile);
        }


        if (!is_null(self::$queue)) {
            echo "clear Queue [OK]\n";
            self::$queue->clear();
        }

        $exit && posix_kill(0, SIGKILL);
    }

    /**
     * 向控制台输出任务状态
     */
    public function status()
    {
        if (!file_exists($this->pidFile)) {
            die("任务未运行\n");
        }


        $proccessInfo = str_pad('=', 70,'=') . "\n";
        $proccessInfo .= "PPID: ".file_get_contents($this->pidFile)."\n";
        $proccessInfo .= "RUN_TIME: " .date('Y-m-d H:i',filemtime($this->pidFile))."\n";
        $proccessInfo .= "PROCCESS_TITLE: ".$this->title."\n";
        $proccessInfo .= "PROCCESS: " .$this->proccessNum."\n";
        $proccessInfo .= str_pad('=', 70,'=') . "\n";

        $status = json_decode(file_get_contents($this->taskStatusFile),true);

        $crontabTitle = str_pad('NAME', 15) . str_pad('COMMAND', 10) . str_pad('COUNT', 10) . str_pad('LAST_TIME', 20) . str_pad('NEXT_TIME', 15) . "\n";

        $body = '';
        foreach ($status as $v) {
            $chineseStr = preg_replace('/[^\x{4e00}-\x{9fa5}]/u', '', $v['name']);

            $v['lastTime'] = isset($v['lastTime']) ? date('m-d H:i:s',$v['lastTime']) : 'none';

            $body .= (str_pad($v['name'], 15 + mb_strlen($chineseStr))
                                . str_pad($v['command'], 10)
                                . str_pad($v['count'], 10)
                                . str_pad($v['lastTime'], 20)
                                . str_pad(date('m-d H:i:s',$v['nextTime']), 15))
                                . "\n";
        }

        $body .= str_pad('=', 70,'=') . "\n\n";



        echo $proccessInfo . $crontabTitle . $body;
    }

    /**
     * 创建进程pid文件,用于确定任务运行状态
     */
    private function createPidFile()
    {
        if (!is_writable(dirname($this->pidFile))) {
            die("无创建文件权限 " . $this->pidFile . "\n");
        }
        file_put_contents($this->pidFile, getmypid());
        echo "create {$this->pidFile} [OK].\n";
    }
   
    /**
     * 添加定时任务
     * @param $name 任务名称
     * @param $tags 指令[at:指定每天运行时间|h:每h小时|i:每i分钟|s:每s秒]
     * @param $timer 
     * @param $settings
     */
    public function addInterval($name, $command, $callable, $args=array())
    {
        $exp_command = strtolower($command);
        list($tag, $timer) = explode('@', $exp_command);
        if (!in_array($tag, array('at','s','i','h'))) {
            return false;
        }


        if (!is_callable($callable)) {
            throw new Exception("addInterval: arguments 3 \$callable not callable", 1);
        }

        $task['name'] = $name;
        $task['count'] = 0;
        $task['args'] = $args;
        $task['tag'] = $tag;
        $task['timer'] = $timer;
        $task['command'] = $command;
        $task['callable'] = self::$serializer->serialize($callable);

        self::$tasks[] = $task;
    }

    /**
     * 解析设定,计算下次运行的时间
     * @param  [type]  $tag 
     * @param  [type]  $timer 
     */
    private function calcNextTime($tag, $timer)
    {
        $nextTime = false;
        // 指定每天运行日期  格式 00:00
        if ($tag == 'at' && strlen($timer) == 5) {
            if (time() >= strtotime($timer)) {
                $nextTime = strtotime($timer . " +1day");
            } 
            else {
                $nextTime = strtotime($timer);
            }
        }

        $timer = intval($timer);
        // 按秒
        if ($tag == 's' && $timer > 0) {
            $nextTime = time() + $timer;
        }

        // 按分钟
        if ($tag == 'i' && $timer > 0) {
            $nextTime = time() + $timer * 60;
        }

        // 按小时
        if ($tag == 'h' && $timer > 0) {
            $nextTime = time() + $timer * 60 * 60;
        }

        return $nextTime;
    }

    /**
     * 创建子进程
     * @param  $runMax 进程处理任务次数
     * @param  $title  子进程名称
     */
    private function execTask($runMax, $title)
    {
        $pid = pcntl_fork();
        if ($pid > 0) {
            self::$workers[$pid] = $pid;
        } elseif ($pid == 0) {
            pcntl_signal(SIGTERM, SIG_DFL); 
            pcntl_signal(SIGCHLD, SIG_DFL);

            @cli_set_process_title($title);
            $i = 0;
            while ($runMax > $i) {
                if (($msg = self::$queue->pop()) !== false) {
                    $task = json_decode($msg, true);
                    call_user_func_array(self::$serializer->unserialize($task['callable']), $task['args']);
                    $i++;
                }
            }

            exit(0);
        }
    }

    /**
     * 自动扩容进程,默认处理一次就销毁
     */
    private function autoProccess()
    {
        // 自动扩容进程
        $status = self::$queue->status();
        while ($status['msg_qnum']--) {
            $this->execTask($this->tempProccessRunNum, $this->title . '-' . 'temp');
        }
    }

    /**
     * 解析任务发送到消息队列里,分发给子进程
     */
    private function dispatchTask()
    {
        foreach (self::$tasks as &$task) {

            if (empty($task['nextTime'])) {
                $task['nextTime'] = $this->calcNextTime($task['tag'], $task['timer']);
            }

            if (!empty($task['nextTime']) && time() >= $task['nextTime']) {
                @self::$queue->push(json_encode($task));
                $task['count']++;
                $task['lastTime'] = $task['nextTime'];
                $task['nextTime'] = $this->calcNextTime($task['tag'], $task['timer']);
            }

        }

        // 读取任务状态,写入文件
        file_put_contents($this->taskStatusFile, json_encode(self::$tasks));
    }

}
