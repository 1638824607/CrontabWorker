<?php

require __DIR__ . '/../vendor/autoload.php';

ini_set("display_errors", "On");

use \CrontabWorker\CrontabWorker;

$crontabWorker = new CrontabWorker();
$crontabWorker->output = "/test.log";

//开启10个进程预备运行任务
$crontabWorker->proccessNum = 10;

for ($i=0; $i < 1000; $i++) { 
	//指定每秒运行一次
	$crontabWorker->addInterval('任务1', 's@1', function(){
		echo 111,"\n";
	});
}

$crontabWorker->run();