# EVE 舰船中文 D-Scan

基于 [dscan.info](https://dscan.info/) 设计理念的 EVE Online 纯中文舰船识别工具。

## 线上地址

**https://dscan.dpdns.org/**

> 旧域名 `www.evedscancn.cc.cd` 因 `cc.cd` 后缀在国内被网络阻断，已停用，请使用上方新域名。

## 功能

- **D-Scan 解析** — 粘贴 EVE 扫描结果（本地/舰队/D-Scan 均可），自动识别中/英文舰船名，按作战功能分组（后勤/特种/火力/运输）。精确到 typeID 识别，玩家自定义船名不会误判
- **ECM 建议** — 按扫描到的舰船势力自动推荐 ECM 干扰类型（雷达/引力/磁力/光雷达/多谱式）
- **舰船浏览器** — 浏览全部 531 艘舰船的中文数据，支持搜索/分类/科技等级/排序筛选，每行附 **ECM 建议**；点击船名查看详情
- **舰船详情弹窗** — 空船属性（质量/槽位/HP/电容/无人机等）+ 船体加成（中文 traits）
- **装备购买（Buy List）** — 粘贴你的**现有资产** + 一套**配装**，输入要装的艘数，自动扣除已有资产生成需购买清单；一键复制 Multi-Buy 格式回游戏批量购买（资产/配装中英文混用均可）
- **30人本记录** — 粘贴本场角色名，按当前时间存档；点记录复现参与者，点角色名看个人出战统计；跨场分析出场次数 / 日内时段 / 日期分布
- **战斗记录** — 与「30人本」同一套功能（存档 / 复现 / 个人统计 / 分析），但**数据完全独立**：各自的 localStorage、各自的同步码、各自在服务器上的记录
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

## 30人本 / 战斗记录怎么用

> 「30人本」与「战斗」是**两个独立的记录页**：同一套界面和统计，但记录、同步码、服务器数据三者都完全分开，
> 互不影响（30人本 的同步码填入战斗页也取不到任何东西）。下面以 30人本 为例，战斗页操作完全相同；导出/导入的文件也是各自独立的。

1. 复制本场参战名单（**每行一个角色名**即可，也可直接粘贴本地/舰队扫描原文，会按同样规则提取）
2. 点「**解析并记录**」→ 左侧自动生成一条**按当前时间**的记录（人数 ≠30 时会用琥珀色标出，只提示不强制）
3. **点记录** → 复现该场参与者；**点任意角色名** → 看这个人的个人出战统计
4. 切到「**分析**」标签 → 总场次 / 总人次 / 出场次数排行 / 日内时段分布 / 日期分布

**角色个人面板**：出场场次、出战天数、首次·最近、时段习惯、**常一起出战** Top 10（可点进去）、参战记录（点时间跳回该场），并顺带显示其军团/联盟（复用 `esi.php`）。

**记录操作**：悬停记录条目出现 `✎` 改备注/时间、`✕` 删除；左栏底部「导出 / 导入 / 同步码」。

### 数据存在哪

**本地 + 服务器双写**：记录先写浏览器 localStorage（即时、离线可用），再 best-effort 同步到 VPS 上的 SQLite。
左栏顶部显示同步状态（已同步 / 待同步 / 离线）。

站点是公开的，所以用**同步码**做隔离：本地首次使用自动生成一个随机码，服务器只存它的 `sha256`——
没有同步码读不到任何记录。**换设备时**点「同步码」填入同一个码即可取回记录（**务必自行留存**，清浏览器缓存后丢失就只能靠导出的 JSON 找回）。

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
├── raid.php              # 30人本记录 API（SQLite，见下方接口）
├── raid_data/            # SQLite 数据目录（raid.db，需 777 可写，勿提交）
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

## 30人本记录 API（`raid.php`）

SQLite 表 `runs(code, id, ts, note, names, updated_at)`，`code` 存的是**同步码的 sha256**。

| 方法 | 请求 | 返回 |
|------|------|------|
| GET | `?action=list&code=XXX` | `{"records":[{"id","ts","note","names":[],"updatedAt"}]}` |
| POST | `{"action":"save","code":..,"record":{...}}` | `{"ok":true}` |
| POST | `{"action":"delete","code":..,"id":..}` | `{"ok":true}` |

- `names` 最多 300 项、每项截断 64 字符；`note` 截断 200 字符
- 没有同步码（<8 字符）直接 400；用**不同**的同步码查不到别人的记录
- ⚠️ 部署时必须先建好可写目录，否则 PHP-FPM 建不了库：
  `sudo mkdir -p <站点>/raid_data && sudo chmod 777 <站点>/raid_data`

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
