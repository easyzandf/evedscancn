# UI 改进计划（含 VPS 部署与双远端提交）

> 编写日期：2026-09-23　基线提交：`6f5aaf3`　状态：**计划稿，尚未改动任何代码**
>
> 阅读方式：第 1–2 章是核对过的事实，第 3–4 章是要做的事，第 6–8 章是上线与提交步骤。
> 每个阶段都可以独立发布，不必等全部做完。

---

## 1. 现状基线（已实测核对）

| 项目 | 现状 |
|---|---|
| 前端形态 | 单文件 `index.html`（4515 行 / 197KB），页面 + CSS + JS 内嵌；无框架、无构建步骤、无依赖清单 |
| 样式结构 | 两套并存：`index.html:19` 起是早期硬编码配色；`index.html:429` 起是后加的 CSS 变量主题层（`:root` + `body.light`） |
| 页面 | 7 个：D-Scan 解析 / 舰船浏览器 / 装备购买 / 30人本 / 战斗 / 会战报告 / 帮助 |
| 后端 | `api.php`（分享缓存）、`esi.php`（ESI 代理）、`raid.php`（记录，SQLite）、`kb.php`（会战，SQLite + zKill 代理） |
| 数据 | `ships-data.js`（531 艘）、`traits-data.js`、`items-data.js`（懒加载） |
| 第三方脚本 | html2canvas（境外 CDN 直连）、lucide 图标（**声明了但从未加载**） |
| 仓库远端 | `origin` = GitCode `ZANDF/evedscancn`（`master`）；`github` = `easyzandf/evedscancn`（`main`）；两者当前同为 `6f5aaf3` |
| 部署方式 | 手工 scp/cp 覆盖文件；仓库内无部署脚本、无 CI、无 tag |
| VPS | `vps-tradecross`（147.139.212.118，用户 `admin`） |
| 线上站点目录 | `/opt/1panel/apps/openresty/openresty/www/sites/tradecross.com/index` |
| VPS 环境 | 有 `git`；**没有 `rsync`**；宿主机**没有 `php`**（PHP-FPM 在容器 `172.18.0.4:9000`） |
| 站点目录约束 | 该目录**是共用目录**，里面还放着旧站的 `admin/ js/ css/ error/ config.php db.php index.php.bak phpinfo.php test.html`。部署必须**逐文件覆盖**，禁止 `rm -rf`、禁止 `rsync --delete` |
| 版本一致性 | `index.html`、`esi.php`、`raid.php`、`kb.php`、3 个数据文件的 MD5 与本地**完全一致**；`api.php` 仅差 63 字节（本地 CRLF / 线上 LF，内容相同） |

---

## 2. 问题清单（走查结论，附证据）

### P0 · 明确缺陷

| # | 问题 | 证据 / 位置 |
|---|---|---|
| 1 | **全站图标不显示**。`.topbar` 导航、页眉小标、按钮里的 `<i data-lucide="...">` 都是空元素，全站没有任何地方加载 lucide | `index.html:1088-1098`（导航）、`1108`、`1116-1117`、`1187`、`1212`、`1230`、`1255`、`1274`、`1287`；共 10 种图标 |
| 2 | **截图功能在境内直接失效**。html2canvas 从境外 CDN 引入，加载失败时 7 个「截图复制」按钮卡在「生成中…」，不报错、不恢复 | `index.html:1486`；实测 `typeof window.html2canvas === 'undefined'` 时抛 `html2canvas is not defined` |
| 3 | **手机端整页横向溢出**。舰船浏览器表格 `min-width:720px` 且没有滚动容器，390px 屏下文档宽被撑到 740px，页面可左右拖动 | `index.html:723`；表格 `index.html:1144`；其余表格都由 JS 包在 `.tbl-wrap` 里（`index.html:1763` 等），只有这张静态表漏了 |
| 4 | **一条样式失效**。`.raid-input { height: 165px }` 被权重更高的 `textarea.scanbox`（260px）覆盖，30人本 / 战斗页输入框高于设计 | `index.html:852` vs `index.html:85`；实测计算高度 260px |
| 5 | **小字对比度不足**。次要灰 `--dim` 大量用于 11–12px 文字（时间轴、条形图数值、页脚、计数） | 深色 `--dim #67718a`：3.37–3.92:1；浅色 `#8a92a6`：2.78–3.11:1（AA 要求 4.5:1）。浅色主题品牌橙 `#c07b0e` 仅 3.45:1 |
| 6 | **弹窗不具备对话框语义**。无 `role="dialog"` / `aria-modal`，打开不接管焦点，Esc 关不掉；全站只有 1 个 `aria-label` | `#shipModal` / `#personModal` / `#brKmModal`；实测 Esc 后弹窗仍 `show`，焦点停在背景的 `<a>` 上 |
| 7 | **切页不写 URL**。`switchPg()` 只切显隐，浏览器后退无效、刷新回首页、页面无法被收藏和分享 | `index.html:2285` |

