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

  var _lp = {
    inited: false,
    loading: false,
    loaded: false,
    region: 10000002,
    offers: {},
    prices: {},
    esiNames: {},
    books: {},
    lp: { C: '', G: '' },
    faction: 'all',
    sort: 'now',
    q: '',
    onlyMarket: true,
    hideBp: true,
    noReq: false,
    expanded: {},
    rows: [],
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

  function iskN(v) {
    if (v === null || v === undefined || !isFinite(v)) return '—';
    return String(Math.round(v)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function iskShort(v) {
    if (typeof fmtIsk === 'function') return fmtIsk(v);
    if (v === null || v === undefined || !isFinite(v)) return '—';
    if (Math.abs(v) >= 1e9) return (v / 1e9).toFixed(2) + ' B';
    if (Math.abs(v) >= 1e6) return (v / 1e6).toFixed(2) + ' M';
    if (Math.abs(v) >= 1e3) return (v / 1e3).toFixed(1) + ' K';
    return String(Math.round(v));
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
  function scheduleRender() {
    if (_renderTimer) clearTimeout(_renderTimer);
    _renderTimer = setTimeout(function () { _renderTimer = null; render(); }, 150);
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
    setHint('');
    render();
  }

  // -- 计算 --
  function computeRows() {
    var rows = [];
    LP_STORES.forEach(function (st) {
      (_lp.offers[st.key] || []).forEach(function (o) {
        if (!o.lp_cost || o.lp_cost <= 0) return;

        var p = _lp.prices[o.type_id] || null;
        var buy = p ? num(p.b) : 0;    // 最高收购价
        var sell = p ? num(p.s) : 0;   // 最低出售价

        var mats = [];
        var matCost = 0;
        var matUnknown = false;
        (o.required_items || []).forEach(function (ri) {
          var rp = _lp.prices[ri.type_id] || null;
          var unit = rp ? (num(rp.s) || num(rp.b)) : 0;   // 买材料按最低卖价，无卖单用最高收购价兜底
          if (!unit) matUnknown = true;
          matCost += unit * ri.quantity;
          mats.push({ type_id: ri.type_id, quantity: ri.quantity, unit: unit });
        });

        var cost = num(o.isk_cost) + matCost;
        var revNow = buy > 0 ? buy * o.quantity : null;
        var revList = sell > 0 ? sell * o.quantity : null;
        var profitNow = revNow === null ? null : revNow - cost;
        var profitList = revList === null ? null : revList - cost;

        var amt = num(_lp.lp[st.key]);
        var times = amt > 0 ? Math.floor(amt / o.lp_cost) : 0;

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
          cost: cost,
          profitNow: profitNow,
          profitList: profitList,
          perLpNow: profitNow === null ? null : profitNow / o.lp_cost,
          perLpList: profitList === null ? null : profitList / o.lp_cost,
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
      if (_lp.onlyMarket && !(r.buy > 0 || r.sell > 0)) return false;
      if (_lp.hideBp && r.isBp) return false;
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
    function val(r) {
      if (key === 'list') return r.perLpList;
      if (key === 'profit') return r.profitNow;
      if (key === 'total') return r.totalNow;
      if (key === 'lp') return r.offer.lp_cost;
      if (key === 'name') return r.name;
      return r.perLpNow;
    }
    rows.sort(function (a, b) {
      if (key === 'name') return String(val(a)).localeCompare(String(val(b)), 'zh');
      if (key === 'lp') return num(val(a)) - num(val(b));
      var x = val(a), y = val(b);
      var xn = (x === null || x === undefined || !isFinite(x));
      var yn = (y === null || y === undefined || !isFinite(y));
      if (xn && yn) return 0;
      if (xn) return 1;
      if (yn) return -1;
      return y - x;
    });
    return rows;
  }

  // -- 渲染 --
  function render() {
    if (!$('lpBody') || !_lp.loaded) return;
    var all = computeRows();
    _lp.rows = all;
    var rows = sortRows(filterRows(all));

    var cnt = $('lpCnt');
    if (cnt) cnt.textContent = rows.length + ' / ' + all.length + ' 条';

    var html = summaryHtml(all);
    html += '<div class="tbl-wrap">' + tableHtml(rows) + '</div>';
    html += '<p class="lp-note">秒卖 = 卖给最高收购价；挂卖 = 挂最低出售价；均未计手续费和税，取欧服全区最优（可能含玩家建筑）。LP 商店报价 24 小时缓存，行情 15 分钟缓存，点行展开挂单前五。</p>';
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

  function tableHtml(rows) {
    if (!rows.length) return '<div class="lp-empty">没有符合条件的报价</div>';
    var head = '<thead><tr>'
      + '<th class="lp-th-item">物品</th>'
      + '<th>卫队</th>'
      + '<th class="num">LP 成本</th>'
      + '<th class="num">ISK 成本</th>'
      + '<th>材料</th>'
      + '<th class="num">换得</th>'
      + '<th class="num">最高收购</th>'
      + '<th class="num">最低出售</th>'
      + '<th class="num">秒卖 ISK/LP</th>'
      + '<th class="num">挂卖 ISK/LP</th>'
      + '<th class="num">秒卖单次收益</th>'
      + '<th class="num">可兑总收益</th>'
      + '</tr></thead>';
    return '<table class="ship-tbl lp-tbl">' + head + '<tbody>'
      + rows.map(rowHtml).join('') + '</tbody></table>';
  }

  function matTitle(r) {
    if (!r.mats.length) return '';
    return r.mats.map(function (m) {
      return typeName(m.type_id) + ' x' + m.quantity + ' @ ' + iskN(m.unit);
    }).join('\n');
  }

  function rowHtml(r) {
    var o = r.offer;
    var key = r.store.key + ':' + o.offer_id;
    var open = !!_lp.expanded[key];
    var cls = 'lp-row' + (open ? ' open' : '');

    var matCell = r.mats.length
      ? '<span title="' + escHtml(matTitle(r)) + '">' + r.mats.length + ' 项 · ' + iskShort(r.matCost) + '</span>'
        + (r.matUnknown ? ' <span class="lp-warn" title="有材料查不到行情，成本偏低">?</span>' : '')
      : '—';

    var amt = num(_lp.lp[r.store.key]);
    var totalCell = amt > 0
      ? (r.totalNow === null ? '—' : '<b>' + iskShort(r.totalNow) + '</b><br><span class="lp-sub">' + iskN(r.times) + ' 次</span>')
      : '<span class="lp-muted">填 LP</span>';

    var html = '<tr class="' + cls + '" data-lp-key="' + escHtml(key) + '" data-lp-type="' + o.type_id + '">'
      + '<td class="lp-c-item"><div class="cn">' + escHtml(r.name) + '</div>'
        + (r.en ? '<div class="en">' + escHtml(r.en) + '</div>' : '')
        + (r.isBp ? ' <span class="lp-mini">蓝图</span>' : '') + '</td>'
      + '<td><span class="lp-tag lp-tag-' + r.store.key.toLowerCase() + '">' + r.store.key + '</span></td>'
      + '<td class="num">' + iskN(o.lp_cost) + '</td>'
      + '<td class="num">' + iskShort(o.isk_cost) + '</td>'
      + '<td class="lp-c-mat">' + matCell + '</td>'
      + '<td class="num">x' + iskN(o.quantity) + '</td>'
      + '<td class="num">' + (r.buy > 0 ? iskShort(r.buy) : '—') + '</td>'
      + '<td class="num">' + (r.sell > 0 ? iskShort(r.sell) : '—') + '</td>'
      + '<td class="num lp-strong">' + perLp(r.perLpNow) + '</td>'
      + '<td class="num">' + perLp(r.perLpList) + '</td>'
      + '<td class="num ' + (num(r.profitNow) < 0 ? 'lp-neg' : '') + '">' + (r.profitNow === null ? '—' : iskShort(r.profitNow)) + '</td>'
      + '<td class="num">' + totalCell + '</td>'
      + '</tr>';

    if (open) {
      html += '<tr class="lp-detail-row"><td colspan="12">'
        + '<div class="lp-detail" data-lp-det="' + o.type_id + '">' + detailHtml(o.type_id) + '</div>'
        + '</td></tr>';
    }
    return html;
  }

  function detailHtml(typeId) {
    var b = _lp.books[typeId];
    if (!b) return '<div class="lp-detail-msg">正在拉取挂单前五…</div>';
    if (b.error) {
      return '<div class="lp-detail-msg">挂单拉取失败 '
        + '<a href="#" onclick="lpReloadBook(' + typeId + ');return false">重试</a></div>';
    }
    return '<div class="lp-book-cols">'
      + bookHtml('收购单前五（价格从高到低）', b.buy, b.stations)
      + bookHtml('出售单前五（价格从低到高）', b.sell, b.stations)
      + '</div>';
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
    var region = $('lpRegion');
    if (region && !region.options.length) {
      LP_REGIONS.forEach(function (r) {
        var o = document.createElement('option');
        o.value = r.id;
        o.textContent = r.label;
        region.appendChild(o);
      });
      region.value = String(_lp.region);
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
    loadPrices(force);
  }

  function lpReloadBook(typeId) {
    delete _lp.books[typeId];
    var cells = document.querySelectorAll('[data-lp-det="' + typeId + '"]');
    for (var i = 0; i < cells.length; i++) cells[i].innerHTML = detailHtml(typeId);
    loadBook(typeId, true);
  }

  function loadBook(typeId, force) {
    apiGet('lp.php?action=book&region=' + _lp.region + '&type_id=' + typeId + (force ? '&force=1' : ''), function (res) {
      _lp.books[typeId] = (res && (res.buy || res.sell)) ? res : { error: 1 };
      var cells = document.querySelectorAll('[data-lp-det="' + typeId + '"]');
      for (var i = 0; i < cells.length; i++) cells[i].innerHTML = detailHtml(typeId);
    });
  }

  function lpSetRegion(v) {
    var id = parseInt(v, 10);
    if (!id || id === _lp.region) return;
    _lp.region = id;
    _lp.prices = {};
    _lp.books = {};
    _lp.pricesAt = 0;
    lpReload(false);
  }

  function lpSetLp(key, v) {
    _lp.lp[key] = v;
    scheduleRender();
  }

  function lpSetFaction(v) {
    _lp.faction = v;
    var box = $('lpFaction');
    if (box) {
      Array.prototype.forEach.call(box.querySelectorAll('button'), function (b) {
        b.classList.toggle('on', b.getAttribute('data-f') === v);
      });
    }
    render();
  }

  function lpSetSort(v) { _lp.sort = v; render(); }
  function lpSetSearch(v) { _lp.q = v || ''; scheduleRender(); }
  function lpSetFlag(k, on) {
    if (k === 'onlyMarket') _lp.onlyMarket = !!on;
    if (k === 'hideBp') _lp.hideBp = !!on;
    if (k === 'noReq') _lp.noReq = !!on;
    render();
  }

  // 一行点击 = 展开/收起；展开后首次按需拉挂单前五
  document.addEventListener('click', function (e) {
    var tr = e.target && e.target.closest ? e.target.closest('tr.lp-row') : null;
    if (!tr || !tr.closest('#pg-lp')) return;
    var key = tr.getAttribute('data-lp-key');
    var typeId = parseInt(tr.getAttribute('data-lp-type'), 10);
    if (_lp.expanded[key]) {
      delete _lp.expanded[key];
      render();
    } else {
      _lp.expanded[key] = 1;
      render();
      var cell = document.querySelector('[data-lp-det="' + typeId + '"]');
      if (cell && !_lp.books[typeId]) loadBook(typeId, false);
    }
  });

  // 暴露给 index.html 的页面切换与内联事件
  window.lpInit = lpInit;
  window.lpReload = function (force) { return lpReload(!!force); };
  window.lpReloadBook = lpReloadBook;
  window.lpSetRegion = lpSetRegion;
  window.lpSetLp = lpSetLp;
  window.lpSetFaction = lpSetFaction;
  window.lpSetSort = lpSetSort;
  window.lpSetSearch = lpSetSearch;
  window.lpSetFlag = lpSetFlag;
})();
