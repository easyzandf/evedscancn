/* LP 兑换页面：加达里 / 盖伦特国民卫队 LP 商店 x 欧服市场行情
 *
 * 数据接口是同目录的 lp.php：
 *   - offers    LP 商店报价（缓存 24h）
 *   - aggregate 每个 type 的最高收购价 / 最低出售价（缓存 15min）
 *   - book      单个 type 的买卖挂单前五（缓存 15min，展开某一行时才拉）
 *
 * 排序口径：秒卖 = 直接卖给最高收购价；挂卖 = 挂最低出售价。
 * 两者都不算手续费和税，按需求锁定。
 */
(function () {
  'use strict';

  var LP_STORES = [
    { key: 'C', corp: 1000180, name: '加达里国民卫队', short: 'C 卫队' },
    { key: 'G', corp: 1000181, name: '盖伦特国民卫队', short: 'G 卫队' }
  ];

  var LP_REGIONS = [
    { id: 10000002, label: 'Jita / The Forge' },
    { id: 10000043, label: 'Amarr / Domain' },
    { id: 10000032, label: 'Dodixie / Sinq Laison' },
    { id: 10000030, label: 'Rens / Heimatar' },
    { id: 10000042, label: 'Hek / Metropolis' }
  ];

  // 主要贸易枢纽空间站（只看站内行情）
  // 点表头排序时，每个列第一次点击的默认方向
  var LP_SORT_DIR = { name: 'asc', lp: 'asc', cost: 'asc', sell: 'desc', now: 'desc', list: 'desc', contract: 'desc', depth: 'desc', vol: 'asc', h48: 'desc', total: 'desc' };
  var LP_STATIONS = [
    { id: 60003760, region: 10000002, label: 'Jita IV-4 海军装配厂' },
    { id: 60008494, region: 10000043, label: 'Amarr VIII 皇帝家族学院' },
    { id: 60011866, region: 10000032, label: 'Dodixie IX-20 联邦海军装配厂' },
    { id: 60004588, region: 10000030, label: 'Rens VI-8 布鲁特部落财政部' },
    { id: 60005686, region: 10000042, label: 'Hek VIII-12 无限创造工厂' }
  ];

  function stationLabel() {
    var label = '';
    LP_STATIONS.forEach(function (s) { if (s.id === _lp.station) label = s.label; });
    return label || String(_lp.station);
  }

  // 顶部栏本身是 sticky 的，表头吸顶要避开它的高度
  function syncStickyTop() {
    var tb = document.querySelector('.topbar');
    if (tb) document.documentElement.style.setProperty('--lp-sticky-top', tb.offsetHeight + 'px');
  }

  // 销售税 = 7.5% × (1 − 11% × Accounting 等级)；默认 4 级 = 4.2%
  var LP_TAX_BASE = 7.5;
  var LP_TAX_PER_LEVEL = 0.11;
  // 默认费率：Accounting 4 级、中介费 2.4%、安全税 0；页面上的控件可改
  var LP_FEE_DEFAULT = { taxLevel: 4, broker: 1.8, safe: 0 };

  // 复制图标（lucide copy，内联避免依赖）
  var LP_COPY_ICON = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';
  // 刷新图标（lucide refresh-cw）
  var LP_REFRESH_ICON = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>';

  var _lp = {
    inited: false,
    loading: false,
    loaded: false,
    region: 10000002,
    station: 60003760,
    offers: {},
    prices: {},
    esiNames: {},
    books: {},
    depth: {},
    matLevels: {},
    qty: {},
    depthLoading: false,
    depthRegion: 0,
    contracts: {},
    contractsLoading: false,
    contractsRegion: 0,
    history: {},
    historyLoading: false,
    historyLoaded: false,
    volumes: {},
    volumesLoaded: false,
    lp: { C: '', G: '' },
    fee: { taxLevel: LP_FEE_DEFAULT.taxLevel, broker: LP_FEE_DEFAULT.broker, safe: LP_FEE_DEFAULT.safe },
    faction: 'all',
    sort: 'now',
    sortDir: 'desc',
    q: '',
    onlyMarket: true,
    onlyContract: false,
    hideBp: true,
    noReq: false,
    expanded: {},
    rows: [],
    page: 1,
    pageSize: 20,
    bookBusy: {},
    matBusy: {},
    bookMeta: {},
    pricesAt: 0
  };

  // -- 小工具 --
  function $(id) { return document.getElementById(id); }

  function escHtml(s) {
    if (typeof esc === 'function') return esc(s);
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function num(v) {
    var n = parseFloat(v);
    return isFinite(n) ? n : 0;
  }

  // Accounting 等级 -> 销售税百分比
  function salesTaxPct() {
    var lv = Math.max(0, Math.min(5, Math.round(num(_lp.fee.taxLevel))));
    return LP_TAX_BASE * (1 - LP_TAX_PER_LEVEL * lv);
  }

  function iskN(v) {
    if (v === null || v === undefined || !isFinite(v)) return '—';
    return String(Math.round(v)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function iskShort(v) {
    if (v === null || v === undefined || !isFinite(v)) return '—';
    if (Math.abs(v) >= 1e12) return (v / 1e12).toFixed(2) + ' T';
    if (Math.abs(v) >= 1e9) return (v / 1e9).toFixed(2) + ' B';
    if (Math.abs(v) >= 1e6) return (v / 1e6).toFixed(2) + ' M';
    if (Math.abs(v) >= 1e3) return (v / 1e3).toFixed(1) + ' K';
    return String(Math.round(v));
  }

  // 体积：m3，按 K/M 分级
  function volFmt(v) {
    if (v === null || v === undefined || !isFinite(v)) return '—';
    if (v === 0) return '0';
    var a = Math.abs(v);
    if (a >= 1e6) return (v / 1e6).toFixed(2) + ' M';
    if (a >= 1e3) return (v / 1e3).toFixed(2) + ' K';
    if (a >= 1) return String(Math.round(v * 100) / 100);
    return v.toFixed(3);
  }

  // 每 LP 收益：小于 100 保留一位小数，其余取整
  function perLp(v) {
    if (v === null || v === undefined || !isFinite(v)) return '—';
    return Math.abs(v) >= 100 ? iskN(v) : v.toFixed(1);
  }

  function ago(ts) {
    if (!ts) return '—';
    var d = Math.max(0, Math.floor(Date.now() / 1000) - ts);
    if (d < 60) return '刚刚';
    if (d < 3600) return Math.floor(d / 60) + ' 分钟前';
    if (d < 86400) return Math.floor(d / 3600) + ' 小时前';
    return Math.floor(d / 86400) + ' 天前';
  }

  function clock(ms) {
    var d = new Date(ms);
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function apiGet(url, cb) {
    var xhr = new XMLHttpRequest();
    var done = false;
    function finish(v) { if (!done) { done = true; cb(v); } }
    xhr.open('GET', url, true);
    xhr.timeout = 60000;
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      var r = null;
      try { r = JSON.parse(xhr.responseText); } catch (e) { r = null; }
      finish(r);
    };
    xhr.onerror = function () { finish(null); };
    xhr.ontimeout = function () { finish(null); };
    xhr.send(null);
  }

  function setHint(t) {
    var el = $('lpHint');
    if (el) el.textContent = t || '';
  }

  function renderBody(html) {
    var el = $('lpBody');
    if (el) el.innerHTML = html;
  }

  // 输入框连续打字时不要每敲一下就重建整张表
  var _renderTimer = null;
  var _renderReuse = false;
  // reuse=true：只重排/重画，不重算 785 条。改 LP 数量、费率这类会影响计算值的操作不能传 true。
  function scheduleRender(reuse) {
    _renderReuse = !!reuse;
    if (_renderTimer) clearTimeout(_renderTimer);
    _renderTimer = setTimeout(function () { _renderTimer = null; render(_renderReuse); }, 150);
  }

  // -- 材料清单复制（Multi-Buy：名称 + Tab + 数量）--
  function copyText(t, ok) {
    if (typeof _copyText === 'function') { _copyText(t, null, ok); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(ok, function () {});
  }

  function sanName(s) {
    if (typeof _sanName === 'function') return _sanName(s);
    return String(s == null ? '' : s).replace(/[\t\r\n]+/g, ' ');
  }

  function flashCopied(el, msg) {
    if (!el) return;
    if (el.tagName === 'BUTTON' && !el.querySelector('svg')) {
      if (!el.getAttribute('data-label')) el.setAttribute('data-label', el.textContent);
      el.textContent = msg;
      el.classList.add('lp-copied-btn');
      setTimeout(function () {
        el.textContent = el.getAttribute('data-label') || el.textContent;
        el.classList.remove('lp-copied-btn');
      }, 1500);
    } else {
      el.classList.add('lp-copied');
      setTimeout(function () { el.classList.remove('lp-copied'); }, 1200);
    }
  }

  // 按 "卫队:offer_id" 找到计算好的那一行（展开时用）
  function findRowByKey(key) {
    for (var i = 0; i < _lp.rows.length; i++) {
      var r = _lp.rows[i];
      if ((r.store.key + ':' + r.offer.offer_id) === key) return r;
    }
    return null;
  }

  function rowKey(r) { return r.store.key + ':' + r.offer.offer_id; }

  function findOffer(key) {
    var parts = String(key || '').split(':');
    var sk = parts[0], oid = parseInt(parts[1], 10);
    var found = null;
    (_lp.offers[sk] || []).forEach(function (o) { if (o.offer_id === oid) found = o; });
    return found;
  }

  function copyMaterialList(key, el) {
    var o = findOffer(key);
    if (!o) return;
    if (!o.required_items || !o.required_items.length) { flashCopied(el, '无需材料'); return; }
    var lines = o.required_items.map(function (ri) {
      return sanName(typeName(ri.type_id)) + '\t' + ri.quantity;
    });
    copyText(lines.join('\n'), function () { flashCopied(el, '已复制!'); });
  }

  // 刷新这条报价用到的材料行情（只刷这几个 type，绕过 15 分钟缓存）。
  // 关键：等待期间不碰表格里的任何 DOM——758 行的表，格子里改一个字都会引发整表重排 + 重绘，
  // 弱机器上就是“浏览器无响应”。进度只写在表格外的 #lpHint。
  function refreshMaterials(key, btn) {
    var o = findOffer(key);
    if (!o || !o.required_items || !o.required_items.length) return;
    if (_lp.matBusy[key]) return;
    _lp.matBusy[key] = 1;
    var ids = o.required_items.map(function (ri) { return ri.type_id; });
    var nm = typeName(o.type_id);
    var t0 = Date.now();
    setHint('正在刷新「' + nm + '」的材料价格…');
    var tick = setInterval(function () {
      setHint('正在刷新「' + nm + '」的材料价格… ' + Math.round((Date.now() - t0) / 1000) + 's');
    }, 1000);
    apiGet('lp.php?action=depths&region=' + _lp.region + '&station=' + _lp.station + '&ids=' + ids.join(',') + '&force=1', function (res) {
      if (!res || !res.depths) {
        clearInterval(tick);
        delete _lp.matBusy[key];
        setHint('材料价格刷新失败，请重试');
        return;
      }
      var depths = res.depths;
      // 前五档不够算大批量；连带把完整卖单档位也强刷一遍
      ensureMatLevels(ids, true, function () {
        clearInterval(tick);
        delete _lp.matBusy[key];
        var n = applyMaterialDepths(ids, depths);
        setHint('已刷新「' + nm + '」的材料价格 · 共更新 ' + n + ' 行');
        setTimeout(function () { setHint(''); }, 3500);
      });
    });
  }

  // 这些 type 的行情变了：写入 depth，只重算用到它们的报价（其余行复用），
  // 然后按当前排序重画这一页。实测比整表重算 + 整表重绘快很多。
  function applyMaterialDepths(typeIds, depths) {
    Object.keys(depths).forEach(function (k) { _lp.depth[k] = depths[k]; });

    var hit = {};
    typeIds.forEach(function (t) { hit[String(t)] = 1; });

    var keys = {};
    LP_STORES.forEach(function (st) {
      (_lp.offers[st.key] || []).forEach(function (o) {
        if (!o.lp_cost || o.lp_cost <= 0) return;
        var used = !!hit[String(o.type_id)];
        if (!used) {
          (o.required_items || []).forEach(function (ri) { if (hit[String(ri.type_id)]) used = true; });
        }
        if (used) keys[st.key + ':' + o.offer_id] = 1;
      });
    });
    var n = Object.keys(keys).length;

    recomputeOffers(keys);
    return n;
  }

  // 只重算这几条报价，其余行直接复用 _lp.rows，最后只重排 + 重画当前这一页。
  // 改「拟兑换数量」、材料行情变化都走这里。
  function recomputeOffers(keys) {
    var fresh = computeRows(keys);
    if (!fresh.length || !_lp.rows.length) { render(); return; }
    var byKey = {};
    fresh.forEach(function (r) { byKey[rowKey(r)] = r; });
    _lp.rows = _lp.rows.map(function (r) { return byKey[rowKey(r)] || r; });
    render(true);
  }

  // 拉取这几项材料的完整卖单档位（服务端按价格聚合，缓存 15 分钟）
  function ensureMatLevels(ids, force, cb) {
    if (typeof force === 'function') { cb = force; force = false; }
    var want = [];
    ids.forEach(function (t) {
      if (!t) return;
      if (force || !_lp.matLevels[t]) want.push(t);
    });
    if (!want.length) { cb(); return; }

    var chunks = [];
    for (var i = 0; i < want.length; i += 20) chunks.push(want.slice(i, i + 20));
    var left = chunks.length;
    chunks.forEach(function (c) {
      apiGet('lp.php?action=matlevels&region=' + _lp.region + '&station=' + _lp.station
        + '&ids=' + c.join(',') + (force ? '&force=1' : ''), function (res) {
        if (res && res.levels) {
          Object.keys(res.levels).forEach(function (k) { _lp.matLevels[k] = res.levels[k]; });
        }
        if (--left === 0) cb();
      });
    });
  }


  // -- 名称 --
  function localName(id) {
    if (typeof kbTypeLocal === 'function') {
      var kn = kbTypeLocal(id);
      if (kn) return kn;
    }
    var d = window.ITEMS_DATA && window.ITEMS_DATA[id];
    if (!d) return '';
    return d.zh || d.en || '';
  }
  function typeName(id) { return localName(id) || _lp.esiNames[id] || ('#' + id); }
  function typeEn(id) {
    var s = (typeof _kbShipIndex === 'function') ? _kbShipIndex()[id] : null;
    if (s && s.en) return s.en;
    var d = window.ITEMS_DATA && window.ITEMS_DATA[id];
    return (d && d.en) ? d.en : '';
  }
  function isBlueprint(id) {
    var n = (typeName(id) + ' ' + typeEn(id)).toLowerCase();
    return n.indexOf('blueprint') !== -1 || n.indexOf('蓝图') !== -1;
  }

  function eachOffer(fn) {
    LP_STORES.forEach(function (st) {
      (_lp.offers[st.key] || []).forEach(function (o) { fn(st, o); });
    });
  }

  function allTypeIds() {
    var ids = {};
    eachOffer(function (st, o) {
      if (o.type_id) ids[o.type_id] = 1;
      (o.required_items || []).forEach(function (ri) { if (ri.type_id) ids[ri.type_id] = 1; });
    });
    return Object.keys(ids);
  }

  // -- 加载流水线 --
  function loadItemsSafe(cb) {
    if (typeof loadItems === 'function') loadItems(cb);
    else cb();
  }

  function start(force) {
    if (_lp.loading) return;
    _lp.loading = true;
    setHint('正在加载 LP 商店报价…');
    if (!_lp.loaded) renderBody('<div class="lp-empty">正在加载 LP 商店报价…</div>');
    loadItemsSafe(function () { loadOffers(force); });
  }

  function loadOffers(force) {
    var pending = LP_STORES.length;
    var failed = 0;
    LP_STORES.forEach(function (st) {
      apiGet('lp.php?action=offers&corp=' + st.corp + (force ? '&force=1' : ''), function (res) {
        if (res && res.offers) _lp.offers[st.key] = res.offers;
        else failed++;
        if (--pending !== 0) return;
        if (!_lp.offers.C && !_lp.offers.G) {
          _lp.loading = false;
          setHint('LP 商店报价加载失败，请稍后重试');
          renderBody('<div class="lp-empty">无法获取 LP 商店报价：需要 PHP 后端（lp.php）和 ESI 连接。</div>');
          return;
        }
        if (failed) setHint('部分阵营报价加载失败，已显示可用的一半');
        loadNames(force);
      });
    });
  }

  function loadNames(force) {
    var ids = allTypeIds();
    var miss = ids.filter(function (id) { return !localName(id) && !_lp.esiNames[id]; });
    if (!miss.length) { loadPrices(force); return; }

    var chunks = [];
    for (var i = 0; i < miss.length; i += 60) chunks.push(miss.slice(i, i + 60));
    var left = chunks.length;
    var step = function () { if (--left === 0) loadPrices(force); };
    chunks.forEach(function (c) {
      apiGet('kb.php?action=types&ids=' + c.join(','), function (res) {
        if (res && res.types) {
          Object.keys(res.types).forEach(function (k) { _lp.esiNames[k] = res.types[k]; });
        }
        step();
      });
    });
  }

  function loadPrices(force) {
    var ids = allTypeIds();
    if (!ids.length) { finishLoad(); return; }

    var chunks = [];
    for (var i = 0; i < ids.length; i += 200) chunks.push(ids.slice(i, i + 200));
    var total = ids.length;
    var doneN = 0;

    var next = function (idx) {
      if (idx >= chunks.length) { finishLoad(); return; }
      setHint('正在拉取市场行情… ' + Math.min(doneN, total) + ' / ' + total);
      var url = 'lp.php?action=aggregate&region=' + _lp.region + '&ids=' + chunks[idx].join(',') + (force ? '&force=1' : '');
      apiGet(url, function (res) {
        if (res && res.prices) {
          Object.keys(res.prices).forEach(function (k) { _lp.prices[k] = res.prices[k]; });
          if (res.cachedAt) _lp.pricesAt = res.cachedAt;
        }
        doneN += chunks[idx].length;
        next(idx + 1);
      });
    };
    next(0);
  }

  function finishLoad() {
    _lp.loading = false;
    _lp.loaded = true;
    _lp.inited = true;
    if (_lp.contractsRegion !== _lp.region) loadContracts(!!_lp.forceDepth);
    if (!_lp.volumesLoaded) loadVolumes();
    if (!_lp.historyLoaded) loadHistory();
    if (_lp.depthRegion !== _lp.region) {
      // 先拉站内订单簿再渲染：避免加载期间显示全区价被当成站内价
      setHint('正在拉取 ' + stationLabel() + ' 站内行情…首次约 10~30 秒');
      loadDepths(!!_lp.forceDepth, function () { render(); });
    } else {
      setHint('');
      render();
    }
    _lp.forceDepth = false;
  }

  // 前五收单深度：整表约 500 个 type，服务端逐 type 缓存 15 分钟，首次拉完后面秒开
  // 公开合同：服务端按小时从 everef 快照重建，只保留 LP 可换物品的单物品合同
  // 物品体积：volume 与区域无关，服务端缓存 30 天，全站只拉一次
  // 48h 成交量/均价：ESI 市场历史是区域级、按天的，取最近两个日桶
  function loadHistory() {
    if (_lp.historyLoading || _lp.historyLoaded) return;
    _lp.historyLoading = true;
    var uniq = {};
    eachOffer(function (st, o) { if (o.type_id) uniq[o.type_id] = 1; });
    var list = Object.keys(uniq);
    if (!list.length) { _lp.historyLoading = false; _lp.historyLoaded = true; return; }
    var chunks = [];
    for (var i = 0; i < list.length; i += 120) chunks.push(list.slice(i, i + 120));
    var left = chunks.length;
    chunks.forEach(function (c) {
      apiGet('lp.php?action=history&region=' + _lp.region + '&ids=' + c.join(','), function (res) {
        if (res && res.history) {
          Object.keys(res.history).forEach(function (k) { _lp.history[k] = res.history[k]; });
        }
        if (--left === 0) {
          _lp.historyLoading = false;
          _lp.historyLoaded = true;
          if (_lp.loaded && _lp.depthRegion === _lp.region) scheduleRender();
        }
      });
    });
  }

  function loadVolumes() {
    if (_lp.volumesLoading || _lp.volumesLoaded) return;
    _lp.volumesLoading = true;
    var uniq = {};
    eachOffer(function (st, o) {
      if (o.type_id) uniq[o.type_id] = 1;
      (o.required_items || []).forEach(function (ri) { if (ri.type_id) uniq[ri.type_id] = 1; });
    });
    var list = Object.keys(uniq);
    if (!list.length) { _lp.volumesLoading = false; _lp.volumesLoaded = true; return; }
    var chunks = [];
    for (var i = 0; i < list.length; i += 200) chunks.push(list.slice(i, i + 200));
    var left = chunks.length;
    chunks.forEach(function (c) {
      apiGet('lp.php?action=volumes&ids=' + c.join(','), function (res) {
        if (res && res.volumes) {
          Object.keys(res.volumes).forEach(function (k) { _lp.volumes[k] = res.volumes[k]; });
        }
        if (--left === 0) {
          _lp.volumesLoading = false;
          _lp.volumesLoaded = true;
          if (_lp.loaded && _lp.depthRegion === _lp.region) scheduleRender();
        }
      });
    });
  }

  function loadContracts(force) {
    if (_lp.contractsLoading) return;
    _lp.contractsLoading = true;
    apiGet('lp.php?action=contracts&region=' + _lp.region + (force ? '&force=1' : ''), function (res) {
      _lp.contractsLoading = false;
      if (res && res.contracts) {
        _lp.contracts = res.contracts;
        _lp.contractsRegion = _lp.region;
        if (_lp.loaded && _lp.depthRegion === _lp.region) scheduleRender();
      }
    });
  }

  var _depthCallbacks = [];
  function _flushDepthCallbacks() {
    var cbs = _depthCallbacks;
    _depthCallbacks = [];
    if (cbs.length) cbs.forEach(function (cb) { cb(); });
    else render();
  }

  function loadDepths(force, done) {
    if (typeof done === 'function') _depthCallbacks.push(done);
    if (_lp.depthLoading) return;
    _lp.depthLoading = true;
    var uniq = {};
    eachOffer(function (st, o) {
      if (o.type_id) uniq[o.type_id] = 1;
      (o.required_items || []).forEach(function (ri) { if (ri.type_id) uniq[ri.type_id] = 1; });
    });
    var list = Object.keys(uniq);
    if (!list.length) {
      _lp.depthLoading = false;
      _lp.depthRegion = _lp.region;
      _flushDepthCallbacks();
      return;
    }
    var chunks = [];
    for (var i = 0; i < list.length; i += 60) chunks.push(list.slice(i, i + 60));
    var sent = 0, idx = 0;
    var next = function () {
      if (idx >= chunks.length) {
        _lp.depthLoading = false;
        _lp.depthRegion = _lp.region;
        setHint('');
        _flushDepthCallbacks();
        return;
      }
      setHint('正在拉取站内行情… ' + sent + ' / ' + list.length);
      apiGet('lp.php?action=depths&region=' + _lp.region + '&station=' + _lp.station + '&ids=' + chunks[idx].join(',') + (force ? '&force=1' : ''), function (res) {
        if (res && res.depths) {
          Object.keys(res.depths).forEach(function (k) { _lp.depth[k] = res.depths[k]; });
        }
        sent += chunks[idx].length;
        idx++;
        next();
      });
    };
    next();
  }

  // -- 计算 --
  // 传了 onlyKeys 就只算这几条报价：材料刷新后局部重算，不用跑完整的 785 条
  function computeRows(onlyKeys) {
    var rows = [];
    LP_STORES.forEach(function (st) {
      (_lp.offers[st.key] || []).forEach(function (o) {
        if (!o.lp_cost || o.lp_cost <= 0) return;
        if (onlyKeys && !onlyKeys[st.key + ':' + o.offer_id]) return;

        var p = _lp.prices[o.type_id] || null;
        // 站内订单簿加载完就用站内价；未加载到时先用全区聚合价占位
        var ob = _lp.depth[o.type_id] || null;
        var buy = ob ? ((ob.buy && ob.buy.length) ? num(ob.buy[0].p) : 0) : (p ? num(p.b) : 0);
        var sell = ob ? ((ob.sell && ob.sell.length) ? num(ob.sell[0].p) : 0) : (p ? num(p.s) : 0);

        var amt = num(_lp.lp[st.key]);
        var key = st.key + ':' + o.offer_id;
        var lpTimes = amt > 0 ? Math.floor(amt / o.lp_cost) : 0;
        // 每行可以单独填「拟兑换次数」，不填就按上面输入的 LP 总量换算
        var qty = Math.floor(num(_lp.qty[key]));
        var times = qty > 0 ? qty : lpTimes;
        var batch = times > 0 ? times : 1;   // 材料按本次要换的总批数去买

        var mats = [];
        var matCost = 0;
        var matUnknown = false;
        var matShort = false;
        (o.required_items || []).forEach(function (ri) {
          var rp = _lp.prices[ri.type_id] || null;
          var best = rp ? (num(rp.s) || num(rp.b)) : 0;   // 兜底价：最低卖价，无卖单用最高收购价
          if (!best) matUnknown = true;
          var need = ri.quantity * batch;
          // 优先用完整价格档位（matlevels）；没拉到就退回前五挂卖单
          var lvl = _lp.matLevels[ri.type_id];
          var d5 = _lp.depth[ri.type_id] || null;
          var book = (lvl && lvl.lv && lvl.lv.length) ? lvl.lv : (d5 && d5.sell ? d5.sell : null);
          var unit = best, per;
          if (book && book.length) {
            // 按站内挂卖单从低到高逐档吃；有完整档位时就是真实吃单成本
            var swept = 0, rem = need, last = best;
            for (var k = 0; k < book.length && rem > 0; k++) {
              var take = Math.min(num(book[k].v), rem);
              swept += take * num(book[k].p);
              rem -= take;
              last = num(book[k].p);
            }
            if (rem > 0) { swept += rem * last; matShort = true; }   // 档位不够，尾部按最高可见价估
            per = swept / batch;
            unit = swept / need;
          } else {
            per = best * ri.quantity;
          }
          matCost += per;
          mats.push({ type_id: ri.type_id, quantity: ri.quantity, unit: unit, batchNeed: need });
        });

        var cost = num(o.isk_cost) + matCost;
        // 秒卖（吃收单）只扣销售税；挂卖还要再扣中介费和安全税
        var fSales = salesTaxPct() / 100;
        var fList = fSales + num(_lp.fee.broker) / 100 + num(_lp.fee.safe) / 100;
        var revNow = buy > 0 ? buy * o.quantity : null;
        var revList = sell > 0 ? sell * o.quantity : null;
        var profitNow = revNow === null ? null : revNow * (1 - fSales) - cost;
        var profitList = revList === null ? null : revList * (1 - fList) - cost;

        var volUnit = num(_lp.volumes[o.type_id]);
        var volTotal = volUnit * o.quantity;

        var hh = _lp.history[o.type_id] || null;
        var hVol = hh ? num(hh.v) : 0;
        var hAvg = hh ? num(hh.p) : 0;
        var hDays = (hh && hh.days) ? hh.days : [];

        // 吃满前五收单：次数受深度限制，输入 LP 后再受 LP 限制
        var dep = _lp.depth[o.type_id] || null;
        var ctr = (_lp.contracts[o.type_id] || []).filter(function (c) { return num(c.loc) === num(_lp.station); });
        var contractPerLp = null, contractGrossPerLp = null, contractProfit = null, totalContract = null;
        if (ctr.length && o.lp_cost > 0) {
          var cRev = num(ctr[0].unit) * o.quantity;
          contractProfit = cRev * (1 - fSales) - cost;
          contractPerLp = contractProfit / o.lp_cost;
          contractGrossPerLp = cRev / o.lp_cost;
          totalContract = times > 0 ? times * contractProfit : null;
        }
        var depLoaded = Object.prototype.hasOwnProperty.call(_lp.depth, o.type_id);
        var depQty = 0, depTimes = 0, depUse = 0, depProfit = null;
        if (dep && dep.buy && dep.buy.length) {
          for (var di = 0; di < dep.buy.length; di++) depQty += num(dep.buy[di].v);
          depTimes = Math.floor(depQty / o.quantity);
          if (depTimes > 0) {
            depUse = times > 0 ? Math.min(depTimes, times) : depTimes;
            var depUnits = depUse * o.quantity, depRev = 0, depRem = depUnits;
            for (var dj = 0; dj < dep.buy.length && depRem > 0; dj++) {
              var depTake = Math.min(num(dep.buy[dj].v), depRem);
              depRev += depTake * num(dep.buy[dj].p);
              depRem -= depTake;
            }
            depProfit = depRev * (1 - fSales) - depUse * cost;
          }
        }

        rows.push({
          store: st,
          offer: o,
          name: typeName(o.type_id),
          en: typeEn(o.type_id),
          isBp: isBlueprint(o.type_id),
          buy: buy,
          sell: sell,
          mats: mats,
          matCost: matCost,
          matUnknown: matUnknown,
          matShort: matShort,
          batch: batch,
          qty: qty,
          cost: cost,
          profitNow: profitNow,
          profitList: profitList,
          perLpNow: profitNow === null ? null : profitNow / o.lp_cost,
          perLpList: profitList === null ? null : profitList / o.lp_cost,
          grossPerLpNow: revNow === null ? null : revNow / o.lp_cost,
          grossPerLpList: revList === null ? null : revList / o.lp_cost,
          depLoaded: depLoaded,
          depQty: depQty,
          depTimes: depTimes,
          depUse: depUse,
          depProfit: depProfit,
          contracts: ctr,
          volUnit: volUnit,
          volTotal: volTotal,
          hVol: hVol,
          hAvg: hAvg,
          hDays: hDays,
          contractPerLp: contractPerLp,
          contractGrossPerLp: contractGrossPerLp,
          contractProfit: contractProfit,
          totalContract: totalContract,
          times: times,
          totalNow: profitNow === null ? null : times * profitNow,
          totalList: profitList === null ? null : times * profitList
        });
      });
    });
    return rows;
  }

  function filterRows(rows) {
    var q = _lp.q.trim().toLowerCase();
    return rows.filter(function (r) {
      if (_lp.faction !== 'all' && r.store.key !== _lp.faction) return false;
      if (_lp.onlyMarket && !(r.buy > 0 || r.sell > 0 || r.contracts.length)) return false;
      if (_lp.onlyContract && !r.contracts.length) return false;
      if (_lp.hideBp && r.isBp && !r.contracts.length) return false;
      if (_lp.noReq && (r.offer.required_items || []).length) return false;
      if (q) {
        var hay = (r.name + ' ' + r.en + ' ' + r.offer.type_id).toLowerCase();
        if (hay.indexOf(q) === -1) return false;
      }
      return true;
    });
  }

  function sortRows(rows) {
    var key = _lp.sort;
    var dir = (_lp.sortDir === 'asc') ? 1 : -1;
    function val(r) {
      if (key === 'list') return r.perLpList;
      if (key === 'contract') return r.contractPerLp;
      if (key === 'profit') return r.profitNow;
      if (key === 'total') return r.totalNow;
      if (key === 'lp' || key === 'cost') return r.offer.lp_cost;
      if (key === 'name') return r.name;
      if (key === 'sell') return r.sell > 0 ? r.sell : null;
      if (key === 'buy') return r.buy > 0 ? r.buy : null;
      if (key === 'depth') return r.depProfit;
      if (key === 'vol') return r.volTotal > 0 ? r.volTotal : null;
      if (key === 'h48') return r.hVol > 0 ? r.hVol : null;
      return r.perLpNow;
    }
    rows.sort(function (a, b) {
      if (key === 'name') return dir * String(val(a)).localeCompare(String(val(b)), 'zh');
      var x = val(a), y = val(b);
      var xn = (x === null || x === undefined || !isFinite(x));
      var yn = (y === null || y === undefined || !isFinite(y));
      if (xn && yn) return 0;
      if (xn) return 1;   // 没数据的永远排最后
      if (yn) return -1;
      return dir * (x - y);
    });
    return rows;
  }

  // -- 渲染 --
  function render(reuse) {
    if (!$('lpBody') || !_lp.loaded) return;
    syncStickyTop();
    // reuse=true：排序/翻页直接复用上次算好的行——这两件事不改变任何计算值，
    // 重算 785 条纯属浪费（6 倍降速下实测就是 ~500ms 的卡顿）。
    var all = (reuse && _lp.rows.length) ? _lp.rows : computeRows();
    _lp.rows = all;
    var rows = sortRows(filterRows(all));

    var cnt = $('lpCnt');
    if (cnt) cnt.textContent = rows.length + ' / ' + all.length + ' 条';

    // 分页：只渲染当前页。行数降下来之后，排版 + 绘制的开销跟着成比例下降
    var size = _lp.pageSize;
    var pages = size > 0 ? Math.max(1, Math.ceil(rows.length / size)) : 1;
    if (_lp.page > pages) _lp.page = pages;
    if (_lp.page < 1) _lp.page = 1;
    var view = size > 0 ? rows.slice((_lp.page - 1) * size, _lp.page * size) : rows;

    var html = '<div class="lp-sumwrap">' + summaryHtml(all) + '</div>';
    html += '<div class="tbl-wrap">' + tableHtml(view) + '</div>';
    html += pagerHtml(rows.length, pages);
    html += '<p class="lp-note">所有价格（最高收购价 / 最低出售价 / 材料成本 / 前五收单深度 / 合同）都只取顶部所选空间站内的订单，不跨站；站内订单簿没加载完之前不显示价格，避免混入全区价。秒卖 = 卖给站内最高收购价，扣『销售税』；挂卖 = 挂站内最低出售价，扣『销售税 + 中介费 + 安全税』；毛 ISK/LP = 卖出收入 ÷ LP（税前、不扣任何成本）；前五秒卖 = 按站内前五收购单从高到低吃掉这些量，可兑换的次数与净收益（按秒卖费率）；合同 = 站内公开合同里该物品最便宜的单物品合同单价（每小时从 everef 快照重建）。站内没有市场挂单的物品（主要是蓝图拷贝）会在「秒卖收益」列用青色「合同」标签给出合同口径的 ISK/LP。材料成本按站内挂卖单从低到高逐档吃单算；每行可以填「拟兑换次数」（留空则按 LP 数量换算），填了会去拉该材料在站内的完整卖单档位（不只是前五档），所以大批量兑换给出的是真实吃单成本；档位吃不满时尾部按最高可见价估，并在材料后面标 !。销售税按 Accounting 等级自动算（7.5% × (1 − 11% × 等级)，默认 4 级 = 4.20%）；中介费 1.8%、安全税 0 可手动改。LP 商店报价 24 小时缓存，站内行情与前五深度 15 分钟缓存；站内订单簿首次加载需要十几秒。</p>';
    renderBody(html);
  }

  function summaryHtml(all) {
    var market = all.filter(function (r) { return r.buy > 0 || r.sell > 0; }).length;
    var html = '<div class="lp-sum">'
      + '<span>共 <b>' + all.length + '</b> 条报价</span>'
      + '<span>有行情 <b>' + market + '</b></span>';
    if (_lp.pricesAt) html += '<span>行情缓存 <b>' + ago(_lp.pricesAt) + '</b></span>';
    html += '</div>';

    LP_STORES.forEach(function (st) {
      var pool = all.filter(function (r) { return r.store.key === st.key && isFinite(r.perLpNow) && r.perLpNow !== null; });
      if (!pool.length) return;
      pool.sort(function (a, b) { return b.perLpNow - a.perLpNow; });

      var amt = num(_lp.lp[st.key]);
      var top = pool[0];
      html += '<div class="lp-best lp-best-' + st.key.toLowerCase() + '">'
        + '<span class="lp-best-tag">' + st.short + '</span>';
      if (amt > 0) {
        html += '<span>最优 <b>' + escHtml(top.name) + '</b>'
          + ' · 每 LP <b>' + perLp(top.perLpNow) + '</b>'
          + ' · 可换 <b>' + iskN(top.times) + '</b> 次'
          + ' · 秒卖总收益 <b>' + iskShort(top.totalNow) + '</b></span>';
      } else {
        html += '<span>最优 <b>' + escHtml(top.name) + '</b> · 每 LP <b>' + perLp(top.perLpNow) + '</b></span>';
      }
      var rest = pool.slice(1, 3).map(function (r) { return escHtml(r.name) + ' ' + perLp(r.perLpNow); });
      if (rest.length) html += '<span class="lp-best-rest">次选：' + rest.join(' / ') + '</span>';
      html += '</div>';
    });
    return html;
  }

  // 表头上的排序箭头（只有当前排序列显示）
  function sortArrow(key) {
    if (_lp.sort !== key) return '<span class="lp-sort-ar"></span>';
    return '<span class="lp-sort-ar on">' + (_lp.sortDir === 'asc' ? '▲' : '▼') + '</span>';
  }

  function tableHtml(rows) {
    if (!rows.length) return '<div class="lp-empty">没有符合条件的报价</div>';
    var head = '<thead><tr>'
      + '<th class="lp-th-item lp-sortable" data-sort="name">物品' + sortArrow('name') + '</th>'
      + '<th class="num lp-th-cost lp-sortable" data-sort="lp">兑换成本' + sortArrow('lp') + '</th>'
      + '<th class="num lp-th-market lp-sortable" data-sort="sell">市场 / 产出' + sortArrow('sell') + '</th>'
      + '<th class="num lp-th-gain lp-sortable" data-sort="now">秒卖收益' + sortArrow('now') + '</th>'
      + '<th class="num lp-th-gain lp-sortable" data-sort="list">挂卖收益' + sortArrow('list') + '</th>'
      + '<th class="num lp-th-depth lp-sortable" data-sort="depth">前五秒卖' + sortArrow('depth') + '</th>'
      + '<th class="num lp-th-vol lp-sortable" data-sort="vol">体积 m³' + sortArrow('vol') + '</th>'
      + '<th class="num lp-th-h48 lp-sortable" data-sort="h48">48h 量/均价' + sortArrow('h48') + '</th>'
      + '<th class="num lp-th-total lp-sortable" data-sort="total">可兑总收益' + sortArrow('total') + '</th>'
      + '</tr></thead>';
    return '<table class="ship-tbl lp-tbl">' + head + '<tbody>'
      + rows.map(rowHtml).join('') + '</tbody></table>';
  }

  // 分页条：每页条数可选 + 首页/上一页/下一页/末页
  function pagerHtml(total, pages) {
    var size = _lp.pageSize;
    var sizes = [10, 20, 30, 50];
    var opts = sizes.map(function (n) {
      return '<option value="' + n + '"' + (size === n ? ' selected' : '') + '>' + n + '</option>';
    }).join('');

    var html = '<div class="lp-pager">'
      + '<label>每页 <select id="lpPageSize" onchange="lpSetPageSize(this.value)">' + opts + '</select> 条</label>';
    if (size > 0 && pages > 1) {
      var prevOff = _lp.page <= 1 ? 'disabled ' : '';
      var nextOff = _lp.page >= pages ? 'disabled ' : '';
      html += '<span class="lp-page-btns">'
        + '<button type="button" ' + prevOff + 'onclick="lpSetPage(1)">首页</button>'
        + '<button type="button" ' + prevOff + 'onclick="lpSetPage(' + (_lp.page - 1) + ')">上一页</button>'
        + '<span class="lp-page-cur">第 <b>' + _lp.page + '</b> / ' + pages + ' 页</span>'
        + '<button type="button" ' + nextOff + 'onclick="lpSetPage(' + (_lp.page + 1) + ')">下一页</button>'
        + '<button type="button" ' + nextOff + 'onclick="lpSetPage(' + pages + ')">末页</button>'
        + '</span>';
    }
    html += '<span class="lp-page-info">共 <b>' + total + '</b> 条</span></div>';
    return html;
  }

  function matTitle(r) {
    if (!r.mats.length) return '';
    return r.mats.map(function (m) {
      return typeName(m.type_id) + ' x' + m.quantity + ' @ 均价 ' + iskN(m.unit) + '（本批共买 ' + iskN(m.batchNeed) + '）';
    }).join('\n');
  }

  // 48h 历史的悬停明细（区域级、按天）
  function hTitle(r) {
    var head = 'ESI 市场历史（区域级，不是单个空间站）';
    if (!r.hDays || !r.hDays.length) return head + '\n暂无可用量';
    return head + '\n' + r.hDays.map(function (d) {
      return d.d + '：量 ' + iskN(d.v) + '，均价 ' + iskShort(d.a) + '（高 ' + iskShort(d.h) + ' / 低 ' + iskShort(d.l) + '）';
    }).join('\n');
  }

  function rowHtml(r) {
    var o = r.offer;
    var key = r.store.key + ':' + o.offer_id;
    var open = !!_lp.expanded[key];
    var cls = 'lp-row' + (open ? ' open' : '');

    var matLine = r.mats.length
      ? '<span class="lp-copy-mat" data-lp-mat="' + escHtml(key) + '" title="点击复制材料清单（Multi-Buy）&#10;' + escHtml(matTitle(r)) + '">'
        + r.mats.length + ' 项 ' + iskShort(r.matCost) + '</span>'
        + (r.matUnknown ? ' <span class="lp-warn" title="有材料查不到行情，成本偏低">?</span>' : '')
        + (r.matShort ? ' <span class="lp-warn" title="站内挂卖档位不够，超出部分按最高可见价估，实际成本会更高">!</span>' : '')
        + '<button type="button" class="lp-mat-refresh" data-lp-matrefresh="' + escHtml(key) + '" title="刷新这几项材料的最新价格">' + LP_REFRESH_ICON + '</button>'
      : '—';

    var amt = num(_lp.lp[r.store.key]);
    var totalVal = r.totalNow !== null ? r.totalNow : r.totalContract;
    var totalCell = amt > 0
      ? (totalVal === null ? '—' : '<div class="lp-val lp-strong">' + iskShort(totalVal) + '</div><div class="lp-sub">' + iskN(r.times) + ' 次</div>')
      : '<span class="lp-muted">填 LP</span>';

    // 秒卖：优先用市场最高收购价；只有合同价的物品（BPC）回退到合同口径
    var nowCell = (r.profitNow !== null)
      ? '<div class="lp-val lp-strong">净 ' + perLp(r.perLpNow) + ' <span class="lp-unit">ISK/LP</span></div>'
        + '<div class="lp-sub">毛 ' + perLp(r.grossPerLpNow) + ' <span class="lp-unit">ISK/LP</span></div>'
        + '<div class="lp-sub' + (num(r.profitNow) < 0 ? ' lp-sub-neg' : '') + '">单次 ' + iskShort(r.profitNow) + '</div>'
      : (r.contractPerLp !== null
        ? '<div class="lp-val lp-strong"><span class="lp-contract-tag">合同</span>' + perLp(r.contractPerLp) + ' <span class="lp-unit">ISK/LP</span></div>'
          + '<div class="lp-sub">毛 ' + perLp(r.contractGrossPerLp) + ' <span class="lp-unit">ISK/LP</span></div>'
          + '<div class="lp-sub' + (num(r.contractProfit) < 0 ? ' lp-sub-neg' : '') + '">单次 ' + iskShort(r.contractProfit) + '</div>'
        : '<div class="lp-val">净 — <span class="lp-unit">ISK/LP</span></div>'
          + '<div class="lp-sub">毛 — <span class="lp-unit">ISK/LP</span></div>'
          + '<div class="lp-sub">单次 —</div>');

    var depthTitle = r.depUse < r.depTimes
      ? '前五收单最多可吃 ' + iskN(r.depTimes) + ' 次，这里受你输入的 LP 限制'
      : '前五收单合计 ' + iskN(r.depQty) + ' 个，可吃满 ' + iskN(r.depTimes) + ' 次';
    var depthCell = !r.depLoaded
      ? '<span class="lp-muted">…</span>'
      : (!r.depTimes
        ? '<span class="lp-muted">—</span>'
        : '<div class="lp-val" title="' + escHtml(depthTitle) + '">' + iskN(r.depUse) + ' 次</div>'
          + '<div class="lp-sub' + (num(r.depProfit) < 0 ? ' lp-sub-neg' : '') + '">' + (r.depProfit === null ? '—' : iskShort(r.depProfit)) + '</div>');

    var html = '<tr class="' + cls + '" data-lp-key="' + escHtml(key) + '" data-lp-type="' + o.type_id + '">'
      + '<td class="lp-c-item"><div class="lp-name">'
        + '<span class="lp-tag lp-tag-' + r.store.key.toLowerCase() + '">' + r.store.key + '</span>'
        + escHtml(r.name) + (r.isBp ? ' <span class="lp-mini">蓝图</span>' : '') + '</div>'
        + '<button type="button" class="lp-name-copy" data-lp-name="' + escHtml(r.name) + '" title="复制物品名">' + LP_COPY_ICON + '</button>' + '</div>'
        + (r.en ? '<div class="lp-en">' + escHtml(r.en) + '</div>' : '') + '</td>'
      + '<td class="num lp-c-cost">'
        + '<div><span class="lp-k">LP</span>' + iskN(o.lp_cost) + '</div>'
        + '<div><span class="lp-k">ISK</span>' + iskShort(o.isk_cost) + '</div>'
        + '<div><span class="lp-k">材料</span>' + matLine + '</div>'
        + '<div class="lp-qty"><span class="lp-k">次数</span>'
          + '<input type="number" min="1" step="1" inputmode="numeric" data-lp-qty="' + escHtml(key) + '"'
            + ' value="' + (r.qty > 0 ? r.qty : '') + '" placeholder="' + r.batch + '"'
            + ' title="拟兑换次数：材料成本按这个数量从低到高逐档吃单估算；留空则按 LP 数量换算"></div>'
      + '</td>'
      + '<td class="num lp-c-market">'
        + '<div><span class="lp-k">收</span>' + (r.buy > 0 ? iskShort(r.buy) : '—') + '</div>'
        + '<div><span class="lp-k">售</span>' + (r.sell > 0 ? iskShort(r.sell) : '—') + '</div>'
        + '<div><span class="lp-k">换得</span>x' + iskN(o.quantity) + '</div>'
        + (r.contracts.length ? '<div class="lp-contract"><span class="lp-k">合同</span>' + iskShort(r.contracts[0].unit) + '</div>' : '')
      + '</td>'
      + '<td class="num lp-c-gain">' + nowCell + '</td>'
      + '<td class="num lp-c-gain">'
        + '<div class="lp-val">净 ' + perLp(r.perLpList) + ' <span class="lp-unit">ISK/LP</span></div>'
        + '<div class="lp-sub">毛 ' + perLp(r.grossPerLpList) + ' <span class="lp-unit">ISK/LP</span></div>'
        + '<div class="lp-sub">单次 ' + (r.profitList === null ? '—' : iskShort(r.profitList)) + '</div>'
      + '</td>'
      + '<td class="num lp-c-depth">' + depthCell + '</td>'
      + '<td class="num lp-c-vol" title="单个 ' + volFmt(r.volUnit) + ' m³"><div class="lp-val">' + volFmt(r.volTotal) + '</div><div class="lp-sub">单个 ' + volFmt(r.volUnit) + '</div></td>'
      + '<td class="num lp-c-h48" title="' + escHtml(hTitle(r)) + '"><div class="lp-val">' + (r.hVol > 0 ? iskN(r.hVol) : '—') + '</div><div class="lp-sub">' + (r.hAvg > 0 ? iskShort(r.hAvg) : '') + '</div></td>'
      + '<td class="num lp-c-total">' + totalCell + '</td>'
      + '</tr>';

    if (open) html += detailRowHtml(r);
    return html;
  }

  // 明细行单独抽出来：展开/收起时只插/删这一行，不重绘整张表
  function detailRowHtml(r) {
    var o = r.offer;
    var key = r.store.key + ':' + o.offer_id;
    return '<tr class="lp-detail-row" data-lp-detail="' + escHtml(key) + '"><td colspan="9">'
      + '<div class="lp-detail">'
      + '<div class="lp-detail-bar"><button type="button" class="lp-copy-btn" data-lp-mat="' + escHtml(key) + '">复制材料清单(Multi-Buy)</button>'
        + '<button type="button" class="lp-copy-btn lp-book-refresh" data-lp-bookrefresh="' + o.type_id + '" title="重新拉取这个物品在 ' + escHtml(stationLabel()) + ' 的买卖前五（绕过 15 分钟缓存，约 1 秒）">' + LP_REFRESH_ICON + '<span class="lp-book-refresh-label">刷新挂单前五</span></button></div>'
      + contractBlockHtml(r)
      + '<div data-lp-det="' + o.type_id + '">' + detailHtml(o.type_id) + '</div>'
      + '</div></td></tr>';
  }

  // 展开面板里的公开合同块：列出该物品最便宜的单物品合同，并算合同口径的净 ISK/LP
  function contractBlockHtml(r) {
    var list = r.contracts || [];
    if (!list.length) return '';
    var html = '<div class="lp-book lp-contract-block"><h5>公开合同（单物品，最低单价优先）</h5>'
      + '<table class="lp-book-tbl"><thead><tr>'
      + '<th>单价</th><th>合同价</th><th>数量</th><th>说明</th><th>地点</th><th>到期</th>'
      + '</tr></thead><tbody>';
    list.forEach(function (c) {
      var note = c.bpc ? ('BPC ' + iskN(c.runs) + ' run · ME' + iskN(c.me) + ' TE' + iskN(c.te)) : '成品';
      html += '<tr><td class="num">' + iskN(c.unit) + '</td>'
        + '<td class="num">' + iskN(c.price) + '</td>'
        + '<td class="num">' + iskN(c.qty) + '</td>'
        + '<td>' + escHtml(note) + '</td>'
        + '<td>' + escHtml(c.loc_name || ('#' + c.loc)) + '</td>'
        + '<td>' + escHtml(String(c.exp || '').replace('T', ' ').replace('Z', '')) + '</td></tr>';
    });
    html += '</tbody></table>';
    var c0 = list[0];
    var rev = num(c0.unit) * r.offer.quantity;
    var profit = rev * (1 - salesTaxPct() / 100) - r.cost;
    var per = r.offer.lp_cost > 0 ? profit / r.offer.lp_cost : null;
    html += '<div class="lp-detail-msg">按最低合同单价：毛收入 ' + iskShort(rev)
      + '，扣 ' + salesTaxPct().toFixed(2) + '% 销售税与 ISK/材料成本后净收益 ' + iskShort(profit)
      + '，折合 <b>' + perLp(per) + ' ISK/LP</b></div>';
    return html + '</div>';
  }

  function detailHtml(typeId) {
    var b = _lp.books[typeId];
    var meta = bookMetaHtml(typeId);
    if (!b) return meta + '<div class="lp-detail-msg">正在拉取挂单前五…</div>';
    if (b.error) {
      return meta + '<div class="lp-detail-msg">挂单拉取失败 '
        + '<a href="#" onclick="lpReloadBook(' + typeId + ');return false">重试</a></div>';
    }
    return meta + '<div class="lp-book-cols">'
      + bookHtml('收购单前五（价格从高到低）', b.buy, b.stations)
      + bookHtml('出售单前五（价格从低到高）', b.sell, b.stations)
      + '</div>';
  }

  // ESI 全区订单簿单次要 0.8 秒上下，把抓取时间和耗时摆出来，方便判断刷新到底有没有生效
  function bookMetaHtml(typeId) {
    var m = _lp.bookMeta[typeId];
    if (!m) return '';
    return '<div class="lp-detail-msg lp-book-meta">挂单拉取于 ' + clock(m.at)
      + (m.ms ? ' · 用时 ' + (m.ms / 1000).toFixed(1) + 's' : '') + '</div>';
  }

  function bookHtml(title, list, stations) {
    if (!list || !list.length) {
      return '<div class="lp-book"><h5>' + title + '</h5><div class="lp-detail-msg">暂无挂单</div></div>';
    }
    var rows = list.map(function (r) {
      var loc = '';
      if (r.loc >= 1000000000000) loc = '玩家建筑';
      else if (stations && stations[r.loc]) loc = stations[r.loc];
      else loc = '空间站 #' + r.loc;
      return '<tr><td class="num">' + iskN(r.p) + '</td>'
        + '<td class="num">' + iskN(r.v) + '</td>'
        + '<td class="num">' + (r.min > 1 ? iskN(r.min) : '—') + '</td>'
        + '<td>' + escHtml(loc) + '</td></tr>';
    }).join('');
    return '<div class="lp-book"><h5>' + title + '</h5>'
      + '<table class="lp-book-tbl"><thead><tr>'
      + '<th>价格</th><th>数量</th><th>最小</th><th>地点</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
  }

  // -- 交互 --
  function renderShell() {
    var st = $('lpStation');
    if (st && !st.options.length) {
      LP_STATIONS.forEach(function (s) {
        var o = document.createElement('option');
        o.value = s.id;
        o.textContent = s.label;
        st.appendChild(o);
      });
      st.value = String(_lp.station);
    }
  }

  function lpInit() {
    renderShell();
    if (_lp.loaded) { render(); return; }
    if (_lp.loading) return;
    start(false);
  }

  function lpReload(force) {
    if (_lp.loading) return;
    if (!Object.keys(_lp.offers).length) { start(force); return; }
    _lp.loading = true;
    if (force) { _lp.depth = {}; _lp.depthRegion = 0; }
    _lp.forceDepth = !!force;
    loadPrices(force);
  }

  function lpReloadBook(typeId) {
    delete _lp.books[typeId];
    var cells = document.querySelectorAll('[data-lp-det="' + typeId + '"]');
    for (var i = 0; i < cells.length; i++) cells[i].innerHTML = detailHtml(typeId);
    loadBook(typeId, true);
  }

  function bookCells(typeId) {
    return document.querySelectorAll('[data-lp-det="' + typeId + '"]');
  }

  function paintBook(typeId) {
    var cells = bookCells(typeId);
    for (var i = 0; i < cells.length; i++) {
      cells[i].classList.remove('lp-refreshing');
      cells[i].innerHTML = detailHtml(typeId);
    }
  }

  function bookBtnText(btn, text) {
    var span = btn.querySelector ? btn.querySelector('.lp-book-refresh-label') : null;
    if (span) span.textContent = text;
    else btn.textContent = text;
  }

  // 展开面板里按物品强刷站内买卖前五。
  // 等待期间只动表格外的 #lpHint：758 行的表，格子里改一个字都要整表重排 + 重绘，
  // 弱机器上会直接卡成“浏览器无响应”。旋转图标和变暗效果也一并去掉。
  function refreshBook(typeId, btn) {
    if (_lp.bookBusy[typeId]) return;
    _lp.bookBusy[typeId] = 1;
    var t0 = Date.now();
    var nm = typeName(typeId);
    if (btn) bookBtnText(btn, '刷新中…');
    setHint('正在刷新「' + nm + '」挂单前五…');
    var tick = setInterval(function () {
      setHint('正在刷新「' + nm + '」挂单前五… ' + Math.round((Date.now() - t0) / 1000) + 's');
    }, 1000);
    apiGet('lp.php?action=book&region=' + _lp.region + '&station=' + _lp.station + '&type_id=' + typeId + '&force=1', function (res) {
      clearInterval(tick);
      delete _lp.bookBusy[typeId];
      var ms = Date.now() - t0;
      var ok = !!(res && (res.buy || res.sell));
      _lp.books[typeId] = ok ? res : { error: 1 };
      if (ok) _lp.bookMeta[typeId] = { at: res.cachedAt ? res.cachedAt * 1000 : Date.now(), ms: ms };
      paintBook(typeId);
      if (btn) {
        bookBtnText(btn, ok ? '已更新' : '刷新失败，点这里重试');
        setTimeout(function () { bookBtnText(btn, '刷新挂单前五'); }, 2400);
      }
      setHint(ok ? ('「' + nm + '」挂单前五已更新 · 用时 ' + (ms / 1000).toFixed(1) + 's') : ('「' + nm + '」挂单前五刷新失败'));
      setTimeout(function () { if (!_lp.bookBusy[typeId]) setHint(''); }, 4000);
    });
  }

  function loadBook(typeId, force) {
    if (_lp.bookBusy[typeId]) return;
    _lp.bookBusy[typeId] = 1;
    apiGet('lp.php?action=book&region=' + _lp.region + '&station=' + _lp.station + '&type_id=' + typeId + (force ? '&force=1' : ''), function (res) {
      delete _lp.bookBusy[typeId];
      var ok = !!(res && (res.buy || res.sell));
      _lp.books[typeId] = ok ? res : { error: 1 };
      if (ok && res.cachedAt) _lp.bookMeta[typeId] = { at: res.cachedAt * 1000, ms: 0 };
      paintBook(typeId);
    });
  }

  function lpSetRegion(v) {
    var id = parseInt(v, 10);
    if (!id || id === _lp.region) return;
    _lp.region = id;
    _lp.prices = {};
    _lp.books = {};
    _lp.matLevels = {};
    _lp.bookBusy = {};
    _lp.matBusy = {};
    _lp.bookMeta = {};
    _lp.depth = {};
    _lp.depthRegion = 0;
    _lp.contracts = {};
    _lp.contractsRegion = 0;
    _lp.history = {};
    _lp.historyLoaded = false;
    _lp.pricesAt = 0;
    lpReload(false);
  }

  // 切换空间站（同时切区域，价格全部按站内算）
  function lpSetStation(v) {
    var id = parseInt(v, 10);
    var st = null;
    LP_STATIONS.forEach(function (s) { if (s.id === id) st = s; });
    if (!st || id === _lp.station) return;
    _lp.station = id;
    _lp.region = st.region;
    _lp.prices = {};
    _lp.books = {};
    _lp.matLevels = {};
    _lp.bookBusy = {};
    _lp.matBusy = {};
    _lp.bookMeta = {};
    _lp.depth = {};
    _lp.depthRegion = 0;
    _lp.contracts = {};
    _lp.contractsRegion = 0;
    _lp.pricesAt = 0;
    lpReload(false);
  }

  function lpSetLp(key, v) {
    _lp.lp[key] = v;
    scheduleRender();
  }

  // 费率输入（销售税 / 中介费 / 安全税，单位 %）
  function lpSetFee(k, v) {
    if (!_lp.fee || !Object.prototype.hasOwnProperty.call(_lp.fee, k)) return;
    var n = parseFloat(v);
    _lp.fee[k] = (isFinite(n) && n >= 0) ? n : 0;
    scheduleRender();
  }

  // 销售税改成选 Accounting 等级，不用手输税率
  function lpSetTaxLevel(v) {
    var n = parseInt(v, 10);
    _lp.fee.taxLevel = (isFinite(n) && n >= 0 && n <= 5) ? n : 4;
    scheduleRender();
  }

  // 填拟兑换次数：只影响本行。先用现有数据算一遍，再去补齐全量卖单档位后重算一次。
  function lpSetQty(key, v) {
    var n = parseInt(v, 10);
    if (isFinite(n) && n > 0) _lp.qty[key] = n; else delete _lp.qty[key];
    var one = {};
    one[key] = 1;
    var o = findOffer(key);
    var ids = [];
    if (o && o.required_items) o.required_items.forEach(function (ri) { ids.push(ri.type_id); });
    recomputeOffers(one);
    if (ids.length) ensureMatLevels(ids, function () { recomputeOffers(one); });
  }

  function lpSetFaction(v) {
    _lp.faction = v;
    _lp.page = 1;
    var box = $('lpFaction');
    if (box) {
      Array.prototype.forEach.call(box.querySelectorAll('button'), function (b) {
        b.classList.toggle('on', b.getAttribute('data-f') === v);
      });
    }
    render(true);
  }

  function lpSetSort(v) { _lp.sort = v; _lp.sortDir = LP_SORT_DIR[v] || 'desc'; _lp.page = 1; render(true); }
  function lpSetSearch(v) { _lp.q = v || ''; _lp.page = 1; scheduleRender(true); }

  function lpSetPageSize(v) {
    var n = parseInt(v, 10);
    if (!isFinite(n) || n <= 0) n = 20;
    _lp.pageSize = n > 0 ? n : 0;
    _lp.page = 1;
    render(true);
  }

  // 翻页：重绘后回到表格顶部（顶栏吸顶，要把它让出来）
  function lpSetPage(p) {
    var n = parseInt(p, 10);
    if (!isFinite(n) || n < 1 || n === _lp.page) return;
    _lp.page = n;
    render(true);
    var wrap = document.querySelector('#lpBody .tbl-wrap');
    if (!wrap) return;
    var top = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--lp-sticky-top'), 10) || 56;
    window.scrollTo(0, Math.max(0, wrap.getBoundingClientRect().top + window.pageYOffset - top - 8));
  }
  function lpSetFlag(k, on) {
    if (k === 'onlyMarket') _lp.onlyMarket = !!on;
    if (k === 'onlyContract') _lp.onlyContract = !!on;
    if (k === 'hideBp') _lp.hideBp = !!on;
    if (k === 'noReq') _lp.noReq = !!on;
    _lp.page = 1;
    render(true);
  }

  // 一行点击 = 展开/收起；展开后首次按需拉挂单前五
  // 「拟兑换次数」失去焦点 / 回车时生效（不边打字边重绘，避免输入框被重建后丢焦点）
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var q = t.closest('[data-lp-qty]');
    if (q && q.closest('#pg-lp')) lpSetQty(q.getAttribute('data-lp-qty'), q.value);
  });

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    // 「拟兑换次数」输入框：走浏览器默认行为（聚焦/输入），不要当成行点击
    if (t.closest('[data-lp-qty]')) return;
    // 点表头排序：同一列再点一下切换升降序
    var th = t.closest('#pg-lp thead th[data-sort]');
    if (th) {
      e.preventDefault();
      var sk = th.getAttribute('data-sort');
      if (_lp.sort === sk) _lp.sortDir = (_lp.sortDir === 'asc' ? 'desc' : 'asc');
      else { _lp.sort = sk; _lp.sortDir = LP_SORT_DIR[sk] || 'desc'; }
      var sel = $('lpSort');
      if (sel) {
        var hasOpt = false;
        for (var si = 0; si < sel.options.length; si++) { if (sel.options[si].value === sk) hasOpt = true; }
        if (hasOpt) sel.value = sk;
      }
      _lp.page = 1;
      render(true);
      return;
    }
    // 材料清单复制：拦在行展开之前
    var cp = t.closest('[data-lp-mat]');
    if (cp && cp.closest('#pg-lp')) {
      e.preventDefault();
      copyMaterialList(cp.getAttribute('data-lp-mat'), cp);
      return;
    }
    var cn = t.closest('[data-lp-name]');
    if (cn && cn.closest('#pg-lp')) {
      e.preventDefault();
      copyText(cn.getAttribute('data-lp-name'), function () { flashCopied(cn, '已复制!'); });
      return;
    }
    var mr = t.closest('[data-lp-matrefresh]');
    if (mr && mr.closest('#pg-lp')) {
      e.preventDefault();
      refreshMaterials(mr.getAttribute('data-lp-matrefresh'), mr);
      return;
    }
    var br = t.closest('[data-lp-bookrefresh]');
    if (br && br.closest('#pg-lp')) {
      e.preventDefault();
      refreshBook(parseInt(br.getAttribute('data-lp-bookrefresh'), 10), br);
      return;
    }
    var tr = t.closest('tr.lp-row');
    if (!tr || !tr.closest('#pg-lp')) return;
    var key = tr.getAttribute('data-lp-key');
    var typeId = parseInt(tr.getAttribute('data-lp-type'), 10);
    // 只动这一行：不再重建整张表（758 行重绘要 500ms+）
    if (_lp.expanded[key]) {
      delete _lp.expanded[key];
      tr.classList.remove('open');
      var nx = tr.nextElementSibling;
      if (nx && nx.classList.contains('lp-detail-row')) nx.parentNode.removeChild(nx);
    } else {
      var rowObj = findRowByKey(key);
      if (!rowObj) { render(); return; }
      _lp.expanded[key] = 1;
      tr.classList.add('open');
      tr.insertAdjacentHTML('afterend', detailRowHtml(rowObj));
      if (!_lp.books[typeId]) loadBook(typeId, false);
    }
  });

  // 暴露给 index.html 的页面切换与内联事件
  window.lpInit = lpInit;
  window.lpReload = function (force) { return lpReload(!!force); };
  window.lpReloadBook = lpReloadBook;
  window.lpSetRegion = lpSetRegion;
  window.lpSetStation = lpSetStation;
  window.lpSetLp = lpSetLp;
  window.lpSetFee = lpSetFee;
  window.lpSetTaxLevel = lpSetTaxLevel;
  window.lpSetFaction = lpSetFaction;
  window.lpSetSort = lpSetSort;
  window.lpSetSearch = lpSetSearch;
  window.lpSetFlag = lpSetFlag;
  window.lpSetPage = lpSetPage;
  window.lpSetPageSize = lpSetPageSize;
  window.addEventListener('resize', syncStickyTop);
  // 顶栏高度会随字体加载/换行变化，用 ResizeObserver 持续同步吸顶偏移
  (function watchTopbar() {
    var tb = document.querySelector('.topbar');
    if (!tb) return;
    if (window.ResizeObserver) new ResizeObserver(syncStickyTop).observe(tb);
    syncStickyTop();
  })();
})();
