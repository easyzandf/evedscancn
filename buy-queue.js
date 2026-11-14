/*
 * Multi-fit purchase queue.
 *
 * The original buy-list calculator starts every calculation from the same
 * pasted inventory. This layer keeps an ordered queue, reserves inventory for
 * each fit, and keeps confirmed batches locked so later fits cannot reuse the
 * same modules.
 */
(function () {
  'use strict';

  var STORE_KEY = 'eve-buy-queue-v1';
  var _ready = false;
  var _bound = false;
  var _renderTimer = null;
  var _state = loadState();

  function emptyState() {
    return {
      version: 1,
      assetText: '',
      currentFitText: '',
      plans: [],
      adjustments: { items: [], ships: [] }
    };
  }

  function loadState() {
    var base = emptyState();
    try {
      var saved = JSON.parse(localStorage.getItem(STORE_KEY) || 'null');
      if (!saved || saved.version !== 1) return base;
      base.assetText = typeof saved.assetText === 'string' ? saved.assetText : '';
      base.currentFitText = typeof saved.currentFitText === 'string' ? saved.currentFitText : '';
      base.plans = Array.isArray(saved.plans) ? saved.plans.filter(function (p) {
        return p && p.id && p.fitText && (p.status === 'draft' || p.status === 'confirmed');
      }) : [];
      if (saved.adjustments) {
        base.adjustments.items = Array.isArray(saved.adjustments.items) ? saved.adjustments.items : [];
        base.adjustments.ships = Array.isArray(saved.adjustments.ships) ? saved.adjustments.ships : [];
      }
    } catch (e) {
      return base;
    }
    return base;
  }

  function saveState() {
    try {
      localStorage.setItem(STORE_KEY, JSON.stringify({
        version: 1,
        assetText: _state.assetText,
        currentFitText: _state.currentFitText,
        plans: _state.plans.map(function (p) {
          return {
            id: p.id,
            fitText: p.fitText,
            count: p.count,
            label: p.label,
            status: p.status,
            createdAt: p.createdAt,
            confirmedAt: p.confirmedAt || null,
            locked: p.locked || null
          };
        }),
        adjustments: _state.adjustments
      }));
    } catch (e) {}
  }

  function newId() {
    return Date.now().toString(36) + Math.random().toString(36).slice(2, 9);
  }

  function cleanName(name) {
    return String(name || '').trim().replace(/\*+$/, '').trim();
  }

  function itemIdentity(name) {
    var raw = cleanName(name);
    var id = lookupItemId(raw);
    return {
      kind: 'item',
      id: id ? Number(id) : null,
      key: id ? ('i:' + id) : ('r:' + raw.toLowerCase()),
      sourceName: raw
    };
  }

  function shipIdentity(name) {
    var raw = cleanName(name);
    var ship = lookupShip(raw);
    var id = ship && ship.attr ? Number(ship.attr.typeID) : null;
    return {
      kind: 'hull',
      id: id,
      key: id ? ('s:' + id) : ('rs:' + raw.toLowerCase()),
      sourceName: ship ? (ship.en || ship.cn || raw) : raw,
      ship: ship || null
    };
  }

  function newMap() {
    return Object.create(null);
  }

  function addQty(map, key, qty) {
    if (!key || !(qty > 0)) return;
    map[key] = (map[key] || 0) + qty;
  }

  function takeQty(map, key, wanted) {
    var available = Number(map[key] || 0);
    var take = Math.max(0, Math.min(available, Number(wanted) || 0));
    if (take > 0) map[key] = available - take;
    return take;
  }

  function addAdjustment(entry, usedQty) {
    if (!entry || !(usedQty > 0)) return;
    var target = entry.kind === 'hull' ? _state.adjustments.ships : _state.adjustments.items;
    var found = null;
    for (var i = 0; i < target.length; i++) {
      if (target[i].key === entry.key) { found = target[i]; break; }
    }
    if (!found) {
      found = { key: entry.key, id: entry.id || null, name: entry.sourceName || entry.name || '', qty: 0 };
      target.push(found);
    }
    found.qty += usedQty;
  }

  function applyAdjustments(map, entries) {
    (entries || []).forEach(function (e) {
      if (!e || !e.key || !(e.qty > 0)) return;
      addQty(map, e.key, e.qty);
    });
  }

  function buildPools() {
    var items = newMap();
    var ships = newMap();
    var current = newMap();

    parseAssets(_state.assetText).forEach(function (asset) {
      var item = itemIdentity(asset.name);
      if (item.id) {
        addQty(items, item.key, asset.qty);
        return;
      }
      var ship = shipIdentity(asset.name);
      if (ship.id) addQty(ships, ship.key, asset.qty);
      else addQty(items, item.key, asset.qty);
    });

    applyAdjustments(items, _state.adjustments.items);
    applyAdjustments(ships, _state.adjustments.ships);

    var currentFit = parseFit(_state.currentFitText || '');
    Object.keys(currentFit.modules || {}).forEach(function (name) {
      var item = itemIdentity(name);
      addQty(current, item.key, currentFit.modules[name]);
    });

    var currentShip = currentFit.ship ? shipIdentity(currentFit.ship) : null;
    return {
      items: items,
      ships: ships,
      current: current,
      currentShipKey: currentShip ? currentShip.key : null,
      currentShipQty: currentShip ? 1 : 0
    };
  }

  function consumeLockedRows(pools, rows) {
    (rows || []).forEach(function (row) {
      if (row.kind === 'hull') {
        if (row.currentHave > 0 && pools.currentShipQty > 0 && row.key === pools.currentShipKey) {
          pools.currentShipQty = 0;
        }
        if (row.assetHave > 0) takeQty(pools.ships, row.key, row.assetHave);
        return;
      }
      if (row.currentHave > 0) takeQty(pools.current, row.key, row.currentHave);
      if (row.assetHave > 0) takeQty(pools.items, row.key, row.assetHave);
    });
  }

  function displayItemName(id, fallback) {
    var data = id && window.ITEMS_DATA ? window.ITEMS_DATA[id] : null;
    if (!data) return fallback;
    return data.zh || data.en || fallback;
  }

  function secondaryItemName(id) {
    var data = id && window.ITEMS_DATA ? window.ITEMS_DATA[id] : null;
    if (!data) return '';
    return data.en && data.en !== data.zh ? data.en : '';
  }

  function calcPlan(plan, pools) {
    var fit = parseFit(plan.fitText);
    var needs = Object.create(null);
    var rows = [];

    Object.keys(fit.modules || {}).forEach(function (name) {
      var identity = itemIdentity(name);
      if (!needs[identity.key]) needs[identity.key] = { identity: identity, name: cleanName(name), per: 0 };
      needs[identity.key].per += fit.modules[name];
    });

    Object.keys(needs).forEach(function (key) {
      var entry = needs[key];
      var identity = entry.identity;
      var need = entry.per * plan.count;
      var currentHave = takeQty(pools.current, identity.key, need);
      var assetHave = takeQty(pools.items, identity.key, need - currentHave);
      var buy = Math.max(0, need - currentHave - assetHave);
      rows.push({
        kind: 'item', key: identity.key, id: identity.id,
        sourceName: entry.name,
        name: displayItemName(identity.id, entry.name),
        en: secondaryItemName(identity.id),
        need: need, currentHave: currentHave, assetHave: assetHave,
        have: currentHave + assetHave, buy: buy,
        stockAfter: Number(pools.items[identity.key] || 0)
      });
    });

    if (fit.ship) {
      var ship = shipIdentity(fit.ship);
      var needHull = plan.count;
      var currentHull = 0;
      if (pools.currentShipQty > 0 && ship.key === pools.currentShipKey) {
        currentHull = 1;
        pools.currentShipQty = 0;
      }
      var assetHull = takeQty(pools.ships, ship.key, needHull - currentHull);
      rows.unshift({
        kind: 'hull', key: ship.key, id: ship.id,
        sourceName: ship.sourceName,
        name: ship.ship ? ship.ship.cn : ship.sourceName,
        en: ship.ship ? ship.ship.en : '',
        need: needHull, currentHave: currentHull, assetHave: assetHull,
        have: currentHull + assetHull,
        buy: Math.max(0, needHull - currentHull - assetHull),
        stockAfter: Number(pools.ships[ship.key] || 0)
      });
    }

    plan._rows = rows;
    plan._fit = fit;
    return rows;
  }

  function computeQueue() {
    var pools = buildPools();
    _state.plans.forEach(function (plan) {
      if (plan.status === 'confirmed' && plan.locked) {
        consumeLockedRows(pools, plan.locked.rows);
      }
    });
    _state.plans.forEach(function (plan) {
      if (plan.status === 'draft') calcPlan(plan, pools);
      else if (plan.locked) {
        plan._rows = plan.locked.rows;
        plan._fit = parseFit(plan.fitText);
      }
    });
    return { pools: pools, combined: combineDrafts() };
  }

  function combineDrafts() {
    var merged = Object.create(null);
    _state.plans.forEach(function (plan) {
      if (plan.status !== 'draft') return;
      (plan._rows || []).forEach(function (row) {
        if (!(row.buy > 0)) return;
        var key = row.kind + ':' + row.key;
        if (!merged[key]) {
          merged[key] = {
            kind: row.kind, key: row.key, id: row.id,
            sourceName: row.sourceName, name: row.name, en: row.en,
            need: 0, currentHave: 0, assetHave: 0, have: 0, buy: 0
          };
        }
        var target = merged[key];
        target.need += row.need;
        target.currentHave += row.currentHave;
        target.assetHave += row.assetHave;
        target.have += row.have;
        target.buy += row.buy;
      });
    });
    return Object.keys(merged).map(function (key) { return merged[key]; }).sort(function (a, b) {
      if (b.buy !== a.buy) return b.buy - a.buy;
      var an = (a.name || '') + ' ' + (a.en || '');
      var bn = (b.name || '') + ' ' + (b.en || '');
      return an < bn ? -1 : an > bn ? 1 : 0;
    });
  }

  function rowHtml(row) {
    var label = esc(row.name || row.sourceName || '未识别');
    if (row.en && row.en !== row.name) label += ' <span class="en">' + esc(row.en) + '</span>';
    return '<tr><td>' + (row.kind === 'hull' ? '<b>船体</b> ' : '') + label + '</td>'
      + '<td class="qty-need">' + row.need + '</td>'
      + '<td class="qty-have">' + row.currentHave + '</td>'
      + '<td class="qty-have">' + row.assetHave + '</td>'
      + '<td class="' + (row.buy > 0 ? 'qty-buy' : 'qty-zero') + '">'
      + (row.buy > 0 ? row.buy : '已有') + '</td></tr>';
  }

  function planCardHtml(plan, index) {
    var rows = plan._rows || (plan.locked && plan.locked.rows) || [];
    var buyRows = rows.filter(function (r) { return r.buy > 0; }).length;
    var buyUnits = rows.reduce(function (n, r) { return n + (r.buy || 0); }, 0);
    var confirmed = plan.status === 'confirmed';
    var actions = '<button type="button" class="btn btn-clr" data-buy-plan-action="up" data-plan-id="' + esc(plan.id) + '"' + (index === 0 ? ' disabled' : '') + '>↑</button>'
      + '<button type="button" class="btn btn-clr" data-buy-plan-action="down" data-plan-id="' + esc(plan.id) + '"' + (index === _state.plans.length - 1 ? ' disabled' : '') + '>↓</button>';
    if (confirmed) {
      actions += '<button type="button" class="btn btn-clr" data-buy-plan-action="remove" data-plan-id="' + esc(plan.id) + '">撤销并释放</button>';
    } else {
      actions += '<button type="button" class="btn btn-clr" data-buy-plan-action="edit" data-plan-id="' + esc(plan.id) + '">编辑</button>'
        + '<button type="button" class="btn btn-go" data-buy-plan-action="confirm" data-plan-id="' + esc(plan.id) + '">确认已购买</button>'
        + '<button type="button" class="btn btn-clr" data-buy-plan-action="remove" data-plan-id="' + esc(plan.id) + '">删除</button>';
    }
    var details = rows.length ? rows.map(rowHtml).join('') : '<tr><td colspan="5" class="buy-empty-plan">没有可计算的装备。</td></tr>';
    return '<div class="buy-plan-card" data-plan-card="' + esc(plan.id) + '">'
      + '<div class="buy-plan-head"><div class="buy-plan-title">'
      + '<span class="buy-plan-order">' + (index + 1) + '.</span><b>' + esc(plan.label) + '</b>'
      + '<span class="buy-plan-count">× ' + plan.count + ' 艘</span></div>'
      + '<span class="buy-plan-status' + (confirmed ? ' confirmed' : '') + '">'
      + (confirmed ? '已确认 / 占用中' : '待购买') + '</span>'
      + '<div class="buy-plan-actions">' + actions + '</div></div>'
      + '<div class="buy-plan-details"><details><summary>'
      + (confirmed ? '锁定明细' : '分配明细') + ' · 需购买 ' + buyRows + ' 种 / ' + buyUnits + ' 件</summary>'
      + '<table><thead><tr><th>装备</th><th>目标需求</th><th>当前配装</th><th>资产占用</th><th>需购买</th></tr></thead>'
      + '<tbody>' + details + '</tbody></table></details></div></div>';
  }

  function renderCombined(rows) {
    var el = document.getElementById('buyResult');
    if (!el) return;
    if (!rows.length) {
      el.innerHTML = '<h3>合并采购清单</h3><p style="color:#888;font-size:12px">当前没有待购买物品。已确认批次继续占用库存，可继续添加下一套配装。</p>';
      el.classList.remove('hidden');
      window._lastBuy = null;
      return;
    }

    var html = '<h3>合并采购清单 <span>待确认批次</span></h3>'
      + '<p style="color:#888;font-size:12px;margin:0 0 10px">相同物品已合并；Jita 价格按合并后的数量逐档计算。</p>'
      + '<table class="ship-tbl"><thead><tr><th style="width:42%">装备</th><th>目标需求</th>'
      + '<th>当前配装</th><th>资产占用</th><th>需购买</th></tr></thead><tbody>';
    rows.forEach(function (row) {
      html += rowHtml(row);
    });
    html += '</tbody></table>'
      + '<div class="btnbar">'
      + '<button class="btn btn-go" onclick="copyBuyList(this)">复制合并清单(Multi-Buy)</button> '
      + '<button class="btn btn-clr" onclick="screenshotBuy(this)">截图复制</button></div>';
    el.innerHTML = html;
    el.classList.remove('hidden');
    window._lastBuy = { rows: rows, hull: null, count: 0 };
    if (typeof window.initBuyPriceView === 'function') window.initBuyPriceView();
  }

  function renderQueue() {
    if (!_itemsReady) {
      loadItems(renderQueue);
      return;
    }
    syncInputs();
    var result = computeQueue();
    var panel = document.getElementById('buyQueuePanel');
    var cards = document.getElementById('buyQueueCards');
    var count = document.getElementById('buyQueueCount');
    var meta = document.getElementById('buyQueueMeta');
    if (!panel || !cards || !count || !meta) return;

    panel.classList.toggle('hidden', !_state.plans.length);
    var drafts = _state.plans.filter(function (p) { return p.status === 'draft'; }).length;
    var confirmed = _state.plans.length - drafts;
    count.textContent = _state.plans.length ? '共 ' + _state.plans.length + ' 套' : '';
    meta.innerHTML = '<span>待购买 <b>' + drafts + '</b> 套</span>'
      + '<span>已确认占用 <b>' + confirmed + '</b> 套</span>'
      + '<span>本批合并需购 <b>' + result.combined.reduce(function (n, r) { return n + r.buy; }, 0) + '</b> 件</span>';

    var adjustmentCount = (_state.adjustments.items || []).length + (_state.adjustments.ships || []).length;
    if (adjustmentCount) {
      meta.innerHTML += '<span class="buy-stock-adjust">撤销批次带回的库存调整已生效 '
        + '<button type="button" class="btn btn-clr" data-buy-adjust-clear="1">清空调整</button></span>';
    }
    cards.innerHTML = _state.plans.map(planCardHtml).join('');
    renderCombined(result.combined);
  }

  function scheduleRender(delay) {
    if (_renderTimer) clearTimeout(_renderTimer);
    _renderTimer = setTimeout(function () {
      _renderTimer = null;
      if (_itemsReady) renderQueue();
    }, delay == null ? 250 : delay);
  }

  function syncInputs() {
    var assets = document.getElementById('assetInput');
    var current = document.getElementById('currentFitInput');
    if (assets) _state.assetText = assets.value;
    if (current) _state.currentFitText = current.value;
  }

  function fitLabel(text, fit) {
    var first = String(text || '').split(/\r?\n/).find(function (line) { return line.trim(); }) || '';
    var match = first.trim().match(/^\[([^\],]+)(?:,\s*([^\]]*))?\]$/);
    var ship = (match && match[1].trim()) || (fit && fit.ship) || '未命名配装';
    var name = match && match[2] ? match[2].trim() : '';
    return name ? (ship + ' · ' + name) : ship;
  }

  function addPlan() {
    loadItems(function () {
      syncInputs();
      var fitInput = document.getElementById('fitInput');
      var countInput = document.getElementById('fitCount');
      var text = fitInput ? fitInput.value : '';
      if (!text.trim()) {
        alert('请先在③粘贴想切换的新配装');
        return;
      }
      var fit = parseFit(text);
      if (!fit.ship && !Object.keys(fit.modules || {}).length) {
        alert('没有识别到舰船或装备，请检查配装文本');
        return;
      }
      var count = parseInt(countInput && countInput.value, 10) || 1;
      _state.plans.push({
        id: newId(),
        fitText: text,
        count: Math.max(1, count),
        label: fitLabel(text, fit),
        status: 'draft',
        createdAt: Date.now(),
        confirmedAt: null,
        locked: null
      });
      if (fitInput) fitInput.value = '';
      saveState();
      renderQueue();
    });
  }

  function findPlan(id) {
    for (var i = 0; i < _state.plans.length; i++) {
      if (_state.plans[i].id === id) return _state.plans[i];
    }
    return null;
  }

  function cloneRows(rows) {
    return (rows || []).map(function (row) {
      return {
        kind: row.kind, key: row.key, id: row.id,
        sourceName: row.sourceName, name: row.name, en: row.en,
        need: row.need, currentHave: row.currentHave, assetHave: row.assetHave,
        have: row.have, buy: row.buy, stockAfter: row.stockAfter
      };
    });
  }

  function confirmPlan(id) {
    loadItems(function () {
      syncInputs();
      computeQueue();
      var plan = findPlan(id);
      if (!plan || plan.status !== 'draft') return;
      var rows = cloneRows(plan._rows || []);
      plan.locked = { rows: rows, confirmedAt: Date.now() };
      plan.status = 'confirmed';
      plan.confirmedAt = Date.now();
      saveState();
      renderQueue();
    });
  }

  function removePlan(id) {
    var index = _state.plans.findIndex(function (p) { return p.id === id; });
    if (index < 0) return;
    var plan = _state.plans[index];
    if (plan.status === 'confirmed') {
      if (!confirm('撤销这个已确认批次？\n\n该批次占用的资产、当前舰船模块和已购买数量都会重新变为可用库存。')) return;
      (plan.locked && plan.locked.rows || []).forEach(function (row) {
        if (row.buy > 0) addAdjustment(row, row.buy);
      });
    }
    _state.plans.splice(index, 1);
    saveState();
    renderQueue();
  }

  function editPlan(id) {
    var plan = findPlan(id);
    if (!plan || plan.status !== 'draft') return;
    var fitInput = document.getElementById('fitInput');
    var countInput = document.getElementById('fitCount');
    if (fitInput) fitInput.value = plan.fitText;
    if (countInput) countInput.value = String(plan.count);
    _state.plans = _state.plans.filter(function (p) { return p.id !== id; });
    saveState();
    renderQueue();
    if (fitInput) fitInput.focus();
  }

  function movePlan(id, delta) {
    var index = _state.plans.findIndex(function (p) { return p.id === id; });
    var target = index + delta;
    if (index < 0 || target < 0 || target >= _state.plans.length) return;
    var plan = _state.plans[index];
    _state.plans[index] = _state.plans[target];
    _state.plans[target] = plan;
    saveState();
    renderQueue();
  }

  function clearAdjustments() {
    if (!(_state.adjustments.items.length || _state.adjustments.ships.length)) return;
    if (!confirm('清空撤销批次带回的库存调整？后续计算将不再计入这些物品。')) return;
    _state.adjustments = { items: [], ships: [] };
    saveState();
    renderQueue();
  }

  function clearAll() {
    if (_state.plans.length && !confirm('清空全部采购队列、资产输入和库存调整？')) return;
    _state = emptyState();
    try { localStorage.removeItem(STORE_KEY); } catch (e) {}
    ['assetInput', 'currentFitInput', 'fitInput'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.value = '';
    });
    var count = document.getElementById('fitCount');
    if (count) count.value = '1';
    var panel = document.getElementById('buyQueuePanel');
    if (panel) panel.classList.add('hidden');
    var result = document.getElementById('buyResult');
    if (result) {
      result.classList.add('hidden');
      result.innerHTML = '';
    }
    window._lastBuy = null;
  }

  function copyCombined(btn) {
    var lb = window._lastBuy;
    if (!lb || !lb.rows) return;
    var lines = lb.rows.filter(function (r) { return r.buy > 0; }).map(function (r) {
      return _sanName(r.name || r.sourceName) + '\t' + r.buy;
    });
    if (!lines.length) {
      alert('当前没有需要购买的物品');
      return;
    }
    _copyText(lines.join('\n'), btn, function () {
      if (btn) {
        var old = btn.textContent;
        btn.textContent = '已复制!';
        setTimeout(function () { btn.textContent = old; }, 1500);
      }
    });
  }

  function init() {
    var assets = document.getElementById('assetInput');
    var current = document.getElementById('currentFitInput');
    var fit = document.getElementById('fitInput');
    var count = document.getElementById('fitCount');
    if (!assets || !current || !fit || !count) return;

    if (!_bound) {
      _bound = true;
      if (!assets.value) assets.value = _state.assetText;
      if (!current.value) current.value = _state.currentFitText;
      assets.addEventListener('input', function () {
        _state.assetText = assets.value;
        saveState();
        scheduleRender();
      });
      current.addEventListener('input', function () {
        _state.currentFitText = current.value;
        saveState();
        scheduleRender();
      });
      var addBtn = document.getElementById('buyAddPlanBtn');
      if (addBtn) addBtn.addEventListener('click', addPlan);
      document.addEventListener('click', function (event) {
        var clearAdjust = event.target && event.target.closest ? event.target.closest('[data-buy-adjust-clear]') : null;
        if (clearAdjust) {
          event.preventDefault();
          clearAdjustments();
          return;
        }
        var button = event.target && event.target.closest ? event.target.closest('[data-buy-plan-action]') : null;
        if (!button) return;
        event.preventDefault();
        event.stopPropagation();
        var id = button.getAttribute('data-plan-id');
        var action = button.getAttribute('data-buy-plan-action');
        if (action === 'up') movePlan(id, -1);
        else if (action === 'down') movePlan(id, 1);
        else if (action === 'confirm') confirmPlan(id);
        else if (action === 'edit') editPlan(id);
        else if (action === 'remove') removePlan(id);
      });
    }

    fit.placeholder = '把要切换到的装配复制到这里。可以连续添加多套，队列会按顺序占用共享库存，例如：\n[Ferox, New Fit]\nDamage Control II\nHeavy Missile Launcher II x7';
    var hint = document.querySelector('.buy-bar .hint2');
    if (hint) hint.textContent = '按队列顺序分配资产；确认购买后继续占用。相同物品在最终 Multi-Buy 中自动合并';

    loadItems(function () {
      _ready = true;
      if (_state.plans.length) renderQueue();
    });
  }

  window.buyQueueInit = init;
  window.buyQueueAddPlan = addPlan;
  window.computeBuyList = addPlan;
  window.clearBuy = clearAll;
  window.copyBuyList = copyCombined;

  document.querySelectorAll('.topbar .nav a').forEach(function (link) {
    link.addEventListener('click', function () {
      if (link.dataset.pg === 'buy') init();
    });
  });

  if (typeof window.switchPg === 'function') {
    var originalSwitchPg = window.switchPg;
    window.switchPg = function (pg) {
      originalSwitchPg(pg);
      if (pg === 'buy') init();
    };
  }
})();
