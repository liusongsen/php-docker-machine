## PHP-DOCKER-MACHINE 说明

### 目录结构

```
fw-compose-local
|-- README.md             # 开发说明文档
|-- docker-machine.php    # php-docker-machine.php脚本
|-- shipyard              # shipyard服务
|-- server                # 服务端容器server配置信息
|-- logs                  # 运行启动日志
|-- code                  # 本地开发环境代码
|-- config.php            # 项目配置信息（可更改）
|--
```

### 使用说明

关于配置信息说明(config.php)。

> 确定本机代码目录（如：/Users/liusongsen/Project/web/,要求绝对路径）;
> 确定WEB项目对应的project.conf路径（可将该文件复制到server/nginx目录里面）

1.克隆项目文件：

```
git clone http://gitcafe.jingzhuan.cn/JZTECH-WEB/fw-compose-local.git

```

2.修改项目配置:

```

define('CODE_DIR', "your project path"); //本地code目录,必须是绝对路径
define('NGINX_SERVERS_DIR', dirname(__FILE__) . 'server/nginx'); //本地nginx servers路径
define('VIR_PORT', 80); //虚拟主机映射端口
define('CONTAINER_PORT', 80); //容器映射端口

```

3.运行php-docker-machine:

```
php docker-machine.php project
```

4.访问shipyard,看终端输出

```
浏览器打开：http://192.168.99.100:8080
输入账号：admin
输入密码：shipyard
```

5.修改本机host,将对应的域名改为虚拟机即可

```
192.168.99.101  www.yidejia.com
如何查看虚拟机的IP,进入shipyard后，点击"Node"即可查看
```
