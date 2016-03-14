<?php

/**
 * php-docker-machine
 * 一健集成管理shipyard+docker
 */
error_reporting(E_ALL ^ E_NOTICE ^ E_DEPRECATED ^ E_WARNING);
include "config.php";
/**
 * 执行docker命令
 *
 * @param $command
 * @param string $env
 * @param string $exec_command
 * @return array
 */
function execCommand($command, $env = "", $exec_command = "")
{
    $output = array();
    $result = "docker-machine " . $command . " " . $env . " " . $exec_command;
    exec($result, $output);
    //写日志
    $log = "logs/log_" . date("Y-m-d") . ".log";
    if (!file_exists($log)) {
        touch($log);
    }
    if (is_writable($log)) {
        @file_put_contents($log, sprintf("%s=%s=%s", date("Y-m-d H:i:s"), $env, $result) . "\n", FILE_APPEND);
        @file_put_contents($log, sprintf("%s=%s=%s", date("Y-m-d H:i:s"), $env, implode('\n', $result)) . "\n", FILE_APPEND);
    } else {
        echo $log . "日志文件没有写入权限\n";
    }
    return $output;
}

/**
 * 获取虚拟机列表
 *
 * @return array
 */
function getVirturlList()
{
    $data = array();
    $processList = execCommand("ls");
    //转换数据
    foreach ((array)$processList as $v) {
        if ($v && preg_match("/^(\w+).+?(Running|Stopped|Error|Timeout)(.+?(\d{3}\.\d+\.\d+\.\d+).+?)?/is", $v, $matches)) {
            $data[$matches[1]] = array('name' => $matches[1], 'state' => $matches[2], 'ip' => isset($matches[4]) ? $matches[4] : "");
        }
    }
    return $data;
}

/**
 * 重启虚拟机
 *
 * @param $project
 * @return mix
 */
function upVirtural($project)
{
    echo "启动虚拟机: " . $project . "\n";
    //获取虚拟主机列表
    $data = getVirturlList();
    //判断是否启动
    if ($data[$project]['state'] == "Running") {
        return true;
    } elseif ($data[$project]['state'] == "Stopped") {
        execCommand("start", $project);
    } else {
        //for Error Timeout
        array_key_exists($project, $data) && execCommand("rm", $project);
        execCommand("create --driver virtualbox", $project);
    };
    //验证virtural是否启动完成
    $i = 0;
    while (true) {
        $output = execCommand("ls | grep " . $project);
        foreach ($output as $v) {
            if (preg_match("/" . $project . "/is", $v)) {
                break 2;
            }
        }
        //验证重试次数
        if ($i > VIR_RESTART_RETRY_TIMES) {
            echo $project . " 虚拟机启动失败(" . ($i + 1) . ")\n";
            break;
        }
        $i++;
        sleep(1);
    }
}

/**
 * 启动docker
 * 容器管理工具
 *
 * @param string $project 项目名称
 * @return mixed
 */
function docker($project)
{
    echo "启动虚拟机里面的docker服务: " . $project . "\n";
    //修改docker默认启动参数-屏蔽tls
    $output = execCommand("ssh", $project, "'cat /var/lib/boot2docker/profile'");
    if (preg_match("/DOCKER_TLS\=auto/is", implode("\n", $output))) {
        execCommand("ssh", $project, '\'sudo sed -i -e"s/DOCKER_TLS=auto/DOCKER_TLS=no/" /var/lib/boot2docker/profile\'');
    }
    //修改docker默认启动参数-添加本地镜像仓库地址
    if (!preg_match("/\-\-insecure\-registry/is", implode("\n", $output))) {
        execCommand("ssh", $project, '\'sudo sed -i -e"4i--insecure-registry 192.168.1.254:5000" /var/lib/boot2docker/profile\'');
    }
    //重启docker
    if (preg_match("/DOCKER_TLS\=auto/is", implode("\n", $output)) || !preg_match("/\-\-insecure\-registry/is", implode("\n", $output))) {
        execCommand("ssh", $project, "'sudo pkill docker'");
        execCommand("ssh", $project, "' sudo /etc/init.d/docker  start'");
    }
    //验证docker是否启动完成
    $i = 0;
    while (true) {
        $output = execCommand("ssh", $project, "'ps -ef|grep docker'");
        foreach ($output as $v) {
            if (preg_match("/\/usr\/local\/bin\/docker/is", $v)) {
                break 2;
            }
        }
        //验证重试次数
        if ($i > DOCKER_RESTART_RETRY_TIMES) {
            echo $project . " docker启动失败(" . ($i + 1) . ")\n";
            break;
        }
        $i++;
        sleep(1);
    }
}

/**
 * 启动shipyard
 * 集群管理工具
 *
 * @return mixed
 */