### P1 · 一致性与可读性

| # | 问题 | 说明 |
|---|---|---|
| 8 | 字号层级缺失 | 正文只有 11/12/13px 三档（实测统计），中文 11px 偏难读；缺少 14–16px 的正文层 |
| 9 | 圆角混用 | 同时存在 20 / 12 / 8 / 7 / 6 / 5 / 4 / 3px |
| 10 | 双主题维护两遍 | 早期硬编码色值与变量层并存，改一次主题要改两处 |
| 11 | 舰船浏览器表格 | 分类列换行导致行高忽高忽低；列宽与内容不匹配（英文名列大量留白）；无斑马纹；531 行无分页 |
| 12 | D-Scan 结果页 | 大类 / 子类两级标题样式过于接近；行高偏大，一屏可见舰船数量少 |
| 13 | 会战报告图表 | 手写 SVG 用 `preserveAspectRatio="none"`，宽度变化时曲线拉伸变形；无 hover 数值；时间轴绝对定位 + 固定 18px 行高，小字偏挤 |
| 14 | 状态反馈不统一 | 只有一句「加载中…」，无骨架屏；各页空状态措辞与位置不一致 |

### 信息架构建议（可选，P3）

- 顶级导航 7 项偏多；「30人本」与「战斗」是同一套逻辑、两份数据，建议合并为「出战记录」再内部切换。
- 首页输入框到页脚之间大片留白，缺少「能做什么 / 最近记录 / 常用入口」的引导。

---

## 3. 目标与验收标准

**总目标**：在不改变现有功能与数据的前提下，让界面在桌面与手机上都没有明显缺陷，视觉统一，中文字号可读。

| 维度 | 可量化验收 |
|---|---|
| 缺陷 | 10 种图标全部渲染；截图按钮可用且失败时有文字提示；390px 宽无横向滚动 |
| 可读性 | 所有正文文字对比度 ≥ 4.5:1（大字 ≥ 3:1）；最小字号 ≥ 12px，正文 14px |
| 一致性 | 圆角收敛到 2–3 档；颜色/间距/字号全部走 CSS 变量 |
| 可访问性 | 弹窗有对话框语义、Esc 可关、焦点被约束在弹窗内；排序表头可用键盘触发 |
| 性能 | 首屏无境外 CDN 阻塞；`index.html` 不因改造显著增大（目标 < 230KB） |
| 兼容 | 亮/暗两套主题下均通过以上检查；Chrome / Edge / Safari 移动端正常 |

---

## 4. 分阶段实施计划

### 阶段 P0 · 速修（预计 0.5–1 天，改动最小、收益最大）

| # | 改动 | 落点 |
|---|---|---|
| P0-1 | 图标自托管：把 10 个 lucide 图标导出为本地 SVG（内联或 `icons.svg` 雪碧图 + 几行替换脚本），去掉对境外资源的依赖 | `index.html:1088-1098` 等 13 处 `<i data-lucide>` |
| P0-2 | html2canvas 自托管到仓库（`vendor/html2canvas.min.js`），并给 7 个截图按钮加失败提示与按钮复位 | `index.html:1486`；`screenshotResult()`、`raidScreenshot()` |
| P0-3 | 修手机端横向溢出：给 `#browTbl` 套 `.tbl-wrap`（与其余表格一致） | `index.html:1144` |
| P0-4 | 修 `.raid-input` 高度失效：提高选择器权重或改用变量 | `index.html:852` |
| P0-5 | 加 `.gitattributes`：`* text=auto`、`*.php text eol=lf`，消除 CRLF 造成的无意义差异 | 新增 |

**验收**：桌面 1440px 与手机 390px 各截一次图对比；`document.documentElement.scrollWidth === 390`；断开外网时截图按钮给出中文提示。

### 阶段 P1 · 可访问性与设计令牌（预计 1–2 天）

