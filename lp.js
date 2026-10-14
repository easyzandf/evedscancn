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

  // 销售税：0 技能基础 7.5%（Accounting 每级再降 11%，满级 3.37%）
  var LP_TAX = 0.075;
  // 默认费率：销售税 0 技能 7.5%、中介费 0 技能 3%、安全税 0；页面上的输入框可改
  var LP_FEE_DEFAULT = { sales: 7.5, broker: 2.4, safe: 0 };

  var _lp = {
    inited: false,
    loading: false,
    loaded: false,
    region: 10000002,
    offers: {},
    prices: {},
    esiNames: {},
    books: {},
    depth: {},
    depthLoading: false,
    depthRegion: 0,
    contracts: {},
    contractsLoading: false,
    contractsRegion: 0,
    lp: { C: '', G: '' },
    fee: { sales: LP_FEE_DEFAULT.sales, broker: LP_FEE_DEFAULT.broker, safe: LP_FEE_DEFAULT.safe },
    faction: 'all',
    sort: 'now',
    q: '',
    onlyMarket: true,
    onlyContract: false,
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
    if (el.tagName === 'BUTTON') {
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
    if (_lp.depthRegion !== _lp.region) loadDepths(!!_lp.forceDepth);
    if (_lp.contractsRegion !== _lp.region) loadContracts(!!_lp.forceDepth);
    _lp.forceDepth = false;
  }

  // 前五收单深度：整表约 500 个 type，服务端逐 type 缓存 15 分钟，首次拉完后面秒开
  // 公开合同：服务端按小时从 everef 快照重建，只保留 LP 可换物品的单物品合同
  function loadContracts(force) {
    if (_lp.contractsLoading) return;
    _lp.contractsLoading = true;
    apiGet('lp.php?action=contracts&region=' + _lp.region + (force ? '&force=1' : ''), function (res) {
      _lp.contractsLoading = false;
      if (res && res.contracts) {
        _lp.contracts = res.contracts;
        _lp.contractsRegion = _lp.region;
        if (_lp.loaded) render();
      }
    });
  }

  function loadDepths(force) {
    if (_lp.depthLoading) return;
    _lp.depthLoading = true;
    var uniq = {};
    eachOffer(function (st, o) { if (o.type_id) uniq[o.type_id] = 1; });
    var list = Object.keys(uniq);
    if (!list.length) {
      _lp.depthLoading = false;
      _lp.depthRegion = _lp.region;
      return;
    }
    var chunks = [];
    for (var i = 0; i < list.length; i += 60) chunks.push(list.slice(i, i + 60));
    var done = 0, idx = 0;
    var next = function () {
      if (idx >= chunks.length) {
        _lp.depthLoading = false;
        _lp.depthRegion = _lp.region;
        setHint('');
        render();
        return;
      }
      setHint('正在拉取收单深度… ' + done + ' / ' + list.length);
      apiGet('lp.php?action=depths&region=' + _lp.region + '&ids=' + chunks[idx].join(',') + (force ? '&force=1' : ''), function (res) {
        if (res && res.depths) {
          Object.keys(res.depths).forEach(function (k) { _lp.depth[k] = res.depths[k]; });
        }
        done += chunks[idx].length;
        idx++;
        next();
      });
    };
    next();
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
        // 秒卖（吃收单）只扣销售税；挂卖还要再扣中介费和安全税
        var fSales = num(_lp.fee.sales) / 100;
        var fList = fSales + num(_lp.fee.broker) / 100 + num(_lp.fee.safe) / 100;
        var revNow = buy > 0 ? buy * o.quantity : null;
        var revList = sell > 0 ? sell * o.quantity : null;
        var profitNow = revNow === null ? null : revNow * (1 - fSales) - cost;
        var profitList = revList === null ? null : revList * (1 - fList) - cost;

        var amt = num(_lp.lp[st.key]);
        var times = amt > 0 ? Math.floor(amt / o.lp_cost) : 0;

        // 吃满前五收单：次数受深度限制，输入 LP 后再受 LP 限制
        var dep = _lp.depth[o.type_id] || null;
        var ctr = _lp.contracts[o.type_id] || [];
        var contractPerLp = null;
        if (ctr.length && o.lp_cost > 0) {
          var cRev = num(ctr[0].unit) * o.quantity;
          contractPerLp = (cRev * (1 - fSales) - cost) / o.lp_cost;
        }
        var depLoaded = Object.prototype.hasOwnProperty.call(_lp.depth, o.type_id);
        var depQty = 0, depTimes = 0, depUse = 0, depProfit = null;
        if (dep && dep.buy && dep.buy.length) {
          for (var di = 0; di < dep.buy.length; di++) depQty += num(dep.buy[di].v);
          depTimes = Math.floor(depQty / o.quantity);
          if (depTimes > 0) {
            depUse = amt > 0 ? Math.min(depTimes, Math.floor(amt / o.lp_cost)) : depTimes;
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
          contractPerLp: contractPerLp,
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
    function val(r) {
      if (key === 'list') return r.perLpList;
      if (key === 'contract') return r.contractPerLp;
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
    html += '<p class="lp-note">秒卖 = 卖给最高收购价，净收益扣『销售税』；挂卖 = 挂最低出售价，净收益扣『销售税 + 中介费 + 安全税』；毛 ISK/LP = 卖出收入 ÷ LP（税前、不扣任何成本）；前五秒卖 = 按前五收购单从高到低吃掉这些量，可兑换的次数与净收益（按秒卖费率）；合同 = 公开合同里该物品最便宜的单物品合同单价（只索引 LP 能换到的物品，每小时从 everef 快照重建，按销售税口径折算 ISK/LP）。费率默认：销售税 7.5%、中介费 2.4%、安全税 0，顶部输入框可随时修改，改完所有数字实时重算。取欧服全区最优（可能含玩家建筑）。LP 商店报价 24 小时缓存，行情与前五深度 15 分钟缓存。</p>';
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
      + '<th class="num lp-th-cost">兑换成本</th>'
      + '<th class="num lp-th-market">市场 / 产出</th>'
      + '<th class="num lp-th-gain">秒卖收益</th>'
      + '<th class="num lp-th-gain">挂卖收益</th>'
      + '<th class="num lp-th-depth">前五秒卖</th>'
      + '<th class="num lp-th-total">可兑总收益</th>'
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

    var matLine = r.mats.length
      ? '<span class="lp-copy-mat" data-lp-mat="' + escHtml(key) + '" title="点击复制材料清单（Multi-Buy）&#10;' + escHtml(matTitle(r)) + '">'
        + r.mats.length + ' 项 ' + iskShort(r.matCost) + '</span>'
        + (r.matUnknown ? ' <span class="lp-warn" title="有材料查不到行情，成本偏低">?</span>' : '')
      : '—';

    var amt = num(_lp.lp[r.store.key]);
    var totalCell = amt > 0
      ? (r.totalNow === null ? '—' : '<div class="lp-val lp-strong">' + iskShort(r.totalNow) + '</div><div class="lp-sub">' + iskN(r.times) + ' 次</div>')
      : '<span class="lp-muted">填 LP</span>';

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
        + (r.en ? '<div class="lp-en">' + escHtml(r.en) + '</div>' : '') + '</td>'
      + '<td class="num lp-c-cost">'
        + '<div><span class="lp-k">LP</span>' + iskN(o.lp_cost) + '</div>'
        + '<div><span class="lp-k">ISK</span>' + iskShort(o.isk_cost) + '</div>'
        + '<div><span class="lp-k">材料</span>' + matLine + '</div>'
      + '</td>'
      + '<td class="num lp-c-market">'
        + '<div><span class="lp-k">收</span>' + (r.buy > 0 ? iskShort(r.buy) : '—') + '</div>'
        + '<div><span class="lp-k">售</span>' + (r.sell > 0 ? iskShort(r.sell) : '—') + '</div>'
        + '<div><span class="lp-k">换得</span>x' + iskN(o.quantity) + '</div>'
        + (r.contracts.length ? '<div class="lp-contract"><span class="lp-k">合同</span>' + iskShort(r.contracts[0].unit) + '</div>' : '')
      + '</td>'
      + '<td class="num lp-c-gain">'
        + '<div class="lp-val lp-strong">净 ' + perLp(r.perLpNow) + ' <span class="lp-unit">ISK/LP</span></div>'
        + '<div class="lp-sub">毛 ' + perLp(r.grossPerLpNow) + ' <span class="lp-unit">ISK/LP</span></div>'
        + '<div class="lp-sub' + (num(r.profitNow) < 0 ? ' lp-sub-neg' : '') + '">单次 ' + (r.profitNow === null ? '—' : iskShort(r.profitNow)) + '</div>'
      + '</td>'
      + '<td class="num lp-c-gain">'
        + '<div class="lp-val">净 ' + perLp(r.perLpList) + ' <span class="lp-unit">ISK/LP</span></div>'
        + '<div class="lp-sub">毛 ' + perLp(r.grossPerLpList) + ' <span class="lp-unit">ISK/LP</span></div>'
        + '<div class="lp-sub">单次 ' + (r.profitList === null ? '—' : iskShort(r.profitList)) + '</div>'
      + '</td>'
      + '<td class="num lp-c-depth">' + depthCell + '</td>'
      + '<td class="num lp-c-total">' + totalCell + '</td>'
      + '</tr>';

    if (open) {
      html += '<tr class="lp-detail-row"><td colspan="7">'
        + '<div class="lp-detail">'
        + '<div class="lp-detail-bar"><button type="button" class="lp-copy-btn" data-lp-mat="' + escHtml(key) + '">复制材料清单(Multi-Buy)</button></div>'
        + contractBlockHtml(r)
        + '<div data-lp-det="' + o.type_id + '">' + detailHtml(o.type_id) + '</div>'
        + '</div></td></tr>';
    }
    return html;
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
    var profit = rev * (1 - num(_lp.fee.sales) / 100) - r.cost;
    var per = r.offer.lp_cost > 0 ? profit / r.offer.lp_cost : null;
    html += '<div class="lp-detail-msg">按最低合同单价：毛收入 ' + iskShort(rev)
      + '，扣 ' + num(_lp.fee.sales) + '% 销售税与 ISK/材料成本后净收益 ' + iskShort(profit)
      + '，折合 <b>' + perLp(per) + ' ISK/LP</b></div>';
    return html + '</div>';
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
    if (k === 'onlyContract') _lp.onlyContract = !!on;
    if (k === 'hideBp') _lp.hideBp = !!on;
    if (k === 'noReq') _lp.noReq = !!on;
    render();
  }

  // 一行点击 = 展开/收起；展开后首次按需拉挂单前五
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    // 材料清单复制：拦在行展开之前
    var cp = t.closest('[data-lp-mat]');
    if (cp && cp.closest('#pg-lp')) {
      e.preventDefault();
      copyMaterialList(cp.getAttribute('data-lp-mat'), cp);
      return;
    }
    var tr = t.closest('tr.lp-row');
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
  window.lpSetFee = lpSetFee;
  window.lpSetFaction = lpSetFaction;
  window.lpSetSort = lpSetSort;
  window.lpSetSearch = lpSetSearch;
  window.lpSetFlag = lpSetFlag;
})();
