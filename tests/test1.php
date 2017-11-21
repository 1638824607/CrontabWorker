<?php

require __DIR__ . '/../vendor/autoload.php';

ini_set("display_errors", "On");

use \CrontabWorker\CrontabWorker;

$crontabWorker = new CrontabWorker();
$crontabWorker->output = "./test.log";
//开启10个进程预备运行任务
$crontabWorker->proccessNum = 5;

//指定每秒运行一次
$crontabWorker->addInterval('任务1', 's@1', function(){
	echo 111,"\n";
});

//指定每秒运行一次
$crontabWorker->addInterval('任务2', 's@2', function(){
	echo 222,"\n";
});

// 指定每分钟运行一次
$crontabWorker->addInterval('任务3', 'i@1', function(){
	echo 333,"\n";
});

// 指定每小时运行一次
$crontabWorker->addInterval('任务4', 'h@1', function(){
	echo 444,"\n";
});

// 指定每天的时间点运行
$crontabWorker->addInterval('任务5', 'at@00:00', function(){
	echo 555,"\n";
});

$crontabWorker->run();