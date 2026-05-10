/* Kitchen Display v3 — tabs (Activos / Completados), no flicker, time tick. */
(function () {
  'use strict';
  const API = 'api/';
  const POLL_MS = 3000;
  const TIME_TICK_MS = 60000;            // refresh "hace X min" every minute
  const AGED_AFTER_MS = 5 * 60 * 1000;   // pulse a "new" order after 5 min

  const $ = (s) => document.querySelector(s);
  const $$ = (s) => document.querySelectorAll(s);

  let knownIds = new Set();
  let soundOn = true;
  let firstLoad = true;
  let lastRenderHash = null;
  let currentTab = 'active';

  // ---- helpers ----
  function fmtTime(iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T') + 'Z');
    return d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
  }
  function ageMs(iso) {
    const d = new Date(iso.replace(' ', 'T') + 'Z');
    return Date.now() - d.getTime();
  }
  function fmtAgo(iso) {
    const m = Math.floor(ageMs(iso) / 60000);
    if (m < 1) return 'recién';
    if (m === 1) return 'hace 1 min';
    return 'hace ' + m + ' min';
  }
  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }
  function clock() {
    $('#kt-clock').textContent = new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
  }

  // ---- tab switching ----
  $$('.kt-tab').forEach(b => {
    b.addEventListener('click', () => {
      const tab = b.dataset.tab;
      if (tab === currentTab) return;
      currentTab = tab;
      $$('.kt-tab').forEach(x => x.classList.toggle('active', x.dataset.tab === tab));
      lastRenderHash = null;        // force redraw on tab change
      knownIds = new Set();         // don't beep for orders that already existed
      firstLoad = true;
      poll();                       // refresh immediately for the new tab
    });
  });

  $('#kt-sound-toggle').addEventListener('click', () => {
    soundOn = !soundOn;
    $('#kt-sound-toggle').textContent = soundOn ? '🔔 Alertas: ON' : '🔕 Alertas: OFF';
  });

  // ---- polling ----
  async function poll() {
    try {
      const res = await fetch(API + 'orders.php?tab=' + currentTab + '&_=' + Date.now());
      const data = await res.json();
      // always refresh the tab badges, regardless of which tab we're on
      if (data.counts) {
        $('#kt-badge-active').textContent = data.counts.active || 0;
        $('#kt-badge-done').textContent = data.counts.done || 0;
      }
      render(data.orders || []);
    } catch (e) { /* try again next tick */ }
    setTimeout(poll, POLL_MS);
  }

  // ---- render ----
  function render(orders) {
    // detect new orders for the chime
    const ids = new Set(orders.map(o => o.id));
    let hasFresh = false;
    if (currentTab === 'active') {
      orders.forEach(o => { if (!knownIds.has(o.id)) hasFresh = true; });
      if (hasFresh && !firstLoad && soundOn) beep();
    }
    knownIds = ids;
    firstLoad = false;

    // Skip rebuild when nothing changed (prevents flicker)
    const hash = orders.map(o => o.id + ':' + o.status + ':' + ((o.lines || []).length)).join('|');
    if (hash === lastRenderHash) return;
    lastRenderHash = hash;

    const grid = $('#kt-grid');
    grid.innerHTML = '';

    if (orders.length === 0) {
      $('#kt-empty').classList.remove('hidden');
      if (currentTab === 'done') {
        $('#kt-empty-title').textContent = 'Sin órdenes completadas';
        $('#kt-empty-sub').textContent = 'Las últimas 24 hrs aparecerán aquí';
      } else {
        $('#kt-empty-title').textContent = 'Sin pedidos pendientes';
        $('#kt-empty-sub').textContent = 'Esperando órdenes del kiosko…';
      }
      return;
    }
    $('#kt-empty').classList.add('hidden');

    orders.forEach(o => {
      const card = document.createElement('article');
      const aged = ageMs(o.created_at) > AGED_AFTER_MS && o.status === 'new';
      card.className = 'kt-card s-' + o.status + (aged ? ' aged' : '');
      card.dataset.created = o.created_at;
      const linesHtml = (o.lines || []).map(line => {
        const mods = [line.size_label].concat(
          (line.toppings || []).map(t => t.name)
        ).filter(Boolean);
        return '<li>' +
          '<div class="name">' + line.qty + '× ' + escapeHtml(line.item_name) + '</div>' +
          '<div class="mods">' + escapeHtml(mods.join(' · ')) + '</div>' +
          '</li>';
      }).join('');

      let actions = '';
      if (o.status === 'new') {
        actions = '<button class="kt-btn-prep" data-id="' + o.id + '" data-to="preparing">Empezar</button>';
      } else if (o.status === 'preparing') {
        actions = '<button class="kt-btn-ready" data-id="' + o.id + '" data-to="ready">Marcar listo</button>';
      } else if (o.status === 'ready') {
        actions = '<button class="kt-btn-done" data-id="' + o.id + '" data-to="done">Entregado</button>';
      }
      // 'done' status (in completados tab) shows no action button

      card.innerHTML =
        '<header>' +
          '<div class="kt-num">#' + escapeHtml(o.short_id || o.id) + '</div>' +
          '<div class="kt-time" data-time="' + o.created_at + '">' +
            fmtTime(o.created_at) + ' · ' + fmtAgo(o.created_at) +
          '</div>' +
        '</header>' +
        '<ul class="kt-lines">' + linesHtml + '</ul>' +
        (actions ? '<div class="kt-actions">' + actions + '</div>' : '');
      grid.appendChild(card);
    });

    grid.querySelectorAll('button[data-to]').forEach(b => {
      b.addEventListener('click', async () => {
        b.disabled = true;
        await fetch(API + 'update-order.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ order_id: b.dataset.id, status: b.dataset.to }),
        }).catch(() => {});
        lastRenderHash = null;       // force a redraw on next poll
        poll();
      });
    });
  }

  // refresh just the "hace X min" labels every minute without rebuilding cards
  function tickTimes() {
    document.querySelectorAll('.kt-time').forEach(el => {
      const iso = el.dataset.time;
      if (!iso) return;
      el.textContent = fmtTime(iso) + ' · ' + fmtAgo(iso);
    });
  }

  // ---- ding ----
  let audioCtx = null;
  function beep() {
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.value = 880;
      osc.connect(gain); gain.connect(audioCtx.destination);
      gain.gain.setValueAtTime(0.0001, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.4, audioCtx.currentTime + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.5);
      osc.start();
      osc.stop(audioCtx.currentTime + 0.55);
    } catch (e) { /* shrug */ }
  }

  setInterval(clock, 30000);
  setInterval(tickTimes, TIME_TICK_MS);
  clock();
  poll();
})();
