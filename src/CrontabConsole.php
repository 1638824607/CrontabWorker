<?php

namespace CrontabWorker;

/**
 * 控制台输出
 */
class CrontabConsole {

	/**
	 * 欢迎~~
	 */
	public static function welcome()
	{
	}
	/**
	 * 使用说明
	 * @return [type] [description]
	 */
	public static function usage()
	{
		$self = ($_SERVER['PHP_SELF']);
		echo "用法:\n";
		echo "  php $self start       启动\n";
		echo "  php $self -d  start   守护进程化启动\n";
		echo "  php $self -d  restart 守护进程化重启\n";
		echo "  php $self stop        停止任务\n";
		echo "  php $self status      查看任务运行状态\n";
	}


}