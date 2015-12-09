# php-docker-machine
php版本的docker-machine
# 集成shipyard
启动的时候自动启动一个虚拟机跑shipyard
新开启的项目会自动作为一个节点加入shipyard
# 优点
1: 解决了虚拟机Ip动态变化的麻烦
2: 自动维护虚拟机运行状态
3: 自动维护shipyard服务运行状态
4: 对于php-docker-machine而言，它只要做一件事，就是把新开的虚拟机docker加入shipyard即可，
   后面的容器部署和管理都通过shipyard
5: 省去了你很多时间，你不用去关注一个shipyard是如何部署的
# install
php docker-machine.php framework

