# EVE 舰船中文 D-Scan

基于 [dscan.info](https://dscan.info/) 设计理念的 EVE Online 纯中文舰船识别工具。

## 线上地址

**https://www.evedscancn.cc.cd/**

## 功能

- **D-Scan 解析** — 粘贴 EVE 扫描结果，自动识别中/英文舰船名，按分类分组展示
- **ECM 建议** — 根据扫描到的舰船势力自动推荐对应 ECM 干扰类型（雷达/引力/磁力/光雷达/多谱式）
- **舰船浏览器** — 浏览全部 423 艘舰船的中文数据，支持搜索和分类筛选
- **分享链接** — 解析后生成短码链接（`#c=xxxxxx`），发送给队友即可看到相同结果
- **一键截图** — 将解析结果截图为 PNG 复制到剪贴板（需 HTTPS）
- **暗色/亮色主题** — 默认暗色，可切换

## 技术栈

| 层面 | 技术 |
|------|------|
| 前端 | 原生 HTML/CSS/JavaScript（无框架） |
| 后端 API | PHP（缓存分享链接） |
| 截图 | html2canvas |
| Web 服务器 | OpenResty (Nginx) Docker |
| SSL | Let's Encrypt（certbot 自动续期） |
| 管理面板 | 1Panel |

## 项目结构

```
eve-dscan-cn/
├── index.html       # 主页面（CSS + JS 内嵌）
├── ships-data.js    # 舰船数据（423 条，从 Excel 生成）
├── api.php          # 缓存 API（POST 保存 / GET 加载）
├── .gitignore
└── README.md
```

## 部署

纯静态 + PHP 缓存 API。部署到任意支持 PHP 的 Nginx/Apache 服务器：

```bash
# 文件放到站点目录
cp index.html ships-data.js api.php /www/sites/yoursite/
mkdir cache && chmod 777 cache
```

Nginx 需配置 PHP FastCGI 代理：
```nginx
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass php-fpm:9000;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

## 数据来源

- 舰船数据：CCP EVE Online Static Data Export (latest JSONL)
- 中文翻译：游戏内官方中文
- 原始 Excel：`EVE舰船分类大全_修正版.xlsx`（423 艘，2026-07-20 更新）

## 缓存 API

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api.php` | 提交扫描文本 `{"scan":"..."}` → 返回 `{"code":"a1b2c3"}` |
| GET | `/api.php?code=a1b2c3` | 加载缓存的扫描文本 |

- 最多保留 100 条缓存，超出自动删除最旧记录
- 无时间过期限制，仅通过数量上限自然淘汰

## ECM 对应规则

| 势力 | 传感器类型 | ECM 干扰 |
|------|-----------|---------|
| 艾玛 / 血袭者 / 萨沙 | 雷达 | 雷达 ECM |
| 加达里 / 古斯塔斯 / 莫德团 | 引力 | 引力 ECM |
| 盖伦特 / 天蛇集团 | 磁力 | 磁力 ECM |
| 米玛塔尔 / 天使集团 | 光雷达 | 光雷达 ECM |
| 三神裔 / ORE / 姐妹会 等 | 混合 | 多谱式 ECM |