| # | 改动 |
|---|---|
| P1-1 | 三个弹窗补 `role="dialog"` / `aria-modal="true"` / `aria-labelledby`；打开时聚焦标题、Esc 关闭、Tab 限制在弹窗内、关闭后焦点回到触发元素；背景滚动锁定 |
| P1-2 | 提亮次要文字：深色 `--dim` 至少到 4.5:1（约 `#8b95ab`），浅色 `--dim` 与品牌橙同步加深；用脚本复算并写进文档 |
| P1-3 | 建立字号层级：12 / 13 / 14 / 16 / 22px，正文统一 14px；11px 只保留给纯数字标签 |
| P1-4 | 圆角收敛为 `--radius-s/m/l`（6/8/12px），间距用 4 的倍数；把 `index.html:19` 起的硬编码色值并入变量层 |
| P1-5 | 排序表头 `<th onclick>` 改可聚焦（`tabindex`+键盘事件）并加 `aria-sort` |

**验收**：对比度脚本全部达标；键盘可完成「打开弹窗 → 浏览 → Esc 关闭」全流程；亮暗两主题截图复核。

### 阶段 P2 · 页面级重构（预计 3–5 天，按页拆分提交）

| # | 改动 |
|---|---|
| P2-1 | 舰船浏览器：列宽重分配 + 分类列不换行（超长省略号 + title）、斑马纹、行高统一；531 行加分页或虚拟滚动；筛选栏改成「关键字 + 类别 + 科技」三段式 |
| P2-2 | D-Scan 结果页：拉开大类 / 子类两级标题的视觉差；压缩行高提升一屏密度；顶部汇总改成指标卡（可保留现有 ECM 汇总） |
| P2-3 | 会战报告：曲线改等比缩放（去掉 `preserveAspectRatio="none"`）并加 hover 值提示；时间轴改弹性行高，超过 N 行折叠 |
| P2-4 | 移动端：宽表格改卡片式（每艘船一张卡），导航改可横滑的分段控件；首页补引导与最近记录 |
| P2-5 | 截图输出单独一套扁平样式，保证截图与屏幕观感一致 |

**验收**：每页在 1440 / 1024 / 768 / 390 四个宽度下截图复核；对比重构前后同屏信息量。

### 阶段 P3 · 结构调整（可选，预计 2–3 天）

- 加 hash 路由（`#/browse`、`#/br`），支持刷新保持、后退、分享（现有 `#s=` / `#c=` 分享链接需兼容）。
- 「30人本」与「战斗」合并入口，保留两套独立数据。
- 把内嵌 CSS/JS 拆到 `app.css` / `app.js`（单文件从 4500 行降到可维护规模），同步更新部署清单。
- 视情况引入极简构建（如 esbuild）做压缩与版本号注入；若不想加构建，用查询串手动打版本号即可。

---

## 5. 本地验证方法

1. **静态预览**：仓库无构建步骤，起一个本地静态服务直接打开 `index.html` 即可（示例见下，端口自定）；PHP 相关页面（记录同步、会战报告）在没有后端的本地环境下会走离线/报错分支，属预期。
   ```powershell
   # 在仓库根目录起一个临时静态服务（Node 内置模块即可，无需安装依赖）
   node -e "require('http').createServer((q,s)=>{var u=decodeURIComponent(q.url.split('?')[0]);var p=u==='/'?'index.html':u.slice(1);require('fs').readFile(p,(e,d)=>{if(e){s.writeHead(404);return s.end('404');}s.writeHead(200,{'Content-Type':p.endsWith('.js')?'text/javascript':p.endsWith('.png')?'image/png':p.endsWith('.html')?'text/html; charset=utf-8':'application/octet-stream'});s.end(d);});}).listen(8765,function(){console.log('up');});"
   ```
2. **视口矩阵**：1440×900 / 1024×768 / 768×1024 / 390×844，各页各截一张，与本次走查的基线截图对比。
3. **横溢检查**：`document.documentElement.scrollWidth` 应等于视口宽。
4. **对比度检查**：把颜色令牌跑一遍 WCAG 对比度计算，结果写进提交说明。
5. **无外网验证**：断网或屏蔽境外域名后，页面应完全可用（含截图按钮的失败提示）。

---

## 6. 分支与提交规范

- 仓库现有提交风格：**英文、祈使句、主题前缀**，例如 `Timeline: one shared timestamp column, not three`。新提交沿用，例：
  - `Icons: render from a bundled sprite instead of the CDN`
  - `Browse: wrap the ship table so phones stop scrolling sideways`
  - `Modal: give dialogs dialog semantics, Esc, and focus management`
