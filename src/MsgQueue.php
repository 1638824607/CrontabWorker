<?php
namespace CrontabWorker;


class MsgQueue {

	public $key = null;
	public $length = 1024; //队列长度

	public $queue = null;


	public function __construct($key, $mode=0666)
	{
		$this->key = $key;
		$this->queue = \msg_get_queue($key,$mode);
		if (!$this->queue) {
			throw new \Exception("$key faild!", 1);
		}
	}

	/**
	 * 创建队列
	 * @param  [type]  $key  [description]
	 * @param  integer $mode [description]
	 * @return [type]        [description]
	 */
	static public function create($key,$mode=0666) 
	{
		return new self($key,$mode);
	}

	/**
	 * 入列
	 * @param  [type] $msg 消息
	 * @param  [type] $unserialize 数据是否序列化
	 */
	public function push($msg, $unserialize = false)
	{
		$stat = \msg_stat_queue($this->queue);
		if ($stat['msg_qnum'] >= $this->length ) {
			throw new \Exception("error: msg_queue: {$this->key} full!", 1);
		}
		return \msg_send($this->queue, 1, $msg, $unserialize, false);
	}

	/**
	 * 出队,默认阻塞
	 * @param  $wait 是否阻塞,默认为阻塞
	 */
	public function pop($wait=true)
	{
		$message_type = 0;
		if ($wait) {
			\msg_receive($this->queue, 0, $message_type, 1024, $message, false); //阻塞
		} else { //非阻塞
			\msg_receive($this->queue, 0, $message_type, 1024, $message, false, MSG_IPC_NOWAIT);
		}
		if (!$message) {
			return false;
		}
		return $message;
	}

	public function status()
	{
		$stat = \msg_stat_queue($this->queue);
		return $stat;
	}

	public function clear()
	{
		\msg_remove_queue($this->queue);
	}
	
}
