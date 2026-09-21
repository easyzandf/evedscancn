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
- **30人本记录** — 粘贴本场角色名，按当前时间存档；名单按 **头衔 → 军团 → 角色** 展开；点角色名看个人出战统计，点头衔看该玩家名下所有号及两个日志的参与数据；跨场分析出场次数 / 小号分布 / 时段 / 日期分布，支持筛选
- **战斗记录** — 与「30人本」同一套功能（存档 / 复现 / 个人统计 / 分析），但**数据完全独立**：各自的 localStorage、各自的同步码、各自在服务器上的记录
- **本方 / 敌对** — 本方军团固定为 PLA-F、P.L.A，其余一律归敌对；**统计只算本方**，敌对只标注总数
- **面板截图** — 角色面板、玩家面板均可一键截图到剪贴板（内容过长会先展开再截）
- **ESI 查询** — 粘贴角色名列表，自动查询军团/联盟/头衔（需 PHP 后端）
- **KB 统计** — 输入军团 / 联盟 / 角色的名称或缩写（`PLA-F` 这类 ticker 也行），查看 zKillboard 的作战数据：总览卡片（击杀/损失、摧毁与损失价值、作战效率、危险度）、**舰船统计**、**近 7 天击杀/损失明细**；点任意一条看**单场详情**——参战方各人的舰船与**打出的伤害**、受害者的舰船与**承担伤害**、装配、最后一击。舰船/装备名全部走本地词典转中文
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

### 本方 / 敌对

**本方军团固定为 `PLA-F` 与 `P.L.A`**（代码里的 `LOG_HOME_CORPS`，要改直接改这个常量）：

- **所有统计只算本方**：出场次数排行、人物与小号分布、玩家-角色对照、各项卡片，都只包含这两个军团的角色
- 其他所有军团（以及查不到军团的）一律归为 **敌对**：在名单里单独成块并标红，**只统计一个总角色数**，不进任何榜单
- 名单（本场详情）分成「本方」和「敌对」两大块，敌对块整体偏红，一眼看出对面来了多少

1. 复制本场参战名单（**每行一个角色名**即可，也可直接粘贴本地/舰队扫描原文，会按同样规则提取）
2. 点「**解析并记录**」→ 左侧自动生成一条**按当前时间**的记录（人数 ≠30 时会用琥珀色标出，只提示不强制）
3. **点记录** → 看本场名单（按 **头衔 → 军团 → 角色** 展开，跨军团的号分行并给出小计）
4. **点角色名** → 个人出战面板；**点头衔** → 该玩家**名下所有号** + **30人本 / 战斗 两边**的参与数据
5. 切到「**分析**」标签 → 概览卡片 / 人物与小号分布 / 出场次数排行 / 日内时段分布 / 日期分布

**角色个人面板**：出场场次、出战天数、首次·最近、时段习惯、**常一起出战** Top 10（可点进去）、参战记录（点时间跳回该场），并顺带显示其军团/联盟。

**玩家（头衔）面板**：名下所有角色（按军团分行）、以及该玩家在「30人本」和「战斗」**两边**各自的出场场次、时段分布和逐场记录（每场按军团分行列出他用到的号）。

**截图**：两个面板右上角都有「截图复制」，一键把整块内容截到剪贴板；内容超长时先自动展开再截，不会被裁掉。

**记录操作**：悬停记录条目出现 `✎` 改备注/时间、`✕` 删除；左栏底部「导出 / 导入 / 同步码」。

**分析的口径**：分析统计**全部记录**（不是当前选中那条），标题里会写明条数；表格上方有**筛选框**，可按角色名 / 军团 / 头衔过滤。

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
├── esi.php               # ESI 代理（单个查询 + 批量查询）
├── raid.php              # 记录 API：30人本 & 战斗 共用（SQLite，见下方接口）
├── raid_data/            # SQLite 数据目录（raid.db，需 777 可写，勿提交）
├── kb.php                # KB 统计 API：代理 zKillboard 聚合数据（SQLite 缓存，见下方接口）
├── kb_data/              # KB 缓存目录（kb.db，需 777 可写，勿提交）
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
cp index.html ships-data.js traits-data.js items-data.js \
   api.php esi.php raid.php kb.php /www/sites/yoursite/