function shipyard()
{
    echo "启动shipyard对应的docker-compose.yml\n";
    $project = "shipyard";
    //获取虚拟主机列表
    $data = getVirturlList();
    //安装docker-compose
    execCommand("ssh", $project, "'sudo cp -f " . dirname(__FILE__) . "/shipyard/docker-compose /usr/local/bin/'");
    //复制docker-compose.yml
    execCommand("ssh", $project, "'sudo cp -f " . dirname(__FILE__) . "/shipyard/docker-compose.yml /home/docker/'");
    //替换shipyard etc server地址
    execCommand("ssh", $project, '\'sudo sed -i -e"s/google/' . $data['shipyard']['ip'] . '/" /home/docker/docker-compose.yml\'');
    //启动shipyard
    execCommand("ssh", $project, "'cd /home/docker && docker-compose up -d'");
    //验证controller是否启动
    $i = 0;
    while (true) {
        //判断docker_shipyard_controller是否启动
        $output = execCommand("ssh", $project, "'docker inspect docker_shipyard-controller_1'");
        if (!$result = @json_decode(implode("\n", $output), true)) {
            echo "检索不到docker_shipyard-controller运行的相关容器\n";
            exit;
        }
        //如果发现没有启动则需要重新启动
        if ($result[0]['State']['Running']) {
            //查看日志
            execCommand("ssh", $project, "'sudo docker logs --tail 1 docker_shipyard-controller_1'");
            //打印日志
            echo "http://" . $data[$project]['ip'] . ":8080\n";
            echo "账号：admin \n";
            echo "密码：shipyard \n";
            break;
        } else {
            execCommand("ssh", $project, "'docker start docker_shipyard-controller_1'");
        }
        //验证重试次数
        if ($i > SHIPYARD_RESTART_RETRY_TIMES) {
            echo 'shipyard启动失败(' . ($i + 1) . ')\n';
            break;
        }
        $i++;
        sleep(1);
    }
}

/**
 * 添加节点到集群
 *
 * @param string $project 项目名称
 * return mix
 */
function addNodeToSwarm($project)
{
    echo "添加" . $project . "到shipyard swarm集群组\n";
    //获取虚拟主机列表
    $data = getVirturlList();
    //获取当前启动的容器列表
    if (execCommand("ssh", $project, "'docker ps -a | grep shipyard-swarm-agent'")) {
        //获取容器启动参数信息
        $output = execCommand("ssh", $project, "'docker inspect shipyard-swarm-agent'");
        $result = @json_decode(implode("\n", $output), true);
        //判断该节点加入发现服务Ip是否正确＋虚拟IP是否发生变化
        if ($result && stristr($result[0]['Args'][3], $data['shipyard']['ip']) && stristr($result[0]['Args'][2], $data[$project]['ip'])) {
            //判断该节点的运行状态
            if ($result[0]['State']['Running']) {
                return true;
            } else {
                //重新启动
                execCommand("ssh", $project, "'docker start shipyard-swarm-agent'");
            }
        } else {
            //删除
            execCommand("ssh", $project, "'docker rm -f shipyard-swarm-agent'");
        }
    }
    //重新启动
    $command = '\'docker run -d --restart=always --name shipyard-swarm-agent 192.168.1.254:5000/swarm join --addr ' . $data[$project]["ip"] . ':2376 etcd://' . $data['shipyard']['ip'] . ':4001\'';
    execCommand("ssh", $project, $command);
    //循环验证服务是否正确启动
    $i = 0;
    while (true) {
        //判断grep shipyard-swarm-agent是否启动
        $output = execCommand("ssh", $project, "'docker inspect grep shipyard-swarm-agent'");
        if (!$result = @json_decode(implode("\n", $output), true)) {
            echo "检索不到grep shipyard-swarm-agent运行的相关容器\n";
            exit;
        }
        if ($result[0]['State']['Running']) {
            break;
        } else {
            execCommand("ssh", $project, "'docker start shipyard-swarm-agent'");
        }
        //验证重试次数
        if ($i > SHIPYARD_RESTART_RETRY_TIMES) {
            echo "shipyard-swarm-agent启动失败(" . ($i + 1) . ")\n";
            break;
        }
        $i++;
        sleep(1);
    }
}

/**
 * 部署webserver
 *
 * @param string $project 项目
 * @return mixed
 */
function deployWebServer($project)
{
    if (execCommand("ssh", $project, "'docker ps -a | grep webserver'")) {
        execCommand("ssh", $project, "'docker restart webserver'");
    } else {
        //组装命令
        $command = "'docker run -d --restart=always ";
        $volumns[] = CODE_DIR . ":/home/www";
        $volumns[] = NGINX_SERVERS_DIR . ":/etc/nginx/conf.d";
        $command .= " -v " . implode(" -v ", $volumns);
        $command .= " -p " . VIR_PORT . ":" . CONTAINER_PORT . " --name webserver 192.168.1.254:5000/library/webserver:devel '";
        //启动webserver
        execCommand("ssh", $project, $command);
    }
}

/**
 * 启动项目
 *
 * @param string $project 项目名称
 * @return mixed
 */
function startProject($project)
{
    //启动虚拟机
    upVirtural($project);
    //启动docker
    docker($project);
    //部署集群服务
    if ($project == "shipyard") {
        shipyard();
    } else {
        addNodeToSwarm($project);
        deployWebServer($project);
    }
}

//命令行获取要安装的模块配置
if ($argc < 2) {
    echo "请输入你要生成的虚拟机项目名称\n命令格式: php docker-machine.php project1|project2\n";
    exit;
}
if (!in_array($argv[1], array("project1", "project2"))) {
    echo "请检查输入的虚拟机项目名称是否正确";
    exit;
}
//路径验证
if (!is_dir(CODE_DIR)) {
    echo "亲！请检查配置文件中的code目录配置是否正确\n";
    exit;
}
if (!is_dir(NGINX_SERVERS_DIR)) {
    echo "亲！请检查配置文件中的nginx server目录配置是否正确\n";
    exit;
}
if (!preg_match("/\d{2,4}/is", VIR_PORT) || !preg_match("/\d{2,4}/", CONTAINER_PORT)) {
    echo "亲！请检查配置文件中的映射端口配置是否正确\n";
    exit;
}
//启动
startProject("shipyard");
startProject($argv[1]);
?>
