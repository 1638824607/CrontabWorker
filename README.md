# CrontabWorker

此工具是一个php定时任务管理工具,支持大量PHP定时脚本,采用预派生进程模型,父进程负责计算定时任务间隔逻辑向消息队列写入任务,子进程负责处理消息队列任务,当处理任务进程数不够会自动扩容,保证定时任务的正确性


## 概述

曾几何时,我使用linux下crontab工具来做定时任务,起初还好,当我往crontab加越来越多的任务时,我开始怕了,那么一大坨类似`**** php /path/xx.php`的东西一点都不亲切,即使加上每个加上注释我都还是觉得可怕,于是乎就琢磨出用php整一个定时任务管理用具,要求不高,就是要用起来,运行起来管理起来感觉很酷。



## 环境要求

1. liunx
2. pcntl扩展开启
3. php 5.3以上
4. composer

## 安装

```
composer require godv/crontab-worker
```

## 如何使用


核心方法 `CrontabWorker::addInterval($name, $command, $callable, $args)` 

参数1 $name 定时任务名称

参数2 $command 运行指令以key@value的形式表示
1. `s@n` 表示每n秒运行一次 
2. `i@n` 表示每n分钟运行一次 
3. `h@n` 表示每n小时运行一次
4. `at@nn:nn` 表示指定每天的nn:nn执行 例如每天凌晨 at@00:00

参数3 $callable 回调函数,也就是定时任务业务逻辑

参数4 $args 回调函数传递的参数


*代码示例*

> 记得先运行 `composer install` 哦!

> test1.php

``` php

use \CrontabWorker\CrontabWorker;

$crontabWorker = new CrontabWorker();
$crontabWorker->output = "./test.log";
//开启5个进程预备运行任务
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
```

*如何查看使用说明*

> php test1.php -h

*输入上面的命令会出现命令提示*
```
用法:
  php tests/test1.php start       启动
  php tests/test1.php -d  start   守护进程化启动
  php tests/test1.php -d  restart 守护进程化重启
  php tests/test1.php stop        停止任务
  php tests/test1.php status      查看任务运行状态
```

*如何运行*

`-d` 表示已守护进程方式运行

> php test1.php -d start

*如何停止*

> php test1.php stop

*如何重启*

> php test1.php restart

*如何查看服务状态* 

> 觉得这控制台输出不够酷,不够好请评论留言,谢谢！

> php test1.php status

```
======================================================================
PPID: 19272
RUN_TIME: 2017-11-18 19:06
PROCCESS_TITLE: php-crontab
PROCCESS: 5
======================================================================
NAME           COMMAND   COUNT     LAST_TIME           NEXT_TIME      
任务1          s@1       4         11-18 19:06:34      11-18 19:06:35 
任务2          s@2       2         11-18 19:06:34      11-18 19:06:36 
任务3          i@1       0         none                11-18 19:07:30 
任务4          h@1       0         none                11-18 20:06:30 
任务5          at@00:00  0         none                11-19 00:00:00 
======================================================================

```
> ps -ef |grep php-crontab

```
root     19294     1  0 19:07 ?        00:00:00 php-crontab-master
root     19295 19294  0 19:07 ?        00:00:00 php-crontab-child
root     19296 19294  0 19:07 ?        00:00:00 php-crontab-child
root     19297 19294  0 19:07 ?        00:00:00 php-crontab-child
root     19298 19294  0 19:07 ?        00:00:00 php-crontab-child
root     19299 19294  0 19:07 ?        00:00:00 php-crontab-child
```