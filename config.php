<?php

/**
 * php-docker-machine
 * 项目配置信息
 *
 * @user 刘松森 <liusongsen@gmail.com>
 * @date 16/3/12
 */

# dev配置
define('CODE_DIR', dirname(__FILE__) . '/code/'); //本地code目录,必须是绝对路径
define('NGINX_SERVERS_DIR', dirname(__FILE__) . '/server/nginx'); //本地nginx servers路径
# 端口配置
define('VIR_PORT', 80); //虚拟主机映射端口
define('CONTAINER_PORT', 80); //容器映射端口
# 失败后重试重启次数
define('VIR_RESTART_RETRY_TIMES', 5); //虚拟机重启重试次数
define('DOCKER_RESTART_RETRY_TIMES', 10); //Docker重启重试次数
define('SHIPYARD_RESTART_RETRY_TIMES', 10); //Shipyard重启重试次数
