<?php

/**
 * 创建虚拟机
 * 加入集群
 */

$data = array();
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
    echo $result . "\n";
    exec($result, $output);
    return $output;
}

/**
 * 获取虚拟机列表
 *
 * @return array
 */
function getVirturlList()
{
    global $data;
    if (!$data) {
        $processList = execCommand("ls");
        //加工processList
        unset($processList[0]);
        //转换数据
        foreach ((array)$processList as $v) {
            if ($v) {
                //匹配name
                $nameResult = preg_match("/^(\w+)/is", $v, $matchName);
                //匹配状态
                $stateResult = preg_match("/(Running|Stopped)/is", $v, $matchState);
                //匹配地址
                $ipResult = preg_match("/tcp\:\/\/(\d+\.\d+\.\d+\.\d+)/is", $v, $matchIp);
                //组装数据
                if ($nameResult && $stateResult) {
                    $data[$matchName[1]] = array('name' => $matchName[1], 'state' => $matchState[1], 'ip' => $ipResult ? $matchIp[1] : "");
                }
            }
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
    $data = getVirturlList();
    //判断是否存在
    if (array_key_exists($project, $data)) {
        //判断是否启动
        if ($data[$project]['state'] == "Stopped") {
            execCommand("start", $project);
        };
        if ($data[$project]['state'] == "Error") {
            execCommand("rm", $project);
            execCommand("create --driver virtualbox", $project);
        };
    } else {
        execCommand("create --driver virtualbox", $project);
    }
}

/**
 * 添加Note到集群
 *
 * @param $project
 * return mix
 */
function addNoteToSwarm($project)
{
    $data = getVirturlList();
    echo "============================" . $project . "============================\n";
    //判断是否存在
    if (array_key_exists($project, $data) && $data[$project]['state'] == "Running") {
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
        while (true) {
            $output = execCommand("ssh", $project, "'ps -ef|grep docker'");
            foreach ($output as $v) {
                if (preg_match("/\/usr\/local\/bin\/docker \-d/is", $v)) {
                    break 2;
                }
            }
            sleep(1);
        }
        //集群管理工具
        if ($project == "shipyard") {
            //安装docker-compose
            execCommand("ssh", $project, "'sudo cp -f " . dirname(__FILE__) . "/shipyard/docker-compose /usr/local/bin/'");
            //复制docker-compose.yml
            execCommand("ssh", $project, "'sudo cp -f " . dirname(__FILE__) . "/shipyard/docker-compose.yml /home/docker/'");
            //替换shipyard etc server地址
            execCommand("ssh", $project, '\'sudo sed -i -e"s/google/' . $data['shipyard']['ip'] . '/" /home/docker/docker-compose.yml\'');
            //启动shipyard
            execCommand("ssh", $project, "'cd /home/docker && docker-compose up -d'");
            //验证controller是否启动
            while (true) {
                //判断docker_shipyard_controller是否启动
                $output = execCommand("ssh", $project, "'docker inspect docker_shipyard-controller_1'");
                $result = @json_decode(implode("\n", $output), true);
                if (!$result) {
                    echo "检索不到docker_shipyard-controller运行的相关容器\n";
                    exit;
                }
                //如果发现没有启动则需要重新启动
                if ($result[0]['State']['Running']) {
                    echo "++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++\n";
                    $result = execCommand("ssh", $project, "'sudo docker logs --tail 1 docker_shipyard-controller_1'");
                    //打印日志
                    echo implode("\n", $result) . "\n";
                    echo "http://" . $data[$project]['ip'] . ":8080\n";
                    echo "账号：admin \n";
                    echo "密码：shipyard \n";
                    break;
                } else {
                    execCommand("ssh", $project, "'docker start docker_shipyard-controller_1'");
                }
                sleep(1);
            }
        } else {
            //获取当前启动的容器列表
            $containers = execCommand("ssh", $project, "'docker ps -a'");
            unset($containers[0]);
            //判断shipyard-swarm-agent是否存在
            $output = execCommand("ssh", $project, "'docker inspect shipyard-swarm-agent'");
            $result = @json_decode(implode("\n", $output), true);
            if ($result) {
                //判断该节点加入发现服务Ip是否正确
                if (stristr($result[0]['Args'][3], $data['shipyard']['ip'])) {
                    //判断该节点的运行状态
                    if ($result[0]['State']['Running']) {
                        return true;
                    } else {
                        return execCommand("ssh", $project, "'docker start shipyard-swarm-agent'");
                    }
                } else {
                    //强制删除
                    execCommand("ssh", $project, "'docker rm -f shipyard-swarm-agent'");
                }
            }
            //重新启动
            $command = '\'docker run -d --restart=always --name shipyard-swarm-agent 192.168.1.254:5000/swarm join --addr ' . $data[$project]["ip"] . ':2376 etcd://' . $data['shipyard']['ip'] . ':4001\'';
            execCommand("ssh", $project, $command);
        }
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
//先启动shipyard
upVirtural("shipyard");
addNoteToSwarm("shipyard");
//启动
if ($argv[1] != "all") {
    upVirtural($argv[1]);
    addNoteToSwarm($argv[1]);
} else {
    foreach (array("project1", "project2") as $v) {
        upVirtural($v);
        addNoteToSwarm($v);
    }
}
?>