- 一个阶段一条分支，一条分支内按「一个问题一个提交」拆分，便于单独回滚：

```powershell
git switch -c codex/ui-p0-fixes master
# ... 改完并本地验证 ...
git add -A
git commit -m "Icons: render from a bundled sprite instead of the CDN"
```

- 动大改前先打基线标签（当前 `6f5aaf3` 就是可回退的干净起点）：

```powershell
git tag -a ui-baseline-6f5aaf3 -m "UI refactor baseline (pre-P0)"
git push origin ui-baseline-6f5aaf3
git push github ui-baseline-6f5aaf3
```

- 分支命名：`codex/ui-p0-fixes`、`codex/ui-p1-a11y`、`codex/ui-p2-browse`……
- 本地有 `master` 与 `main` 两个分支且同点，建议**只用 `master` 作为工作分支**，GitHub 侧用 `master:main` 推送，避免两边分叉。

---

## 7. 部署到 VPS

### 7.1 拓扑

- 站点目录：`/opt/1panel/apps/openresty/openresty/www/sites/tradecross.com/index`（Nginx 容器内路径 `/www/sites/tradecross.com/index`）
- 宿主机无 `rsync`、无 `php`；静态文件由 OpenResty 直接读盘，PHP 交给容器内 PHP-FPM（`172.18.0.4:9000`），**改文件后无需重启服务**，`openresty -s reload` 只在改 Nginx 配置时需要。
- SSH 别名已配好：`vps-tradecross`；Windows 自带 OpenSSH 客户端可直接 `ssh` / `scp`。

### 7.2 部署清单（当前）

| 文件 | 说明 |
|---|---|
| `index.html` | 主页面 |
| `ships-data.js` / `traits-data.js` / `items-data.js` | 数据 |
| `api.php` / `esi.php` / `raid.php` / `kb.php` | 后端 |
| `favicon.ico` / `favicon.png` / `apple-touch-icon.png` | 仅在图标改动时同步 |
| 新增（P0 之后） | `vendor/html2canvas.min.js`、图标资源文件、以及 P3 若拆分的 `app.css` / `app.js` |

> 不要上传：`.git/`、`__pycache__/`、`deploy/`（配置参考）、`raid_data/`、`kb_data/`、`cache/`、`esi_cache/`、抓取脚本 `*.py`。线上这几个数据目录已存在且必须保持 777 可写。

### 7.3 标准流程（每阶段发布一次）

```powershell
# 0) 发布前：确认本地与线上差异（避免覆盖别人的东西）
ssh vps-tradecross "ls -la /opt/1panel/apps/openresty/openresty/www/sites/tradecross.com/index"

# 1) 线上备份（改动前必做，保留最近几次即可）
ssh vps-tradecross "cd /opt/1panel/apps/openresty/openresty/www/sites/tradecross.com/index && tar czf ~/dscan-backup-$(date +%Y%m%d-%H%M).tgz index.html ships-data.js traits-data.js items-data.js api.php esi.php raid.php kb.php"

# 2) 上传（逐文件覆盖，不加 --delete）
scp index.html ships-data.js traits-data.js items-data.js api.php esi.php raid.php kb.php `
    vps-tradecross:/opt/1panel/apps/openresty/openresty/www/sites/tradecross.com/index/

