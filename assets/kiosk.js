/* Amazonia Bowls Kiosk — front-end logic */
(function () {
  'use strict';

  const API = 'api/'; // relative path; works at amazoniabowls.com/kiosk/
  const POLL_PAY_MS = 2000;
  const ATTRACT_TIMEOUT_MS = 90 * 1000; // return to attract after 90s of inactivity

  // ---- state ----
  const state = {
    menu: null,
    activeCategory: null,
    cart: [],          // {uid, item, size, mods, qty, lineTotal}
    editing: null,     // working draft in item modal
    orderId: null,
    pollTimer: null,
    idleTimer: null,
  };

  // ---- helpers ----
  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => document.querySelectorAll(sel);
  const fmt = (n) => Number(n).toFixed(0);
  const uid = () => Math.random().toString(36).slice(2, 9);

  function showScreen(name) {
    $$('.screen').forEach(s => s.classList.add('hidden'));
    $('#' + name).classList.remove('hidden');
    document.body.className = 'screen-' + name;
  }

  function resetIdleTimer() {
    clearTimeout(state.idleTimer);
    state.idleTimer = setTimeout(() => {
      if (!$('#pay-modal').classList.contains('hidden')) return; // never reset during payment
      state.cart = [];
      closeAllModals();
      showScreen('attract');
    }, ATTRACT_TIMEOUT_MS);
  }

  function closeAllModals() {
    $('#item-modal').classList.add('hidden');
    $('#cart-modal').classList.add('hidden');
    $('#pay-modal').classList.add('hidden');
  }

  // ---- menu loading ----
  async function loadMenu() {
    const res = await fetch('menu.json?v=' + Date.now());
    state.menu = await res.json();
    if (!state.menu.modifier_groups) state.menu.modifier_groups = {};
    renderCategories();
    state.activeCategory = state.menu.categories[0].id;
    renderItems();
  }

  function renderCategories() {
    const nav = $('#categories');
    nav.innerHTML = '';
    state.menu.categories.forEach(cat => {
      const b = document.createElement('button');
      b.textContent = cat.name;
      b.dataset.id = cat.id;
      b.addEventListener('click', () => {
        state.activeCategory = cat.id;
        renderCategories();
        renderItems();
      });
      if (cat.id === state.activeCategory) b.classList.add('active');
      nav.appendChild(b);
    });
  }

  function renderItems() {
    const grid = $('#items');
    grid.innerHTML = '';
    const cat = state.menu.categories.find(c => c.id === state.activeCategory);
    if (!cat) return;
    cat.items.forEach(item => {
      const card = document.createElement('button');
      card.className = 'item-card';
      const minPrice = Math.min(...item.sizes.map(s => s.price));
      const priceLabel = item.sizes.length > 1 ? 'desde $' + fmt(minPrice) : '$' + fmt(minPrice);
      card.innerHTML = `
        <div class="img" style="${item.image ? `background-image:url('${item.image}')` : ''}"></div>
        <div class="body">
          <h3>${escapeHtml(item.name)}</h3>
          <div class="desc">${escapeHtml(item.description || '')}</div>
          <div class="price">${priceLabel}</div>
        </div>`;
      card.addEventListener('click', () => openItem(item, cat));
      grid.appendChild(card);
    });
  }

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  // ---- item modal ----
  function getItemModifierGroups(item) {
    const ids = item.modifier_groups || [];
    return ids.map(id => {
      const g = state.menu.modifier_groups[id];
      if (!g) return null;
      return Object.assign({ id }, g);
    }).filter(Boolean);
  }

  function openItem(item, cat) {
    const groups = getItemModifierGroups(item);
    const toppings = {};
    // pre-select first option for required-single groups (min=1, max=1)
    groups.forEach(g => {
      if (g.min === 1 && g.max === 1 && g.options.length > 0) {
        toppings[g.id] = [g.options[0].id];
      } else {
        toppings[g.id] = [];
      }
    });
    state.editing = {
      item,
      categoryId: cat.id,
      sizeId: item.sizes[0].id,
      toppings,
      qty: 1,
    };
    renderItemModal();
    $('#item-modal').classList.remove('hidden');
  }

  function renderItemModal() {
    const { item, sizeId } = state.editing;
    const body = $('#item-modal-body');
    const groups = getItemModifierGroups(item);

    const sizesHtml = item.sizes.length > 1 ? `
      <div class="option-group">
        <h4>Tamaño</h4>
        <div class="option-list">
          ${item.sizes.map(s => `
            <button class="option-pill ${s.id === sizeId ? 'selected' : ''}" data-size="${s.id}">
              <span class="name">${escapeHtml(s.label)}</span>
              <span class="extra-price">$${fmt(s.price)}</span>
            </button>`).join('')}
        </div>
      </div>` : '';

    const groupsHtml = groups.map(g => {
      const selected = state.editing.toppings[g.id] || [];
      const hint = g.min > 0
        ? (g.min === g.max ? `obligatorio: elige ${g.min}` : `mín. ${g.min}`)
        : (g.max < 99 ? `máx. ${g.max}` : 'opcional');
      const opts = g.options.map(o => `
        <button class="option-pill ${selected.includes(o.id) ? 'selected' : ''}"
                data-mod-group="${g.id}" data-mod="${o.id}">
          <span class="name">${escapeHtml(o.name)}</span>
          <span class="extra-price">${o.price > 0 ? '+$' + fmt(o.price) : (g.min > 0 || g.max > 1 ? 'Incluido' : 'Sin costo')}</span>
        </button>`).join('');
      return `<div class="option-group">
        <h4>${escapeHtml(g.name)} <span class="hint">— ${hint}</span></h4>
        <div class="option-list">${opts}</div>
      </div>`;
    }).join('');

    body.innerHTML = `
      <div class="item-detail">
        <h2>${escapeHtml(item.name)}</h2>
        <p class="desc">${escapeHtml(item.description || '')}</p>
        ${sizesHtml}
        ${groupsHtml}
      </div>`;

    // bind size buttons
    body.querySelectorAll('[data-size]').forEach(b => {
      b.addEventListener('click', () => {
        state.editing.sizeId = b.dataset.size;
        renderItemModal();
      });
    });
    // bind modifier buttons
    body.querySelectorAll('[data-mod]').forEach(b => {
      b.addEventListener('click', () => {
        const gid = b.dataset.modGroup;
        const oid = b.dataset.mod;
        const group = state.menu.modifier_groups[gid];
        if (!group) return;
        let sel = state.editing.toppings[gid] || [];
        if (group.max === 1) {
          // single-select: replace; if min=1 don't allow deselect by clicking same one
          if (sel.length === 1 && sel[0] === oid && group.min === 0) {
            sel = []; // allow deselect when not required
          } else {
            sel = [oid];
          }
        } else {
          const idx = sel.indexOf(oid);
          if (idx >= 0) sel.splice(idx, 1);
          else if (sel.length < group.max) sel.push(oid);
        }
        state.editing.toppings[gid] = sel;
        renderItemModal();
      });
    });

    $('#item-price').textContent = fmt(currentItemPrice());
    const addBtn = $('[data-action="add-to-cart"]');
    if (canAddToCart()) {
      addBtn.removeAttribute('disabled');
      addBtn.classList.remove('btn-disabled');
    } else {
      addBtn.setAttribute('disabled', 'disabled');
      addBtn.classList.add('btn-disabled');
    }
  }

  function canAddToCart() {
    if (!state.editing) return false;
    const groups = getItemModifierGroups(state.editing.item);
    for (const g of groups) {
      const sel = state.editing.toppings[g.id] || [];
      if (sel.length < g.min) return false;
      if (sel.length > g.max) return false;
    }
    return true;
  }

  function currentItemPrice() {
    if (!state.editing) return 0;
    const { item, sizeId, toppings } = state.editing;
    const size = item.sizes.find(s => s.id === sizeId);
    let total = size ? size.price : 0;
    Object.entries(toppings).forEach(([gid, ids]) => {
      const group = state.menu.modifier_groups[gid];
      if (!group) return;
      ids.forEach(oid => {
        const opt = group.options.find(o => o.id === oid);
        if (opt) total += opt.price;
      });
    });
    return total * (state.editing.qty || 1);
  }

  function addEditingToCart() {
    if (!canAddToCart()) return;
    const { item, sizeId, toppings, qty, categoryId } = state.editing;
    const size = item.sizes.find(s => s.id === sizeId);
    const lineTotal = currentItemPrice();

    // flatten modifier list with names + prices
    const flat = [];
    Object.entries(toppings).forEach(([gid, ids]) => {
      const group = state.menu.modifier_groups[gid];
      if (!group) return;
      ids.forEach(oid => {
        const opt = group.options.find(o => o.id === oid);
        if (opt) flat.push({
          group_id: gid, group_name: group.name,
          id: oid, name: opt.name, price: opt.price
        });
      });
    });

    state.cart.push({
      uid: uid(),
      itemId: item.id,
      itemName: item.name,
      categoryId,
      sizeId: size.id,
      sizeLabel: size.label,
      sizePrice: size.price,
      mods: flat,
      qty,
      lineTotal,
    });

    state.editing = null;
    $('#item-modal').classList.add('hidden');
    updateCartUI();
  }

  // ---- cart ----
  function updateCartUI() {
    const count = state.cart.reduce((n, l) => n + l.qty, 0);
    const total = state.cart.reduce((n, l) => n + l.lineTotal, 0);
    $('#cart-count').textContent = count;
    $('#cart-count-s').textContent = count === 1 ? '' : 's';
    $('#cart-total').textContent = fmt(total);
    $('#cart-total-2').textContent = fmt(total);
    const bar = $('#cart-bar');
    if (count === 0) bar.classList.add('empty'); else bar.classList.remove('empty');
    renderCartList();
  }

  function renderCartList() {
    const ul = $('#cart-list');
    ul.innerHTML = '';
    state.cart.forEach(line => {
      const li = document.createElement('li');
      const mods = [];
      if (line.sizeLabel && line.sizeLabel !== 'Unidad' && line.sizeLabel !== 'Pieza') {
        mods.push(line.sizeLabel);
      }
      line.mods.forEach(m => mods.push(m.name + (m.price ? ` (+$${fmt(m.price)})` : '')));
      li.innerHTML = `
        <div>
          <div class="ci-name">${escapeHtml(line.itemName)} × ${line.qty}</div>
          <div class="ci-mods">${escapeHtml(mods.join(' · '))}</div>
        </div>
        <div class="ci-actions">
          <button data-cart-remove="${line.uid}">−</button>
          <strong>$${fmt(line.lineTotal)}</strong>
        </div>`;
      ul.appendChild(li);
    });

    ul.querySelectorAll('[data-cart-remove]').forEach(b => {
      b.addEventListener('click', () => {
        state.cart = state.cart.filter(l => l.uid !== b.dataset.cartRemove);
        updateCartUI();
        if (state.cart.length === 0) $('#cart-modal').classList.add('hidden');
      });
    });
  }

  // ---- checkout ----
  async function checkout() {
    if (state.cart.length === 0) return;
    $('#cart-modal').classList.add('hidden');
    showPayState('connecting', 'Conectando con la terminal…', 'Espera un momento');
    $('#pay-modal').classList.remove('hidden');

    const total = state.cart.reduce((n, l) => n + l.lineTotal, 0);
    $('#pay-amount').textContent = '$' + fmt(total) + ' MXN';

    try {
      const res = await fetch(API + 'create-order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cart: state.cart }),
      });
      const data = await res.json();
      console.log('[kiosk] create-order response:', data);
      if (!res.ok || !data.ok) throw new Error(data.error || 'Error creando orden');

      state.orderId = data.order_id;

      if (data.terminal_pending) {
        showPayState('waiting', 'Toca o inserta tu tarjeta', 'En la terminal');
        $('#pay-cancel').classList.remove('hidden');
        pollPayment();
      } else if (data.terminal_error) {
        // backend tried to drive the terminal but Mercado Pago refused
        showPayState('error', 'Error en la terminal', data.terminal_error);
        $('#pay-cancel').classList.remove('hidden');
        $('#pay-cancel').textContent = 'Volver';
      } else {
        // terminal_enabled = false in config.php — order goes to kitchen as awaiting cash
        showPayState('success', '¡Pedido recibido!', 'Pasa a la caja a pagar');
        afterSuccess();
      }
    } catch (e) {
      showPayState('error', 'No se pudo iniciar el pago', e.message);
      $('#pay-cancel').classList.remove('hidden');
      $('#pay-cancel').textContent = 'Volver';
    }
  }

  function pollPayment() {
    clearTimeout(state.pollTimer);
    state.pollTimer = setTimeout(async () => {
      try {
        const res = await fetch(API + 'order-status.php?id=' + encodeURIComponent(state.orderId));
        const data = await res.json();
        if (data.status === 'paid') {
          showPayState('success', '¡Pago aprobado!', 'Tu pedido va a la cocina');
          afterSuccess();
          return;
        }
        if (data.status === 'rejected' || data.status === 'cancelled') {
          showPayState('error', 'Pago no completado', 'Intenta de nuevo o pasa a la caja');
          $('#pay-cancel').classList.remove('hidden');
          $('#pay-cancel').textContent = 'Volver';
          return;
        }
      } catch (e) { /* keep polling */ }
      pollPayment();
    }, POLL_PAY_MS);
  }

  function afterSuccess() {
    clearTimeout(state.pollTimer);
    $('#pay-cancel').classList.add('hidden');
    $('#pay-done').classList.remove('hidden');
  }

  function showPayState(kind, title, sub) {
    const el = $('#pay-state');
    el.classList.remove('success', 'error');
    if (kind === 'success') el.classList.add('success');
    if (kind === 'error') el.classList.add('error');
    $('#pay-title').textContent = title;
    $('#pay-sub').textContent = sub;
    $('#pay-cancel').classList.add('hidden');
    $('#pay-done').classList.add('hidden');
  }

  async function cancelPayment() {
    clearTimeout(state.pollTimer);
    if (state.orderId) {
      try {
        await fetch(API + 'cancel-order.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ order_id: state.orderId }),
        });
      } catch (e) { /* ignore */ }
    }
    state.orderId = null;
    closeAllModals();
    showScreen('menu');
  }

  function finishOrder() {
    state.cart = [];
    state.orderId = null;
    closeAllModals();
    updateCartUI();
    showScreen('attract');
  }

  // ---- event wiring ----
  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-action]');
    resetIdleTimer();
    if (!a) return;
    if (a.hasAttribute('disabled')) return;
    switch (a.dataset.action) {
      case 'start': showScreen('menu'); break;
      case 'cancel':
        state.cart = [];
        updateCartUI();
        showScreen('attract');
        break;
      case 'view-cart': $('#cart-modal').classList.remove('hidden'); break;
      case 'close-cart': $('#cart-modal').classList.add('hidden'); break;
      case 'close-item': $('#item-modal').classList.add('hidden'); state.editing = null; break;
      case 'add-to-cart': addEditingToCart(); break;
      case 'checkout': checkout(); break;
      case 'cancel-payment': cancelPayment(); break;
      case 'finish-order': finishOrder(); break;
    }
  });

  // any tap resets idle
  ['touchstart', 'mousedown'].forEach(ev =>
    document.addEventListener(ev, resetIdleTimer, { passive: true })
  );

  // disable context menu / pinch-zoom on tablet
  document.addEventListener('contextmenu', (e) => e.preventDefault());
  document.addEventListener('gesturestart', (e) => e.preventDefault());

  // boot
  loadMenu().catch(err => {
    document.body.innerHTML = '<div style="padding:40px;font-family:sans-serif;color:#a00">' +
      'Error cargando el menú: ' + escapeHtml(err.message) + '</div>';
  });
  resetIdleTimer();
})();
ction cancelPayment() {
    clearTimeout(state.pollTimer);
    if (state.orderId) {
      try {
        await fetch(API + 'cancel-order.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ order_id: state.orderId }),
        });
      } catch (e) { /* ignore */ }
    }
    state.orderId = null;
    closeAllModals();
    showScreen('menu');
  }

  function finishOrder() {
    state.cart = [];
    state.orderId = null;
    closeAllModals();
    updateCartUI();
    showScreen('attract');
  }

  // ---- event wiring ----
  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-action]');
    resetIdleTimer();
    if (!a) return;
    if (a.hasAttribute('disabled')) return;
    switch (a.dataset.action) {
      case 'start': showScreen('menu'); break;
      case 'cancel':
        state.cart = [];
        updateCartUI();
        showScreen('attract');
        break;
      case 'view-cart': $('#cart-modal').classList.remove('hidden'); break;
      case 'close-cart': $('#cart-modal').classList.add('hidden'); break;
      case 'close-item': $('#item-modal').classList.add('hidden'); state.editing = null; break;
      case 'add-to-cart': addEditingToCart(); break;
      case 'checkout': checkout(); break;
      case 'cancel-payment': cancelPayment(); break;
      case 'finish-order': finishOrder(); break;
    }
  });

  // any tap resets idle
  ['touchstart', 'mousedown'].forEach(ev =>
    document.addEventListener(ev, resetIdleTimer, { passive: true })
  );

  // disable context menu / pinch-zoom on tablet
  document.addEventListener('contextmenu', (e) => e.preventDefault());
  document.addEventListener('gesturestart', (e) => e.preventDefault());

  // boot
  loadMenu().catch(err => {
    document.body.innerHTML = '<div style="padding:40px;font-family:sans-serif;color:#a00">' +
      'Error cargando el menú: ' + escapeHtml(err.message) + '</div>';
  });
  resetIdleTimer();
})();
.addEventListener('contextmenu', (e) => e.preventDefault());
  document.addEventListener('gesturestart', (e) => e.preventDefault());

  // boot
  loadMenu().catch(err => {
    document.body.innerHTML = '<div style="padding:40px;font-family:sans-serif;color:#a00">' +
      'Error cargando el menú: ' + escapeHtml(err.message) + '</div>';
  });
  resetIdleTimer();
})();
