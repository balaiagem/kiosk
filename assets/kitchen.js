/* Kitchen Display — polls api/orders.php every 3s and renders cards */
(function () {
  'use strict';
  const API = 'api/';
  const POLL_MS = 3000;
  const AGED_AFTER_MS = 5 * 60 * 1000; // visually pulse a "new" order once it's >5min old

  let knownIds = new Set();
  let soundOn = true;
  let firstLoad = true;

  const $ = (s) => document.querySelector(s);

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

  $('#kt-sound-toggle').addEventListener('click', () => {
    soundOn = !soundOn;
    $('#kt-sound-toggle').textContent = soundOn ? '🔔 Alertas: ON' : '🔕 Alertas: OFF';
  });

  async function poll() {
    try {
      const res = await fetch(API + 'orders.php?_=' + Date.now());
      const data = await res.json();
      render(data.orders || []);
    } catch (e) { /* shrug — try again next tick */ }
    setTimeout(poll, POLL_MS);
  }

  function render(orders) {
    // count by status
    const counts = { new: 0, preparing: 0, ready: 0 };
    orders.forEach(o => { counts[o.status] = (counts[o.status] || 0) + 1; });
    $('#kt-pending').textContent = counts.new || 0;
    $('#kt-prep').textContent = counts.preparing || 0;
    $('#kt-ready').textContent = counts.ready || 0;

    // detect new
    const ids = new Set(orders.map(o => o.id));
    let hasFresh = false;
    orders.forEach(o => { if (!knownIds.has(o.id)) hasFresh = true; });
    if (hasFresh && !firstLoad && soundOn) {
      beep();
    }
    knownIds = ids;
    firstLoad = false;

    const grid = $('#kt-grid');
    grid.innerHTML = '';
    if (orders.length === 0) {
      $('#kt-empty').classList.remove('hidden');
      return;
    }
    $('#kt-empty').classList.add('hidden');

    orders.forEach(o => {
      const card = document.createElement('article');
      const aged = ageMs(o.created_at) > AGED_AFTER_MS && o.status === 'new';
      card.className = 'kt-card s-' + o.status + (aged ? ' aged' : '');
      const linesHtml = (o.lines || []).map(line => {
        const mods = [line.size_label].concat(
          (line.toppings || []).map(t => t.name)
        ).filter(Boolean);
        return `<li>
          <div class="name">${line.qty}× ${escapeHtml(line.item_name)}</div>
          <div class="mods">${escapeHtml(mods.join(' · '))}</div>
        </li>`;
      }).join('');

      const actions = o.status === 'new'
        ? `<button class="kt-btn-prep" data-id="${o.id}" data-to="preparing">Empezar</button>`
        : o.status === 'preparing'
          ? `<button class="kt-btn-ready" data-id="${o.id}" data-to="ready">Marcar listo</button>`
          : `<button class="kt-btn-done" data-id="${o.id}" data-to="done">Entregado</button>`;

      card.innerHTML = `
        <header>
          <div class="kt-num">#${escapeHtml(o.short_id || o.id)}</div>
          <div class="kt-time">${fmtTime(o.created_at)} · ${fmtAgo(o.created_at)}</div>
        </header>
        <ul class="kt-lines">${linesHtml}</ul>
        <div class="kt-actions">${actions}</div>`;
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
        poll();
      });
    });
  }

  // simple ding using Web Audio — no asset file needed
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
  clock();
  poll();
})();
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
  clock();
  poll();
})();