# 3) 校验：本地与线上 MD5 必须逐个相同
Get-FileHash -Algorithm MD5 index.html,esi.php,raid.php,kb.php | ForEach-Object { "$($_.Hash.ToLower())  $($_.Path.Split('\')[-1])" }
ssh vps-tradecross "cd /opt/1panel/apps/openresty/openresty/www/sites/tradecross.com/index && md5sum index.html esi.php raid.php kb.php"
```

```bash
# 4) 回滚（万一线上出问题，一条命令恢复）
cd /opt/1panel/apps/openresty/openresty/www/sites/tradecross.com/index && tar xzf ~/dscan-backup-YYYYMMDD-HHMM.tgz
```

### 7.4 部署后冒烟测试

1. 打开 https://dscan.dpdns.org/ 并强制刷新（Ctrl+F5），确认页面版本已更新。
2. 依次点过 7 个页面，确认无脚本报错（DevTools Console 为空）。
3. D-Scan：粘贴示例文本 → 解析 → 结果分组正常。
4. 舰船浏览器：搜索 + 点船名 → 弹窗内容正常；手机上确认无横向滚动。
5. 30人本 / 战斗：记录一条 → 侧栏出现 → 刷新后仍在（验证 `raid_data/` 仍可写）。
6. 会战报告：分析一次（验证 `kb.php` 与 `kb_data/` 缓存正常）。
7. 截图复制按钮（自托管后）可用，且失败时有中文提示。
8. 检查 404：`favicon.ico`、`items-data.js`、新增的图标/CDN 替换文件。

### 7.5 顺手要处理的部署 hygiene（建议纳入 P0）

- **缓存策略**：目前静态文件没有显式 `Cache-Control`，浏览器可能长期用旧的 `index.html`。建议在 1Panel 的 OpenResty 站点配置里加：
  ```nginx
  location = /index.html { add_header Cache-Control "no-cache"; }
  location ~* \.(js|css|png|ico)$ { expires 7d; add_header Cache-Control "public"; }
  ```
  改配置后需要 `docker exec 1Panel-openresty-AW0v openresty -s reload`（容器名取自 `deploy/dscan-renew-hook.sh`）。
- 站点目录里的 `phpinfo.php`、`test.html`、`index.php.bak` 是旧站遗留，公开可访问，建议确认没人依赖后删除（**不在本次 UI 范围内，单独确认**）。
- 证书自动续期钩子已存在（`deploy/dscan-renew-hook.sh`），发布时顺手确认 `dscan.dpdns.org` 证书剩余天数 > 30 天。

---

## 8. 提交到 GitCode 与 GitHub

两个远端都在用，且默认分支名不同，推送命令要写清楚：

```powershell
# 1) 提交（在 codex/ui-p0-fixes 分支上）
git add -A
git commit -m "Browse: wrap the ship table so phones stop scrolling sideways"

# 2) 合回 master（保持单一工作分支）
git switch master
git merge --no-ff codex/ui-p0-fixes

# 3) 推送 GitCode（分支 master）
git push origin master

# 4) 推送 GitHub（本地 master 推到远端 main，两个远端保持同一点）
git push github master:main

# 5) 确认两边一致
git ls-remote --heads origin master
git ls-remote --heads github main
```

- 推送前先 `git fetch --all` 看远端有没有别人的提交，避免强推。
- **禁止 `--force`**（历史里已有线上提交，强推会破坏别人的克隆）。
- 建议给每个阶段打 tag：`git tag -a v0.2.0-ui-p0 -m "UI P0: icons, screenshots, mobile overflow"`，然后 `git push origin v0.2.0-ui-p0` 与 `git push github v0.2.0-ui-p0`。
- README 的「技术栈 / 项目结构」两处需要随 P0 自托管脚本与 P3 拆文件同步更新（`README.md` 对应小节）。
- 可选（暂不做）：GitHub Actions 做「推送后自动 scp 到 VPS + 冒烟检查」。若要做，需要用 SSH 密钥作为仓库 Secret，并在部署脚本里带上备份与回滚。

---

## 9. 时间线与建议顺序

| 阶段 | 内容 | 预计工时 | 独立可发布 |
|---|---|---|---|
| P0 | 图标、截图自托管、手机横溢、输入框高度、`.gitattributes` | 0.5–1 天 | ✅ |
| P1 | 弹窗可访问性、对比度、字号层级、令牌收敛 | 1–2 天 | ✅ |
| P2 | 浏览器页、结果页、会战报告、移动端 4 项页面重构 | 3–5 天 | ✅（按页发布） |
| P3 | hash 路由、入口合并、CSS/JS 拆分 | 2–3 天 | ✅ |

建议节奏：**P0 做完立刻发一版**（用户感知最强、风险最低），P1 与 P2 可以并行往下推，P3 视精力决定。

---

## 10. 风险与回滚

| 风险 | 应对 |
|---|---|
| 单文件 4500 行，改动容易误伤其他页面 | 一问题一提交；每阶段结束在 4 个视口 + 亮暗主题全量截图复核 |
| 覆盖线上文件出错 | 每次发布前 `tar` 备份；回滚命令见 7.3 |
| 站点目录是共用目录，误删旧站文件 | 只 `scp` 明确清单内的文件；禁止 `rm -rf` 与 `rsync --delete` |
| 自托管脚本后忘记一起上传 | 部署清单（7.2）随阶段更新；用 MD5 校验漏传 |
| 缓存导致用户看到旧页面 | 7.5 的 `Cache-Control`；发布后用带查询串的地址验证一次 |
| 两个远端分叉 | 用 `git ls-remote` 复核；只用 `master` 作为工作分支 |