mkdir -p cache raid_data kb_data && chmod 777 cache raid_data kb_data
```

> `raid_data/` 必须可写，否则 PHP-FPM 建不了 SQLite 库，记录接口会 500。

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

## 记录 API（`raid.php`，30人本 / 战斗 共用）

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

## KB 统计 API（`kb.php`）

数据全部来自 **zKillboard** 的公开端点 + ESI，站点只做代理 + 缓存，不存原始 killmail，也不需要轮询。

```bash
GET ?action=resolve&q=PLA-F                    # 名称/缩写 -> 实体（ESI /universe/ids/，支持 ticker）
GET ?action=stats&type=corporationID&id=98764551   # 聚合总览 + Top 舰船/角色
GET ?action=kills&type=corporationID&id=98764551&days=7   # 击杀/损失明细（含伤害）
GET ?action=names&ids=1,2,3                    # 批量 ID -> 名（角色/军团/星系/物品类型）
GET ?action=types&ids=72872,71478              # 批量 typeID -> 中文名
```

SQLite 表 `kb_cache(k, fetched_at, payload)`，一个键一行，全部带 `KB_VER` 前缀（**改了缓存结构就把 `KB_VER` +1**，旧数据自动失效，不用去服务器删库）：

| 键 | 内容 | TTL |
|---|---|---|
| `s:<type>:<id>` | 裁剪后的聚合数据 + Top 舰船/角色 | 10 分钟 |
| `k:<type>:<id>:<days>` | killmail 明细列表 | 10 分钟 |
| `q:<md5>` / `nm:<id>` | 名称解析 | 7 天 |
| `ty:<id>` | 物品/舰船类型中文名 | 30 天 |

要点：

- 聚合走 `/api/stats/{type}/{id}/kills/`——**一次调用就返回总览要的全部数字**（实测 71KB / 0.8s），不用自己拉 killmail 做聚合。显式带 `/kills/` 能省掉 zKillboard 的一次 302。
- 明细走 `/api/kills/` + `/api/losses/`，用 `curl_multi` **并行**拉（单页 200 条 / 700KB+，串行明显更慢）。列表端点里每条都带完整 ESI 结构 —— `attackers[].damage_done`、`victim.damage_taken`、装配、最后一击 —— 所以**前端列表和详情共用同一份数据，点开某条不用再发请求**。
- `type` 走白名单（`corporationID` / `allianceID` / `characterID`），因为它会被拼进上游 URL。
- 响应在 PHP 里 gzip（`ob_gzhandler`）：nginx 全局的 `gzip_types` 没有 `application/json`，击杀明细 360KB 压完约 65KB。
- ⚠️ 同样需要可写目录：`sudo mkdir -p <站点>/kb_data && sudo chmod 777 <站点>/kb_data`

> 不要把「自定义时间窗统计」做成实时请求：`pastSeconds` 上限就是 7 天，更长的窗口要自己分页求和，实测单页 1.3~8.5s 且会偶发失败，得配后台预热任务才行。

### 舰船/装备中文名从哪来

前端按优先级解析 typeID：`ships-data.js`（531 艘，`attr.typeID` 索引）→ `items-data.js`（5976 项，顶层键就是 typeID）→ `kb.php?action=types`（本地没有的 NPC 舰船/建筑/无人机等，走 ESI `/universe/types/?language=zh`）。所以页面上不会出现裸露的 `#数字`。

## 缓存 API

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api.php` | 提交扫描文本 `{"scan":"..."}` → 返回 `{"code":"a1b2c3"}` |
| GET | `/api.php?code=a1b2c3` | 加载缓存的扫描文本 |
| GET | `/esi.php?name=X` | 单个角色 → 军团/联盟/头衔 |
| POST | `/esi.php` `{"names":[...]}` | **批量**查询（≤300 个）→ `{"results":{...}}` |

> 批量接口是给记录页用的：逐个请求会在 PHP-FPM 进程池上排队（78 个名字要 1–2 分钟）。
> 批量版一次 `/universe/ids/` 解析名字，角色详情用 `curl_multi` 并发拉取，78 个名字冷启动约 **4 秒**、命中缓存约 **0.4 秒**。

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
