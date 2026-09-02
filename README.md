# EVE 舰船中文 D-Scan

基于 [dscan.info](https://dscan.info/) 设计理念的 EVE Online 纯中文舰船识别工具。

## 线上地址

**https://www.evedscancn.cc.cd/**

## 功能

- **D-Scan 解析** — 粘贴 EVE 扫描结果（本地/舰队/D-Scan 均可），自动识别中/英文舰船名，按作战功能分组（后勤/特种/火力/运输）。精确到 typeID 识别，玩家自定义船名不会误判
- **ECM 建议** — 按扫描到的舰船势力自动推荐 ECM 干扰类型（雷达/引力/磁力/光雷达/多谱式）
- **舰船浏览器** — 浏览全部 531 艘舰船的中文数据，支持搜索/分类/科技等级/排序筛选，每行附 **ECM 建议**；点击船名查看详情
- **舰船详情弹窗** — 空船属性（质量/槽位/HP/电容/无人机等）+ 船体加成（中文 traits）
- **装备购买（Buy List）** — 粘贴你的**现有资产** + 一套**配装**，输入要装的艘数，自动扣除已有资产生成需购买清单；一键复制 Multi-Buy 格式回游戏批量购买（资产/配装中英文混用均可）
- **ESI 查询** — 粘贴角色名列表，自动查询军团/联盟（需 PHP 后端）
- **分享链接** — 每次解析生成独立短码链接（`#c=xxxxxx`），发给队友即可看到相同结果
- **一键截图** — 将解析结果截图为 PNG 复制到剪贴板（需 HTTPS）
- **暗色/亮色主题** — 默认暗色，可切换

## 装备购买（Buy List）怎么用

1. 游戏内 **资产 / 机库窗口** 全选 → 复制 → 粘贴到「装备购买」①（支持中英文、可多段/多角色拼一起）
2. 复制一套**配装**（游戏内复制 / Pyfa / EVE Workbench 均可，中英文模块名都行）→ 粘贴到②
3. 在③填要装的**艘数** → 点「计算购买清单」
4. 结果按需购买数量排序（装备、弹药、无人机、船体都会算）；点「复制购买清单(Multi-Buy)」→ 回游戏 **Multi-Buy / 批量购买** 窗口直接粘贴下单

> 原理：通过 typeID 物品词典把中文资产与（可能的）英文配装统一归并；需要量 = 单船配装 × 艘数，需购买 = 需要 − 已有。弹药/无人机按配装里的 `xN` 计算，船体单独抵扣。

## 技术栈

| 层面 | 技术 |
|------|------|
| 前端 | 原生 HTML/CSS/JavaScript（无框架，单文件） |
| 后端 API | PHP（缓存分享链接、ESI 代理） |
| 物品词典 | everef reference-data（中英文名，按需懒加载） |
| 截图 | html2canvas |
| Web 服务器 | OpenResty (Nginx) Docker |
| SSL | Let's Encrypt（certbot 自动续期） |
| 管理面板 | 1Panel |

## 项目结构

```
eve-dscan-cn/
├── index.html            # 主页面（页面 + CSS + JS 内嵌）
├── ships-data.js         # 舰船数据 531 艘（CCP SDE + ESI）
├── traits-data.js        # 船体加成中文数据 517 艘（everef ref-data）
├── items-data.js         # 物品中英文词典 5976 种（装备购买用，懒加载）
├── api.php               # 缓存 API（POST 保存 / GET 加载分享链接）
├── esi.php               # ESI 代理（角色/军团/联盟查询）
├── favicon.ico / favicon.png / apple-touch-icon.png  # 站点图标
├── make_icon.py          # 图标生成脚本
├── fetch_attrs.py        # 从 ESI 拉取舰船属性
├── fetch_missing.py      # 从 ESI 补全缺失舰船（108 艘）
├── fetch_traits.py       # 从 everef 拉取船体加成
├── compare_ships.py      # 对比本地与 ESI 最新数据
├── build_items_vps.py    # 从 everef reference-data 生成物品词典
├── missing_new.json      # 补船过程的中间产物（已全部并入 ships-data.js）
└── README.md
```

## 部署

纯静态 + PHP 缓存 API。部署到任意支持 PHP 的 Nginx/Apache 服务器：

```bash
# 文件放到站点目录
cp index.html ships-data.js traits-data.js items-data.js api.php /www/sites/yoursite/
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

- 舰船数据：CCP EVE Online Static Data Export (latest JSONL) + ESI
- 中文翻译：游戏内官方中文
- 原始 Excel：`EVE舰船分类大全_修正版.xlsx`（423 艘，2026-07-20 更新），后续经 ESI 补充 108 艘缺失船（合计 531 艘）
- 物品中英文名：everef reference-data（`reference-data-latest.tar.xz`）

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
