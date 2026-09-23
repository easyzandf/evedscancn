# 站点目录被多个 vhost 共用 —— dscan-dpdns / evedscancn / tradecross.com（它的 :80 块
# 是全站 default_server，对所有没匹配上的 Host 生效）的 root 全指向
# /www/sites/tradecross.com/index —— 所以这几条拦截只能写一份共享片段，
# 由每个 vhost include 进来。
#
# 为什么不能各写一份：已经翻过一次车。第一次只加固了自己那两个域名，旧站域名和
# 另一个 vhost 依然能把 data/auth_system.db（旧站 SQLite 授权库，61KB）整个下载走。
# 以后再添 vhost，记得把这一行也带上：
#     include /usr/local/openresty/nginx/conf/conf.d/site-protect.inc;
#
# 后缀必须是 .inc，不能改成 .conf：nginx.conf 里有 include conf.d/*.conf，
# 改名后这个片段会被当成顶层配置再加载一次，而 location 不允许出现在 http 层，
# 结果是 openresty -t 直接 emerg、整个服务器的站点全部起不来。
# 顺带说明为什么不放 nginx/conf/snippets/：容器只挂了 conf.d 这个目录和几个单文件，
# 别的路径写进去只存在于容器可写层，容器重建就没了。
#
# 顺序有讲究：nginx 的正则 location 是「首个匹配生效」。

# 隐藏文件（.git / .env / .htaccess 之类）。必须排在下面那些正则之前，否则
# /js/.foo.js 会先命中普通静态文件规则，被当成正常文件发出去。
location ~ /\. {
    deny all;
    access_log off;
    log_not_found off;
}

# 备份 / 源码 / 本地库 / 日志 / 配置。上面那条 \.php$ 是正则，只认 .php 结尾，
# index.php.bak 匹配不上，于是被当纯文本原样吐出（实测泄露 3.5KB 源码）；
# data/auth_system.db、kb_data/kb.db、raid_data/raid.db 同理，都能直接下载。
location ~* \.(bak|old|orig|save|swp|swo|sql|log|ini|conf|dist|db|sqlite|sqlite3|db3)$ {
    deny all;
}

# phpinfo 靠扩展名挡不住（它本身就是个 .php），但公开的 phpinfo 会吐出 PHP 版本、
# 配置路径、环境变量，本站用不到，直接关掉。
location = /phpinfo.php {
    deny all;
}

# 服务端数据目录：SQLite 库和日志只由 PHP 从文件系统读写，浏览器永远不该直接取。
# 按目录拦而不是只按扩展名，是为了把 .db-wal / .db-shm / 以后新增的文件一并挡掉。
# （data/ 里是旧站的授权库 licenses / app_data，只被 config.php 在服务端引用。）
location ^~ /data/      { deny all; }
location ^~ /kb_data/   { deny all; }
location ^~ /raid_data/ { deny all; }
