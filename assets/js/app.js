/* ATL Airport — frontend (polls PHP API, no page refresh) */
let __csrf = '';
const API = {
  async get(url) {
    const r = await fetch(url, { credentials: 'same-origin' });
    const text = await r.text();
    let j;
    try {
      j = JSON.parse(text);
    } catch (e) {
      const snip = (text || '').replace(/\s+/g, ' ').slice(0, 180);
      throw new Error('Server returned non-JSON (HTTP ' + r.status + '). Often missing tables or PHP error. Snippet: ' + snip);
    }
    if (!j.ok) throw new Error(j.error || 'Request failed');
    if (j.data && j.data.csrf) __csrf = j.data.csrf;
    return j.data;
  },
  async post(url, body) {
    const payload = Object.assign({}, body || {}, { csrf: __csrf || (body && body.csrf) || '' });
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': __csrf || '',
      },
      body: JSON.stringify(payload),
    });
    const text = await r.text();
    let j;
    try {
      j = JSON.parse(text);
    } catch (e) {
      const snip = (text || '').replace(/\s+/g, ' ').slice(0, 180);
      throw new Error('Server returned non-JSON (HTTP ' + r.status + '). Snippet: ' + snip);
    }
    if (!j.ok) throw new Error(j.error || 'Request failed');
    if (j.data && j.data.csrf) __csrf = j.data.csrf;
    return j.data;
  },
};

const ALL_SECTIONS = ['overview','flights','gates','airside','global','airspace','terminal','baggage','staff','fuel','transit','safety','weather'];
const SECTION_LABELS = {
  overview:'Overview', flights:'Flight Ops', gates:'Gates', airside:'Runway', global:'Global Traffic',
  airspace:'Local Radar', terminal:'Terminal', baggage:'Baggage', staff:'Staff', fuel:'Fuel',
  transit:'Transit', safety:'Safety', weather:'Weather'
};

let currentUser = null;
let currentView = 'overview';
let pollTimer = null;
let seenNotifIds = new Set();
window.__staffPage = 1;
window.__staffSearchQ = '';
window.__flightPage = 1;
window.__flightPageT = 1;
let flightFilter = 'all';
let gateFilter = 'all';
let bagQuery = '';

function bindTransitTabs() {
  document.querySelectorAll('#transitTabs [data-ttab]').forEach(btn => {
    if (btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', () => {
      window.__transitTab = btn.dataset.ttab;
      document.querySelectorAll('#transitTabs [data-ttab]').forEach(b => b.classList.toggle('active', b === btn));
      renderTransit();
    });
  });
}


/* ---------- Alerts / Siren / Critical border ---------- */
function showAlert(type, title, message, critical = false) {
  const stack = document.getElementById('alertStack');
  const el = document.createElement('div');
  el.className = `alert alert-${type}${critical ? ' critical' : ''}`;
  el.setAttribute('role', 'alert');
  el.innerHTML = `<div class="alert-body"><div class="alert-title">${escapeHtml(title)}</div><div>${escapeHtml(message || '')}</div></div>
    <button type="button" class="alert-close" aria-label="Close">&times;</button>`;
  el.querySelector('.alert-close').onclick = () => el.remove();
  stack.appendChild(el);
  if (!critical) setTimeout(() => el.remove(), 8000);
}

function escapeHtml(s) {
  return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

let __sirenCtx = null;
let __sirenTimer = null;
let __sirenOsc = [];

function stopSiren() {
  try {
    if (__sirenTimer) { clearInterval(__sirenTimer); __sirenTimer = null; }
    __sirenOsc.forEach(o => { try { o.stop(); } catch (e) {} });
    __sirenOsc = [];
    if (__sirenCtx) { try { __sirenCtx.close(); } catch (e) {} __sirenCtx = null; }
  } catch (e) {}
}

function playSiren(critical = false) {
  stopSiren();
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (ctx.state === 'suspended') ctx.resume();
    __sirenCtx = ctx;
    const master = ctx.createGain();
    master.gain.value = critical ? 0.18 : 0.08;
    master.connect(ctx.destination);

    const o1 = ctx.createOscillator();
    const o2 = ctx.createOscillator();
    const g1 = ctx.createGain();
    const g2 = ctx.createGain();
    o1.type = 'sawtooth';
    o2.type = 'triangle';
    g1.gain.value = 0.6;
    g2.gain.value = 0.4;
    o1.connect(g1); g1.connect(master);
    o2.connect(g2); g2.connect(master);
    o1.frequency.value = 780;
    o2.frequency.value = 520;
    o1.start();
    o2.start();
    __sirenOsc = [o1, o2];

    // Continuous two-tone sweep while critical mode is on
    let t = 0;
    __sirenTimer = setInterval(() => {
      if (!document.body.classList.contains('critical-mode') && critical) {
        stopSiren();
        return;
      }
      t++;
      const hi = critical ? 920 : 700;
      const lo = critical ? 480 : 500;
      const on = t % 2 === 0;
      try {
        o1.frequency.setTargetAtTime(on ? hi : lo, ctx.currentTime, 0.04);
        o2.frequency.setTargetAtTime(on ? hi * 1.2 : lo * 0.85, ctx.currentTime, 0.04);
        master.gain.setTargetAtTime(critical ? (on ? 0.2 : 0.1) : 0.06, ctx.currentTime, 0.05);
      } catch (e) {}
      // non-critical: stop after short burst
      if (!critical && t > 6) stopSiren();
    }, 320);
  } catch (e) { /* autoplay blocked */ }
}

function setCriticalMode(on) {
  document.body.classList.toggle('critical-mode', !!on);
  if (!on) stopSiren();
}

/* ---------- Auth ---------- */
async function loadCaptcha() {
  const d = await API.get('api/auth.php?action=captcha');
  const el = document.getElementById('captchaDisplay');
  if (el) el.textContent = d.captcha;
  window.__captchaNonce = d.nonce || '';
  const inp = document.getElementById('loginCaptcha');
  if (inp) { inp.value = ''; inp.maxLength = 6; inp.placeholder = '6 characters'; }
}
async function doLogin() {
  const username = document.getElementById('loginUser').value.trim();
  const password = document.getElementById('loginPass').value;
  const captcha = document.getElementById('loginCaptcha').value.trim();
  const err = document.getElementById('loginError');
  try {
    const loginData = await API.post('api/auth.php?action=login', { username, password, captcha, nonce: window.__captchaNonce || '' });
    if (loginData.csrf) __csrf = loginData.csrf;
    currentUser = loginData.user || loginData;
    err.textContent = '';
    document.body.classList.add('logged-in');
    document.getElementById('accountName').textContent = currentUser.full_name || currentUser.username;
    document.getElementById('accountInfo').innerHTML = `Signed in as<br><strong style="color:var(--text)">${escapeHtml(currentUser.username)}</strong><br><span style="font-size:11px">${escapeHtml(currentUser.role)}</span>`;
    applyPermissions();
    startPolling();
    switchView(currentUser.sections?.[0] || 'overview');
  } catch (e) {
    err.textContent = e.message;
    loadCaptcha();
    document.getElementById('loginCaptcha').value = '';
  }
}
function applyPermissions() {
  document.querySelectorAll('#sideNav button[data-view]').forEach(btn => {
    const ok = currentUser.role === 'admin' || (currentUser.sections || []).includes(btn.dataset.view);
    btn.classList.toggle('hidden', !ok);
  });
  const admin = currentUser.role === 'admin';
  document.getElementById('manageUsersBtn').style.display = admin ? '' : 'none';
  const a = document.getElementById('addUserFromStaff');
  if (a) a.style.display = admin ? '' : 'none';
}
async function logout() {
  try { await API.get('api/auth.php?action=logout'); } catch (e) {}
  currentUser = null;
  stopPolling();
  document.body.classList.remove('logged-in', 'critical-mode');
  document.getElementById('accountMenu').classList.remove('open');
  loadCaptcha();
}

/* ---------- Navigation ---------- */
const titles = {
  overview:'overview', flights:'flight ops', gates:'gates', airside:'runway + airside',
  global:'global traffic', airspace:'local radar', terminal:'terminal flow', baggage:'baggage',
  staff:'staff & gse', fuel:'fuel & energy', transit:'transit', safety:'safety & security', weather:'weather'
};
function switchView(v) {
  currentView = v;
  document.querySelectorAll('#sideNav button').forEach(b => b.classList.toggle('active', b.dataset.view === v));
  // Soft, fast panel transition
  document.querySelectorAll('.panel-view').forEach(p => {
    const on = p.id === 'view-' + v;
    if (on) {
      p.classList.add('active');
      p.classList.remove('is-leaving');
      p.classList.add('is-entering');
      requestAnimationFrame(() => {
        requestAnimationFrame(() => p.classList.remove('is-entering'));
      });
    } else {
      p.classList.remove('active', 'is-entering');
    }
  });
  document.getElementById('pageTitle').innerHTML = (titles[v] || v) + ' <span class="dot"></span>';
  // Force radar map build AFTER panel is visible (Leaflet needs real width/height)
  if (v === 'global' || v === 'airspace') {
    window['__radarLast_' + v] = 0;
    setTimeout(() => {
      try {
        if (v === 'global') renderGlobal();
        else renderAirspace();
        const mid = v === 'global' ? 'globalMap' : 'localMap';
        if (window.__radarMaps && window.__radarMaps[mid]) {
          window.__radarMaps[mid].invalidateSize(true);
        }
      } catch (e) { console.warn('radar open', e); }
    }, 80);
    setTimeout(() => {
      const mid = v === 'global' ? 'globalMap' : 'localMap';
      if (window.__radarMaps && window.__radarMaps[mid]) {
        try { window.__radarMaps[mid].invalidateSize(true); } catch (e) {}
      }
    }, 350);
  }
  // Explicit first paint for every section (fixes empty first open)
  const run = (fn) => {
    if (typeof fn !== 'function') return;
    Promise.resolve()
      .then(() => fn())
      .catch((e) => {
        console.warn('render failed', v, e);
        // one automatic retry after layout settles
        setTimeout(() => { try { fn(); } catch (err) { console.warn(err); } }, 120);
      });
  };
  if (v === 'overview') run(renderOverview);
  else if (v === 'flights') { if (!window.__flightPageTouched) window.__flightAroundNow = true; run(renderFlights); }
  else if (v === 'gates') run(renderGates);
  else if (v === 'airside') run(renderAirside);
  else if (v === 'global') { /* handled above with delay */ }
  else if (v === 'airspace') { /* handled above with delay */ }
  else if (v === 'terminal') run(renderTerminal);
  else if (v === 'baggage') run(renderBaggage);
  else if (v === 'staff') run(renderStaff);
  else if (v === 'fuel') run(renderFuel);
  else if (v === 'transit') run(renderTransit);
  else if (v === 'safety') run(renderSafety);
  else if (v === 'weather') run(renderWeather);
  else if (v === 'addflight') run(renderAddFlight);
  else run(() => refreshView());

  if (window.innerWidth <= 800) {
    document.getElementById('sidebar')?.classList.remove('open');
    document.getElementById('sidebarOverlay')?.classList.remove('show');
  }
}

/* ---------- Polling (live updates without refresh) ---------- */
let weatherTimer = null;
function startPolling() {
  stopPolling();
  tickAndRefresh();
  pollTimer = setInterval(tickAndRefresh, 8000);
  // Weather: soft refresh every 2 minutes
  weatherTimer = setInterval(() => {
    if (typeof renderWeather === 'function' && (currentView === 'weather' || document.getElementById('ovTemp'))) {
      try { if (currentView === 'weather') renderWeather(); } catch (e) {}
    }
  }, 120000);
}
function stopPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = null;
  if (weatherTimer) clearInterval(weatherTimer);
  weatherTimer = null;
}
async function tickAndRefresh() {
  try {
    await API.get('api/tick.php');
  } catch (e) { /* ignore tick errors */ }
  try {
    await refreshNotifications();
    await refreshView({ fromPoll: true });
  } catch (e) {
    console.warn(e);
  }
}
async function refreshNotifications() {
  const d = await API.get('api/data.php?section=notifications');
  // No emergency alerts — skip danger/critical entirely
  setCriticalMode(false);
  (d.notifications || []).forEach(n => {
    if (seenNotifIds.has(n.id)) return;
    if (n.is_critical == 1 || n.type === 'danger') return;
    seenNotifIds.add(n.id);
    showAlert(n.type || 'info', n.title, n.message, false);
  });
}
async function refreshView(opts) {
  if (!currentUser) return;
  const fromPoll = !!(opts && opts.fromPoll);
  // Transit: never full re-render on poll (causes panel flicker).
  if (currentView === 'transit') {
    if (!fromPoll) return renderTransit();
    if ((window.__transitTab || 'overview') === 'overview') {
      try { await refreshTransitLive(); } catch (e) { /* ignore */ }
    }
    return;
  }
  // Heavy panels: full render on view switch only — never rebuild on 8s poll
  if (currentView === 'staff') {
    if (fromPoll) return;
    return renderStaff();
  }
  if (currentView === 'baggage') {
    if (fromPoll) return;
    return renderBaggage();
  }
  if (currentView === 'flights') {
    if (fromPoll) return;
    return renderFlights();
  }
  if (currentView === 'gates') {
    if (fromPoll) return;
    return renderGates();
  }
  if (currentView === 'airside') {
    if (fromPoll) return;
    return renderAirside();
  }
  if (currentView === 'terminal') {
    if (fromPoll) return;
    return renderTerminal();
  }
  if (currentView === 'fuel') {
    if (fromPoll) return;
    return renderFuel();
  }
  // Overview: light panel but still throttle (every ~20s on poll)
  if (currentView === 'overview') {
    if (fromPoll) {
      const now = Date.now();
      if (window.__ovLast && now - window.__ovLast < 20000) return;
      window.__ovLast = now;
    }
    return renderOverview();
  }
  // Radar: throttle FR24 calls (credits) — auto-refresh at most every 8 minutes
  if (currentView === 'global' || currentView === 'airspace') {
    const key = '__radarLast_' + currentView;
    const now = Date.now();
    const RADAR_INTERVAL_MS = 8 * 60 * 1000; // 8 minutes
    if (!window[key] || now - window[key] > RADAR_INTERVAL_MS) {
      window[key] = now;
      return currentView === 'global' ? renderGlobal() : renderAirspace();
    }
    return;
  }
  if (currentView === 'safety') return renderSafety();
  if (currentView === 'weather') return renderWeather();
}

/** Lightweight transit update: only live rail cards + Atlanta clock, no DOM wipe */
async function refreshTransitLive() {
  const el = document.getElementById('transitPanel');
  if (!el || !el.querySelector('[data-live-rail]')) return;
  let d = {};
  try {
    d = await API.get('api/data.php?section=transit') || {};
  } catch (e) { return; }
  const clock = el.querySelector('[data-atl-clock]');
  if (clock && d.atlanta_now) clock.textContent = d.atlanta_now;
  const live = d.live || [];
  live.forEach((t, idx) => {
    const card = el.querySelector('[data-live-rail="' + (t.line || idx) + '"]');
    if (!card) return;
    const cur = card.querySelector('[data-live-current]');
    const next = card.querySelector('[data-live-next]');
    const eta = card.querySelector('[data-live-eta]');
    const bar = card.querySelector('[data-live-bar]');
    if (cur) cur.textContent = t.current || '—';
    if (next) next.textContent = t.next || '—';
    if (eta) eta.textContent = (t.eta_airport_min != null ? t.eta_airport_min + ' min' : '—');
    if (bar) bar.style.width = (t.progress_pct || 0) + '%';
  });
}

function statusPill(s) {
  if (['On Time','Scheduled','Arrived','Departed','Ready at Gate','found'].includes(s)) return `<span class="pill green">${s}</span>`;
  if (['Boarding','Deboarding','Cleaning'].includes(s)) return `<span class="pill cyan">${s}</span>`;
  if (s === 'Delayed' || s === 'missing' || s === 'damaged') return `<span class="pill amber">${s}</span>`;
  if (s === 'Cancelled' || s === 'Unknown') return `<span class="pill" style="background:rgba(242,92,110,.2);color:var(--red)">${s}</span>`;
  if (['Landing','Taxi to Gate','Taxi to Runway','Pushback','Takeoff','Final Call'].includes(s)) return `<span class="pill blue">${s}</span>`;
  return `<span class="pill">${s}</span>`;
}

/* ---------- Renderers ---------- */
async function renderOverview() {
  const d = await API.get('api/data.php?section=overview');
  document.getElementById('ovFlights').textContent = d.counts.flights;
  document.getElementById('ovDep').textContent = d.counts.dep;
  document.getElementById('ovArr').textContent = d.counts.arr;
  document.getElementById('ovOtp').textContent = (d.kpi.otp_pct != null ? d.kpi.otp_pct + '%' : '—');
  document.getElementById('ovAlerts').textContent = d.kpi.active_alerts ?? '—';
  // Live weather from Open-Meteo (same source as Weather section)
  try {
    const wx = await API.get('api/weather.php');
    const cur = wx.current || {};
    const icon = cur.condition === 'rain' ? '🌧' : cur.condition === 'cloudy' ? '☁' : cur.condition === 'partly' ? '⛅' : '☀';
    const cardIcon = document.querySelector('.weather-card .icon');
    if (cardIcon) cardIcon.textContent = icon;
    if (cur.temp_c != null) document.getElementById('ovTemp').textContent = cur.temp_c + '°C';
    const feels = cur.feels_c != null ? `Feels ${cur.feels_c}°` : '';
    const hum = cur.humidity != null ? `${cur.humidity}% hum` : '';
    document.getElementById('ovDay').textContent = [cur.condition || 'ATL', feels, hum].filter(Boolean).join(' · ');
  } catch (e) {
    if (d.weather) {
      document.getElementById('ovTemp').textContent = (d.weather.temp_c ?? '—') + '°C';
      document.getElementById('ovDay').textContent = d.weather.condition_label || '—';
    }
  }
  document.getElementById('delayedList').innerHTML = (d.delayed || []).map(f => `
    <div class="soft-row" style="grid-template-columns:90px 1fr 1fr 70px 70px 1fr;cursor:default">
      <span>${f.num}</span><span>${f.aircraft}</span><span>${f.origin}→${f.dest}</span>
      <span>${f.pax_accepted}</span><span>${f.delay_minutes ? f.delay_minutes + 'm' : '—'}</span>
      <span style="color:var(--muted)">${f.delay_reason || '—'}</span>
    </div>`).join('') || '<div class="soft-row" style="color:var(--muted)">No delayed flights</div>';
  document.getElementById('cancelledList').innerHTML = (d.cancelled || []).map(f => `
    <div class="soft-row" style="grid-template-columns:90px 1fr 1fr 70px 80px 1fr 1fr;cursor:default">
      <span>${f.flight_number}</span><span>${f.aircraft_code || '—'}</span><span>${f.destination || '—'}</span>
      <span>${f.pax}</span><span>${f.scheduled_time ? f.scheduled_time.substr(11,5) : '—'}</span>
      <span style="color:var(--muted)">${f.reason || '—'}</span>
      <span class="pill cyan">${f.replacement_flight || '—'}</span>
    </div>`).join('') || '<div class="soft-row" style="color:var(--muted)">None</div>';
}

async function renderFlights() {
  const page = window.__flightPage || 1;
  const pageT = window.__flightPageT || 1;
  const qFlight = window.__flightSearchQ || '';
  // First open (or explicit): jump to Atlanta "now" page
  const around = window.__flightAroundNow ? '&around_now=1' : '';
  if (window.__flightAroundNow) window.__flightAroundNow = false;
  const d = await API.get(
    'api/data.php?section=flights&filter=' + encodeURIComponent(flightFilter)
    + '&page=' + page + '&page_t=' + pageT + '&page_size=100'
    + (qFlight ? '&q=' + encodeURIComponent(qFlight) : '')
    + around
  );
  const board = document.getElementById('flightBoard');
  const titleEl = document.querySelector('#view-flights .sec-title span');
  const tot = d.total_today != null ? d.total_today : (d.today || []).length;
  const filtered = d.filtered_today != null ? d.filtered_today : tot;
  const totalPages = Number(d.total_pages) || 1;
  const totalPagesT = Number(d.total_pages_t) || 1;
  const cur = Number(d.page) || 1;
  const curT = Number(d.page_t) || 1;
  window.__flightPage = cur;
  window.__flightPageT = curT;

  if (titleEl) {
    titleEl.textContent = `flight ops · today (${Number(tot).toLocaleString()} ops`
      + (d.total_tomorrow ? `, ${Number(d.total_tomorrow).toLocaleString()} tomorrow` : '')
      + `) · page ${cur}/${totalPages}`;
  }

  if (!board) {
    console.warn('flightBoard missing');
    return;
  }
  board.innerHTML = (d.today || []).map(f => `
    <div class="soft-row" data-fid="${f.id}" style="grid-template-columns:90px 70px 70px 70px 60px 60px 1fr">
      <span>${f.num}</span><span>${f.origin}</span><span>${f.dest}</span><span>${f.aircraft}</span>
      <span>${f.gate}</span><span>${f.time}</span>${statusPill(f.status)}
    </div>`).join('') || '<div class="soft-row" style="color:var(--muted)">No flights on this page</div>';
  board.querySelectorAll('[data-fid]').forEach(r => r.onclick = () => openFlightDrawer((d.today || []).find(x => String(x.id) === r.dataset.fid)));

  const next = document.getElementById('nextDayBoard');
  if (next) {
    next.innerHTML = (d.tomorrow || []).map(f => `
      <div class="soft-row" data-fid="${f.id}" style="grid-template-columns:90px 70px 70px 70px 60px 60px 1fr">
        <span>${f.num}</span><span>${f.origin}</span><span>${f.dest}</span><span>${f.aircraft}</span>
        <span>${f.gate}</span><span>${f.time}</span>${statusPill(f.status)}
      </div>`).join('') || '<div class="soft-row" style="color:var(--muted)">No flights on this page</div>';
    next.querySelectorAll('[data-fid]').forEach(r => r.onclick = () => openFlightDrawer((d.tomorrow || []).find(x => String(x.id) === r.dataset.fid)));
  }
  const ndl = document.getElementById('nextDayLabel');
  if (ndl) ndl.textContent = new Date(Date.now() + 86400000).toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });

  // Today pager
  const pager = document.getElementById('flightPager');
  const lbl = document.getElementById('flightPageLabel');
  const prev = document.getElementById('flightPrevPage');
  const nxt = document.getElementById('flightNextPage');
  if (pager) {
    pager.style.display = 'flex';
    if (lbl) lbl.textContent = `Today · page ${cur} / ${totalPages} · ${Number(filtered).toLocaleString()} flights in filter`;
    if (prev) {
      prev.disabled = cur <= 1;
      prev.onclick = () => {
        if (window.__flightPage > 1) {
          window.__flightPageTouched = true; window.__flightPage--;
          renderFlights().then(() => {
            document.getElementById('flightBoard')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          });
        }
      };
    }
    if (nxt) {
      nxt.disabled = cur >= totalPages;
      nxt.onclick = () => {
        if (window.__flightPage < totalPages) {
          window.__flightPageTouched = true; window.__flightPage++;
          renderFlights().then(() => {
            document.getElementById('flightBoard')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          });
        }
      };
    }
  }

  // Tomorrow pager
  const pagerT = document.getElementById('flightPagerT');
  const lblT = document.getElementById('flightPageLabelT');
  const prevT = document.getElementById('flightPrevPageT');
  const nxtT = document.getElementById('flightNextPageT');
  if (pagerT) {
    pagerT.style.display = 'flex';
    if (lblT) lblT.textContent = `Tomorrow · page ${curT} / ${totalPagesT} · ${Number(d.total_tomorrow || 0).toLocaleString()} flights`;
    if (prevT) {
      prevT.disabled = curT <= 1;
      prevT.onclick = () => {
        if (window.__flightPageT > 1) {
          window.__flightPageT--;
          renderFlights().then(() => {
            document.getElementById('nextDayBoard')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          });
        }
      };
    }
    if (nxtT) {
      nxtT.disabled = curT >= totalPagesT;
      nxtT.onclick = () => {
        if (window.__flightPageT < totalPagesT) {
          window.__flightPageT++;
          renderFlights().then(() => {
            document.getElementById('nextDayBoard')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          });
        }
      };
    }
  }
}


function openFlightDrawer(f) {
  if (!f) return;
  document.getElementById('drawerTitle').textContent = `${f.num} · ${f.origin} → ${f.dest}${f.is_tomorrow ? ' (tomorrow)' : ''}`;
  document.getElementById('drawerBody').innerHTML = `
    <div style="position:relative;margin-bottom:12px">
      <img src="${f.aircraft_image || 'https://placehold.co/900x400/1c1f2b/5b8def?text=' + encodeURIComponent(f.aircraft)}"
        alt="${f.aircraft}" style="width:100%;height:160px;object-fit:cover;border-radius:12px;background:#12151f"
        onerror="this.src='https://placehold.co/900x400/1c1f2b/5b8def?text=${encodeURIComponent(f.aircraft)}'">
      <div style="position:absolute;bottom:10px;left:10px;background:rgba(0,0,0,.75);padding:4px 10px;border-radius:8px;font-size:12px;font-weight:700">${f.aircraft}</div>
    </div>
    <div class="detail-grid">
      <div class="detail-item"><div class="dlab">Flight</div><div class="dval">${f.num}</div></div>
      <div class="detail-item"><div class="dlab">Route</div><div class="dval">${f.origin} → ${f.dest}</div></div>
      <div class="detail-item"><div class="dlab">Aircraft</div><div class="dval">${f.aircraft}</div></div>
      <div class="detail-item"><div class="dlab">Gate</div><div class="dval">${f.gate}</div></div>
      <div class="detail-item"><div class="dlab">Scheduled time</div><div class="dval">${f.time}</div></div>
      <div class="detail-item"><div class="dlab">Live status</div><div class="dval">${statusPill(f.status)}</div></div>
      <div class="detail-item"><div class="dlab">Type</div><div class="dval">${f.type === 'dep' ? 'Take off' : 'Landing'}${f.is_international ? ' · International' : ' · Domestic'}</div></div>
      <div class="detail-item"><div class="dlab">Total seats</div><div class="dval">${f.seats_total}</div></div>
      <div class="detail-item"><div class="dlab">Passengers accepted</div><div class="dval">${f.pax_accepted}</div></div>
      <div class="detail-item"><div class="dlab">Bags</div><div class="dval">${f.bags_count}</div></div>
      <div class="detail-item"><div class="dlab">Cabin crew</div><div class="dval">${f.crew}</div></div>
    </div>
    <div class="card-title">Crew</div>
    <div style="font-size:13px;color:var(--text2);line-height:1.8">
      <strong>Pilot:</strong> ${f.pilot || '—'}<br>
      <strong>Co-pilot:</strong> ${f.copilot || '—'}
    </div>
    ${f.delay_reason ? `<div class="card-title" style="margin-top:12px">Delay</div><div style="color:var(--amber);font-size:13px">${f.delay_minutes || ''}m — ${f.delay_reason}</div>` : ''}`;
  document.getElementById('drawerOverlay').classList.add('open');
  document.getElementById('flightDrawer').classList.add('open');
}
function closeDrawer() {
  document.getElementById('drawerOverlay').classList.remove('open');
  document.getElementById('flightDrawer').classList.remove('open');
}

async function renderGates() {
  // Official ATL layout (user-specified counts)
  const GATE_LAYOUT = {
    T: { count: 21, type: 'domestic', label: 'Domestic' },
    A: { count: 34, type: 'domestic', label: 'Domestic' },
    B: { count: 35, type: 'domestic', label: 'Domestic' },
    C: { count: 34, type: 'domestic', label: 'Domestic' },
    D: { count: 40, type: 'domestic', label: 'Domestic' },
    E: { count: 28, type: 'international', label: 'International' },
    F: { count: 12, type: 'international', label: 'International' },
  };
  const d = await API.get('api/data.php?section=gates');
  const settings = d.settings || {};
  // Index DB gates by code for live status
  const byCode = {};
  (d.gates || []).forEach(g => { byCode[g.code] = g; });

  let html = '';
  Object.keys(GATE_LAYOUT).forEach(cid => {
    const layout = GATE_LAYOUT[cid];
    const cfg = settings[cid] || { type: layout.type };
    const type = cfg.type || layout.type;
    if (gateFilter === 'domestic' && type !== 'domestic') return;
    if (gateFilter === 'international' && type !== 'international') return;
    const badge = type === 'domestic' ? 'Domestic' : ('Intl · ' + (cfg.continent || '—'));
    const pillCls = type === 'domestic' ? 'blue' : 'cyan';
    html += `<div style="margin-bottom:18px">
      <div style="font-size:13px;color:var(--text2);margin-bottom:8px;font-weight:600">Concourse ${cid}
        <span class="pill ${pillCls}" style="margin-left:8px">${badge}</span>
        <span style="color:var(--muted);font-weight:500;margin-left:6px">· gates 1–${layout.count}</span>
      </div><div class="gate-grid">`;

    for (let n = 1; n <= layout.count; n++) {
      const code = cid + n;
      const g = byCode[code] || null;
      // Last 3 gates of each concourse = Reserve · Plan B (when no DB override)
      const isReserve = g ? (g.is_reserve == 1) : (n > layout.count - 3);
      const occ = g && g.status === 'occupied';
      const maint = g && g.status === 'maintenance';
      const closed = g && g.status === 'closed';
      let cls = 'gate-tile';
      if (isReserve) cls += ' reserve';
      if (occ) cls += ' occupied';
      if (maint || closed) cls += ' closed';
      let stat = 'Available';
      if (isReserve && !occ) stat = 'Reserve · Plan B';
      else if (occ) {
        const ft = g.flight_type === 'dep' ? 'Take off' : (g.flight_type === 'arr' ? 'Landing' : '');
        stat = (ft ? ft + ' · ' : '') + 'Occupied';
      } else if (maint) stat = 'Maintenance';
      else if (closed) stat = 'Closed';
      html += `<div class="${cls}" data-fid="${g && g.fid ? g.fid : ''}" data-code="${code}">
        <div><div class="gid">${code}</div><div class="gstat">${stat}</div></div>
        ${g && g.flight_number ? `<div class="gflight">${g.flight_number}</div>` : ''}
      </div>`;
    }
    html += '</div></div>';
  });
  document.getElementById('gatesContainer').innerHTML = html || '<div style="color:var(--muted);padding:12px">No gates</div>';
}

async function renderAirside() {
  const d = await API.get('api/data.php?section=airside');
  const runways = d.runways || [];
  // Map DB roles onto visual slots (ATL layout schematic)
  const byCode = {};
  runways.forEach(r => { byCode[r.code] = r; });
  const slots = [
    { key: '08L/26R', label: 'runway 1' },
    { key: '08R/26L', label: 'runway 2' },
    { key: '09L/27R', label: 'runway 3' },
    { key: '09R/27L', label: 'runway 4' },
    { key: '10/28',   label: 'runway 5' },
  ];
  function roleOf(code) {
    const r = byCode[code];
    return r ? (r.role || 'both') : 'both';
  }
  function roleColor(role) {
    if (role === 'takeoff') return 'var(--cyan)';
    if (role === 'landing') return 'var(--blue)';
    if (role === 'closed') return 'var(--red)';
    return 'var(--green)';
  }

  // Settings rows still use real codes
  const settingsEl = document.getElementById('rwySettingsRows');
  if (settingsEl) {
    settingsEl.innerHTML = (runways.length ? runways : slots.map(s => ({ code: s.key, role: 'both' }))).map(r => {
      const code = r.code || r.key;
      const role = r.role || 'both';
      return `<div class="settings-row" style="display:flex;gap:10px;align-items:center;margin-bottom:8px">
        <span style="width:90px;font-weight:600">${code}</span>
        <select data-rwy="${code}" class="inp" style="flex:1">
          <option value="both" ${role==='both'?'selected':''}>Both</option>
          <option value="takeoff" ${role==='takeoff'?'selected':''}>Takeoff only</option>
          <option value="landing" ${role==='landing'?'selected':''}>Landing only</option>
          <option value="closed" ${role==='closed'?'selected':''}>Closed</option>
        </select>
      </div>`;
    }).join('');
  }

  const topBars = slots.slice(0, 2).map(s => {
    const role = roleOf(s.key);
    return `<div class="rwy-bar" title="${s.key} · ${role}">
      <span class="rwy-bar-label">${s.label}</span>
      <span class="rwy-bar-code">${s.key}</span>
      <span class="rwy-bar-role" style="color:${roleColor(role)}">${role.toUpperCase()}</span>
    </div>`;
  }).join('');

  const bottomBars = slots.slice(2).map(s => {
    const role = roleOf(s.key);
    return `<div class="rwy-bar" title="${s.key} · ${role}">
      <span class="rwy-bar-label">${s.label}</span>
      <span class="rwy-bar-code">${s.key}</span>
      <span class="rwy-bar-role" style="color:${roleColor(role)}">${role.toUpperCase()}</span>
    </div>`;
  }).join('');

  const concourses = ['T','A','B','C','D','E'].map(c =>
    `<div class="airside-conc airside-conc-v"><span>Concourse ${c}</span></div>`
  ).join('');

  document.getElementById('rwyLive').innerHTML = `
    <div class="airside-schematic">
      <div class="airside-top">${topBars}</div>
      <div class="airside-mid">
        <div class="airside-conc-row">${concourses}</div>
        <div class="airside-f-block">
          <div class="airside-conc airside-conc-h"><span>Concourse F</span></div>
          <div class="airside-f-side"></div>
        </div>
      </div>
      <div class="airside-bottom">${bottomBars}</div>
    </div>`;
}


/* ---------- FR24 Radar maps (Local + Global) ---------- */
window.__radarMaps = window.__radarMaps || {};
window.__radarLayers = window.__radarLayers || {};

function ensureRadarMap(mapId, center, zoom) {
  if (typeof L === 'undefined') {
    console.error('Leaflet not loaded');
    const el0 = document.getElementById(mapId);
    if (el0) el0.innerHTML = '<div style="padding:24px;color:#f25c6e">Leaflet failed to load. Check network / CDN.</div>';
    return null;
  }
  const el = document.getElementById(mapId);
  if (!el) return null;

  // Force dimensions before Leaflet measures the container
  el.style.width = '100%';
  el.style.height = '520px';
  el.style.minHeight = '480px';
  el.style.display = 'block';
  el.style.background = '#0b0d12';

  if (window.__radarMaps[mapId]) {
    const m = window.__radarMaps[mapId];
    try { m.setView(center, zoom, { animate: false }); } catch (e) {}
    [0, 50, 150, 400, 800].forEach(ms => setTimeout(() => {
      try { m.invalidateSize(true); } catch (e) {}
    }, ms));
    return m;
  }

  // Create map after layout flush
  const map = L.map(el, {
    zoomControl: true,
    attributionControl: true,
    preferCanvas: false,
    worldCopyJump: true,
  }).setView(center, zoom);

  // Primary: OSM (most reliable). Secondary: Carto dark.
  const osm = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap · FR24 traffic',
    maxZoom: 19,
    crossOrigin: true,
  });
  const dark = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; OpenStreetMap &copy; CARTO · FR24',
    subdomains: 'abcd',
    maxZoom: 19,
    crossOrigin: true,
  });
  osm.addTo(map);

  [0, 100, 300, 600, 1200].forEach(ms => setTimeout(() => {
    try { map.invalidateSize(true); } catch (e) {}
  }, ms));

  window.__radarMaps[mapId] = map;
  window.__radarLayers[mapId] = L.layerGroup().addTo(map);
  return map;
}

/**
 * Yellow top-down AIRPLANE icon (not a star/cross).
 * Nose points NORTH (0°). FR24 track = true course → CSS rotate(track).
 */
function planeIcon(track) {
  let rot = Number(track);
  if (!isFinite(rot)) rot = 0;
  rot = ((rot % 360) + 360) % 360;
  // Airplane: nose, fuselage, wings, tailplane — clearly a plane, not a star
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 32 32" style="display:block;transform:rotate(${rot}deg);filter:drop-shadow(0 1px 2px rgba(0,0,0,.95))">
    <path fill="#facc15" stroke="#1c1917" stroke-width="0.9" stroke-linejoin="round"
      d="M16 2.2 L17.4 11.2 L29 15.2 L29 17 L17.5 18.6 L18.4 26.2 L21.5 28.2 L21.5 29.5 L16 27.4 L10.5 29.5 L10.5 28.2 L13.6 26.2 L14.5 18.6 L3 17 L3 15.2 L14.6 11.2 Z"/>
  </svg>`;
  return L.divIcon({
    className: 'radar-plane-icon',
    html: svg,
    iconSize: [28, 28],
    iconAnchor: [14, 14],
  });
}

async function fetchRadar(scope) {
  const d = await API.get('api/radar.php?scope=' + encodeURIComponent(scope));
  return d || {};
}

function paintRadar(mapId, flights, listBodyId, countId, metaId, focusLatLng) {
  const map = window.__radarMaps[mapId];
  const layer = window.__radarLayers[mapId];
  if (!map || !layer) return;
  layer.clearLayers();
  const rows = Array.isArray(flights) ? flights : [];
  rows.forEach(f => {
    const lat = Number(f.lat), lon = Number(f.lon);
    if (!isFinite(lat) || !isFinite(lon)) return;
    // Prefer FR24 track; fall back to heading; normalize 0–359
    let hdg = f.track != null ? Number(f.track) : (f.heading != null ? Number(f.heading) : 0);
    if (!isFinite(hdg)) hdg = 0;
    hdg = ((hdg % 360) + 360) % 360;
    const m = L.marker([lat, lon], { icon: planeIcon(hdg), rotationAngle: hdg, rotationOrigin: 'center center' });
    const cs = escapeHtml(f.callsign || f.flight || '—');
    const alt = f.alt != null ? Number(f.alt).toLocaleString() + ' ft' : '—';
    const spd = f.gspeed != null ? Math.round(Number(f.gspeed)) + ' kt' : '—';
    m.bindPopup(`<strong>${cs}</strong><br>Alt ${alt}<br>Speed ${spd}<br>Hdg ${Math.round(hdg)}°`);
    layer.addLayer(m);
  });
  if (focusLatLng && rows.length) {
    // keep ATL centered for local; for global don't auto-fit every poll
  }
  const body = document.getElementById(listBodyId);
  if (body) {
    body.innerHTML = rows.slice(0, 80).map(f => `
      <tr data-lat="${f.lat}" data-lon="${f.lon}">
        <td>${escapeHtml(f.callsign || f.flight || '—')}</td>
        <td>${f.alt != null ? Number(f.alt).toLocaleString() : '—'}</td>
        <td>${f.gspeed != null ? Math.round(Number(f.gspeed)) : '—'}</td>
        <td>${f.track != null ? f.track : '—'}</td>
      </tr>`).join('') || '<tr><td colspan="4" style="color:var(--muted)">No aircraft in view</td></tr>';
    body.querySelectorAll('tr[data-lat]').forEach(tr => {
      tr.onclick = () => {
        const lat = Number(tr.dataset.lat), lon = Number(tr.dataset.lon);
        if (isFinite(lat) && isFinite(lon)) map.setView([lat, lon], Math.max(map.getZoom(), 9));
      };
    });
  }
  const cnt = document.getElementById(countId);
  if (cnt) cnt.textContent = String(rows.length);
  const meta = document.getElementById(metaId);
  if (meta) {
    meta.textContent = (rows.length ? rows.length + ' aircraft · ' : '') + 'Flightradar24 · ' + (new Date().toLocaleTimeString());
  }
}

async function renderGlobal() {
  ensureRadarMap('globalMap', [39.8, -98.5], 4);
  const meta = document.getElementById('globalMeta');
  if (meta) meta.textContent = 'Loading Flightradar24…';
  try {
    const d = await fetchRadar('global');
    paintRadar('globalMap', d.flights || d.data || [], 'globalFlightBody', 'globalCount', 'globalMeta');
    window.__radarLast_global = Date.now();
  } catch (e) {
    if (meta) meta.textContent = e.message || 'Radar error';
    showAlert('danger', 'Global radar', e.message || 'Failed');
  }
}

async function renderAirspace() {
  // KATL
  ensureRadarMap('localMap', [33.6407, -84.4277], 9);
  const meta = document.getElementById('localMeta');
  if (meta) meta.textContent = 'Loading Flightradar24…';
  try {
    const d = await fetchRadar('local');
    paintRadar('localMap', d.flights || d.data || [], 'localFlightBody', 'localCount', 'localMeta', [33.6407, -84.4277]);
    // ATL marker once
    const map = window.__radarMaps.localMap;
    if (map && !window.__atlMarker) {
      window.__atlMarker = L.circleMarker([33.6407, -84.4277], {
        radius: 7, color: '#22d3ee', fillColor: '#22d3ee', fillOpacity: 0.85, weight: 2
      }).addTo(map).bindPopup('<strong>KATL</strong><br>Hartsfield–Jackson Atlanta');
    }
    window.__radarLast_airspace = Date.now();
  } catch (e) {
    if (meta) meta.textContent = e.message || 'Radar error';
    showAlert('danger', 'Local radar', e.message || 'Failed');
  }
}

/** Manual refresh: always hits FR24 and resets the 8-minute throttle */
function forceRadarRefresh(scope) {
  if (scope === 'global') {
    window.__radarLast_global = 0;
    return renderGlobal();
  }
  window.__radarLast_airspace = 0;
  return renderAirspace();
}

// Wire Refresh buttons (manual override of 8-min throttle)
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('btnRefreshGlobal')?.addEventListener('click', () => forceRadarRefresh('global'));
  document.getElementById('btnRefreshLocal')?.addEventListener('click', () => forceRadarRefresh('local'));
});

async function renderTerminal() {
  const d = await API.get('api/data.php?section=terminal');
  const GATE_TOTAL = { T:21, A:34, B:35, C:34, D:40, E:28, F:12 };
  const ORDER = ['T','A','B','C','D','E','F'];
  const usage = {};
  (d.gate_usage || []).forEach(u => { usage[u.terminal] = u; });
  const zonesBy = {};
  (d.zones || []).forEach(z => {
    const t = (z.zone_code || '').replace(/^conc_?/i, '').toUpperCase().charAt(0);
    if (t) zonesBy[t] = z;
  });
  const settings = d.settings || {};
  document.getElementById('terminalCards').innerHTML = ORDER.map(term => {
    const cfg = settings[term] || { type: ['T','A','B'].includes(term) ? 'domestic' : 'international', continent: null };
    const type = cfg.type || 'domestic';
    const contMap = { europe:'europe', namerica:'namerica', asia:'asia', samerica:'samerica' };
    const region = type === 'domestic'
      ? 'Domestic US'
      : ('International · ' + (cfg.continent || contMap[{C:'europe',D:'namerica',E:'asia',F:'samerica'}[term]] || '—'));
    const u = usage[term] || {};
    const total = Number(u.total) || GATE_TOTAL[term] || 0;
    const used = Number(u.used) || 0;
    const pct = total ? Math.round(100 * used / total) : 0;
    const z = zonesBy[term] || {};
    const density = z.density_pct != null ? z.density_pct : '—';
    const peak = z.peak_pct != null ? z.peak_pct : (z.density_pct != null ? Math.min(98, Number(z.density_pct) + 8) : '—');
    const turn = z.avg_turnaround_min != null ? z.avg_turnaround_min : (z.wait_minutes != null ? z.wait_minutes : '—');
    const pillCls = type === 'domestic' ? 'blue' : 'cyan';
    return `<div class="term-card">
      <h4>Concourse ${term} <span class="pill ${pillCls}">${type}</span></h4>
      <div class="term-meta">${region}</div>
      <div class="term-meta" style="margin-top:10px">Gate usage now: <strong>${used}/${total}</strong> (${pct}%)</div>
      <div class="bar-track"><div class="bar-fill" style="width:${pct}%"></div></div>
      <div class="term-meta">Current density: <strong>${density}${density!=='—'?'%':''}</strong> · Peak today: <strong>${peak}${peak!=='—'?'%':''}</strong></div>
      <div class="term-meta">Avg gate turnaround: <strong>${turn}${turn!=='—'?' min':''}</strong></div>
    </div>`;
  }).join('');
}

async function renderBaggage() {
  const q = typeof bagQuery !== 'undefined' ? bagQuery : (document.getElementById('bagSearch')?.value || '');
  const d = await API.get('api/data.php?section=baggage&q=' + encodeURIComponent(q || ''));
  const flights = d.flights || [];
  const bags = d.bags || [];

  const byFlight = {};
  bags.forEach(b => {
    const k = b.flight_number || 'UNKNOWN';
    (byFlight[k] = byFlight[k] || []).push(b);
  });

  let html = '';
  if (q) {
    // Search results — show matched bag rows
    html = Object.keys(byFlight).map(fn => {
      const list = byFlight[fn];
      return `<div class="card" style="margin-bottom:12px">
        <div class="card-title">✈ ${escapeHtml(fn)} <span class="pill cyan">${list.length} match</span></div>
        <div class="soft-head" style="grid-template-columns:120px 90px 1.4fr 70px 90px"><span>Bag ID</span><span>Flight</span><span>Owner</span><span>Weight</span><span>Status</span></div>
        <div class="soft-table">${list.map(b => `
          <div class="soft-row" style="grid-template-columns:120px 90px 1.4fr 70px 90px">
            <span>${escapeHtml(b.bag_id||'')}</span><span>${escapeHtml(b.flight_number||'')}</span>
            <span>${escapeHtml(b.owner_name||'—')}</span><span>${b.weight_kg ?? '—'} kg</span>
            <span>${escapeHtml(b.status||'')}</span>
          </div>`).join('')}</div></div>`;
    }).join('') || '<div class="card" style="color:var(--muted)">No bags matched</div>';
  } else {
    // Fast overview: every flight with declared bag count 250–300
    const meta = `<div style="font-size:12px;color:var(--muted);margin-bottom:12px">Declared bags today: <strong style="color:var(--cyan)">${Number(d.total_bags_declared||0).toLocaleString()}</strong> · tracked rows: ${Number(d.total_bag_rows||0).toLocaleString()} · click a flight to load bag list</div>`;
    html = meta + (flights.map(f => {
      const n = Number(f.bags_count || 0);
      return `<div class="card bag-flight-card" style="margin-bottom:10px;cursor:pointer" data-fn="${escapeHtml(f.flight_number||'')}">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
          <div>
            <div style="font-weight:700">✈ ${escapeHtml(f.flight_number||'—')} · ${escapeHtml(f.origin||'')} → ${escapeHtml(f.destination||'')}</div>
            <div style="font-size:12px;color:var(--muted);margin-top:4px">${escapeHtml(f.status||'')} · pax ${f.pax_accepted ?? '—'}</div>
          </div>
          <div style="text-align:right">
            <div style="font-size:22px;font-weight:700;color:var(--cyan)">${n.toLocaleString()}</div>
            <div style="font-size:11px;color:var(--muted)">bags (250–300)</div>
          </div>
        </div>
        <div class="bag-detail" id="bagDetail_${escapeHtml(f.flight_number||'')}" style="display:none;margin-top:12px"></div>
      </div>`;
    }).join('') || '<div class="card" style="color:var(--muted)">No flights with bags — run Full Seed</div>');
  }
  document.getElementById('bagsByFlight').innerHTML = html;

  // Expand flight → load up to 320 bags for that flight
  document.querySelectorAll('.bag-flight-card').forEach(card => {
    card.onclick = async (ev) => {
      if (ev.target.closest('.bag-detail')) return;
      const fn = card.dataset.fn;
      const box = document.getElementById('bagDetail_' + fn);
      if (!box) return;
      if (box.style.display === 'block') { box.style.display = 'none'; return; }
      box.style.display = 'block';
      box.innerHTML = '<div style="color:var(--muted);font-size:12px">Loading bags…</div>';
      try {
        const dd = await API.get('api/data.php?section=baggage&flight=' + encodeURIComponent(fn));
        const list = dd.bags || [];
        box.innerHTML = `<div style="font-size:12px;color:var(--muted);margin-bottom:8px">Showing ${list.length} bag records for ${escapeHtml(fn)}</div>
          <div class="soft-head" style="grid-template-columns:120px 1.4fr 70px 90px 90px"><span>Bag ID</span><span>Owner</span><span>Weight</span><span>Status</span><span>Belt</span></div>
          <div class="soft-table" style="max-height:320px;overflow:auto">${list.map(b => `
            <div class="soft-row" style="grid-template-columns:120px 1.4fr 70px 90px 90px">
              <span>${escapeHtml(b.bag_id||'')}</span><span>${escapeHtml(b.owner_name||'—')}</span>
              <span>${b.weight_kg ?? '—'} kg</span><span>${escapeHtml(b.status||'')}</span>
              <span>${escapeHtml(b.belt_code || b.location || '—')}</span>
            </div>`).join('') || '<div class="soft-row">No bag rows — re-run seed</div>'}</div>`;
      } catch (e) {
        box.innerHTML = `<div style="color:var(--red)">${escapeHtml(e.message||'Error')}</div>`;
      }
    };
  });

  const lost = d.lost || [];
  const damaged = d.damaged || [];
  const lostEl = document.getElementById('lostBagSummary');
  const dmgEl = document.getElementById('damagedBagSummary');
  if (lostEl) lostEl.innerHTML = lost.map(b => `<div class="soft-row" style="grid-template-columns:1fr 1fr"><span>${escapeHtml(b.bag_id||'')}</span><span>${escapeHtml(b.flight_number||'')}</span></div>`).join('') || '<div style="color:var(--muted)">None</div>';
  if (dmgEl) dmgEl.innerHTML = damaged.map(b => `<div class="soft-row" style="grid-template-columns:1fr 1fr"><span>${escapeHtml(b.bag_id||'')}</span><span>${escapeHtml(b.flight_number||'')}</span></div>`).join('') || '<div style="color:var(--muted)">None</div>';
}


async function renderStaff() {
  const dept = document.getElementById('staffDeptFilter')?.value || 'all';
  const statusF = window.__staffStatusFilter || 'all';
  const page = window.__staffPage || 1;
  const q = window.__staffSearchQ || '';
  const url = 'api/data.php?section=staff'
    + '&dept=' + encodeURIComponent(dept)
    + '&status=' + encodeURIComponent(statusF === 'all' ? '' : statusF)
    + '&page=' + page
    + '&page_size=200'
    + (q ? '&q=' + encodeURIComponent(q) : '');
  const d = await API.get(url);
  const setTxt = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
  setTxt('stTotal', d.counts.total ?? '-');
  setTxt('stDuty', d.counts.on_duty ?? '-');
  setTxt('stBreak', d.counts.on_break ?? '-');
  setTxt('stOff', d.counts.off_shift ?? '-');

  const shown = (d.staff || []).length;
  const filtered = Number(d.filtered_total != null ? d.filtered_total : shown);
  const totalAll = Number(d.counts.total) || filtered;
  const totalPages = Number(d.total_pages) || 1;
  const curPage = Number(d.page) || 1;
  window.__staffPage = curPage;

  const noteEl = document.getElementById('staffShowingNote');
  if (noteEl) {
    const from = filtered ? ((curPage - 1) * (d.page_size || 200) + 1) : 0;
    const to = Math.min(curPage * (d.page_size || 200), filtered);
    noteEl.textContent = filtered
      ? `Showing ${from.toLocaleString()}–${to.toLocaleString()} of ${filtered.toLocaleString()} matched · roster total ${totalAll.toLocaleString()}`
      : `No staff matched · roster total ${totalAll.toLocaleString()}`;
  }

  const sel = document.getElementById('staffDeptFilter');
  if (sel && !(sel.dataset.ready)) {
    (d.departments || []).forEach(dep => {
      const o = document.createElement('option');
      o.value = dep.code;
      o.textContent = `${dep.name} (${dep.cnt})`;
      sel.appendChild(o);
    });
    sel.dataset.ready = '1';
    sel.onchange = () => { window.__staffPage = 1; renderStaff(); };
  }

  const baseIdx = (curPage - 1) * (d.page_size || 200);
  document.getElementById('staffRosterBody').innerHTML = (d.staff || []).map((s, i) => `
    <tr>
      <td>${baseIdx + i + 1}</td>
      <td>${s.employee_code || '—'}</td>
      <td>${s.first_name} ${s.last_name}</td>
      <td>${s.role}</td>
      <td>${s.dept_code || s.dept_name || '—'}</td>
      <td>${s.shift}</td>
      <td>${s.zone || '—'}</td>
      <td>
        <select class="staff-status-sel" data-sid="${s.id}" style="font-size:12px;padding:4px 6px;border-radius:6px;background:#12151f;color:var(--text);border:1px solid var(--border)">
          <option value="on_duty" ${s.status==='on_duty'?'selected':''}>On duty</option>
          <option value="break" ${s.status==='break'?'selected':''}>Break</option>
          <option value="off" ${s.status==='off'?'selected':''}>Off</option>
        </select>
      </td>
    </tr>`).join('') || '<tr><td colspan="8" style="color:var(--muted)">No staff on this page</td></tr>';

  document.querySelectorAll('.staff-status-sel').forEach(sel => {
    sel.onchange = async () => {
      try {
        await API.post('api/actions.php', { action: 'update_staff_status', id: sel.dataset.sid, status: sel.value });
        showAlert('success', 'Updated', 'Staff status saved');
      } catch (e) { showAlert('danger', 'Error', e.message); }
    };
  });

  // Pagination controls — only the roster panel
  const pager = document.getElementById('staffPager');
  const lbl = document.getElementById('staffPageLabel');
  const prev = document.getElementById('staffPrevPage');
  const next = document.getElementById('staffNextPage');
  if (pager) {
    pager.style.display = totalPages > 1 ? 'flex' : (filtered > 0 ? 'flex' : 'none');
    if (lbl) lbl.textContent = `Page ${curPage} / ${totalPages}`;
    if (prev) {
      prev.disabled = curPage <= 1;
      prev.onclick = () => {
        if (window.__staffPage > 1) {
          window.__staffPage--;
          renderStaff().then(() => {
            document.getElementById('staffShowingNote')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          });
        }
      };
    }
    if (next) {
      next.disabled = curPage >= totalPages;
      next.onclick = () => {
        if (window.__staffPage < totalPages) {
          window.__staffPage++;
          renderStaff().then(() => {
            document.getElementById('staffShowingNote')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          });
        }
      };
    }
  }

  // Search + suggestions (bind once)
  const search = document.getElementById('staffSearchInput');
  const box = document.getElementById('staffSuggestBox');
  if (search && !search.dataset.bound) {
    search.dataset.bound = '1';
    let tmr = null;
    search.value = window.__staffSearchQ || '';
    search.addEventListener('input', () => {
      clearTimeout(tmr);
      const val = search.value.trim();
      tmr = setTimeout(async () => {
        window.__staffSearchQ = val;
        window.__staffPage = 1;
        if (val.length === 0) {
          if (box) box.style.display = 'none';
          renderStaff();
          return;
        }
        try {
          const sug = await API.get('api/data.php?section=staff&q=' + encodeURIComponent(val) + '&page=1&page_size=12');
          if (box) {
            const list = sug.suggestions || [];
            if (!list.length) {
              box.innerHTML = '<button type="button" disabled>No matches</button>';
            } else {
              box.innerHTML = list.map(s =>
                `<button type="button" data-q="${escapeHtml((s.first_name + ' ' + s.last_name).trim())}">${escapeHtml(s.first_name + ' ' + s.last_name)} <span class="sug-meta">${escapeHtml(s.employee_code || '')} · ${escapeHtml(s.dept_code || '')}</span></button>`
              ).join('');
              box.querySelectorAll('button[data-q]').forEach(b => {
                b.onclick = () => {
                  search.value = b.dataset.q;
                  window.__staffSearchQ = b.dataset.q;
                  window.__staffPage = 1;
                  box.style.display = 'none';
                  renderStaff();
                };
              });
            }
            box.style.display = 'block';
          }
          renderStaff();
        } catch (e) {
          if (box) box.style.display = 'none';
        }
      }, 220);
    });
    search.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && box) box.style.display = 'none';
      if (ev.key === 'Enter') {
        ev.preventDefault();
        window.__staffSearchQ = search.value.trim();
        window.__staffPage = 1;
        if (box) box.style.display = 'none';
        renderStaff();
      }
    });
    document.addEventListener('click', (ev) => {
      if (box && !box.contains(ev.target) && ev.target !== search) box.style.display = 'none';
    });
  }

  // users table if present
  if (typeof renderUsersTable === 'function' && d.users) {
    try { renderUsersTable(d.users); } catch (e) {}
  }
}


async function renderFuel() {
  const d = await API.get('api/data.php?section=fuel');
  window.__fuelData = d;
  const vals = Array(12).fill(0);
  (d.energy || []).forEach(e => { vals[e.month - 1] = Number(e.mwh); });
  const colors = ['#3dd68c','#3dd68c','#5b8def','#5b8def','#f0b429','#f25c6e','#f25c6e','#f0b429','#3a3d4d','#3a3d4d','#3a3d4d','#3a3d4d'];
  document.getElementById('powerChart').innerHTML = vals.map((v, i) => {
    const h = Math.max(4, Math.min(100, v));
    return `<div style="flex:1;height:${h}%;background:${colors[i]};border-radius:4px 4px 0 0;opacity:${i >= 8 ? .3 : 1}" title="${v} MWh"></div>`;
  }).join('');
  const low = (d.tanks || []).filter(t => Number(t.level_pct) <= 40);
  const alert = document.getElementById('tankAlert');
  if (alert) {
    if (low.length) {
      alert.style.display = 'block';
      alert.textContent = 'Fuel running low (≤40%): ' + low.map(t => `${t.name} (${t.level_pct}%)`).join(', ') + ' — pipeline top-up recommended';
      // one-shot toast per tank per session
      window.__fuelWarned = window.__fuelWarned || {};
      low.forEach(t => {
        const k = String(t.id);
        if (!window.__fuelWarned[k]) {
          window.__fuelWarned[k] = true;
          showAlert(Number(t.level_pct) <= 20 ? 'danger' : 'warning', 'Fuel running low', `${t.name} is at ${t.level_pct}% — pipeline fill recommended`);
        }
      });
    } else {
      alert.style.display = 'none';
    }
  }
  document.getElementById('tankRow').innerHTML = (d.tanks || []).map(t => {
    const capL = Math.round(Number(t.capacity_gal) * 3.78541);
    const litNow = Math.round(capL * t.level_pct / 100);
    const pct = Number(t.level_pct) || 0;
    const col = pct <= 40 ? 'var(--red)' : pct < (t.low_threshold_pct || 40) ? 'var(--amber)' : 'linear-gradient(180deg,#f0b429,#c48a10)';
    return `<div class="fuel-tank-card" data-tank-id="${t.id}" style="text-align:center">
      <div class="fuel-tank-shell">
        <div class="fuel-tank-fill" data-pct="${pct}" style="height:0%;background:${col}"></div>
      </div>
      <div style="font-size:12px;font-weight:600;margin-top:6px">${escapeHtml(t.name)}</div>
      <div class="fuel-tank-pct" style="font-size:13px">${pct}%</div>
      <div style="font-size:10px;color:var(--muted)">${litNow.toLocaleString()} / ${capL.toLocaleString()} L</div>
      <div style="font-size:10px;color:var(--muted)">${(t.fuel_type||'jet_a').toUpperCase()}</div>
    </div>`;
  }).join('');
  // Smooth fill animation
  requestAnimationFrame(() => {
    document.querySelectorAll('.fuel-tank-fill').forEach(el => {
      const p = el.getAttribute('data-pct') || '0';
      el.style.transition = 'height 1.35s cubic-bezier(.22,1,.36,1), box-shadow .6s ease';
      el.style.boxShadow = '0 0 18px rgba(240,180,41,.35)';
      el.style.height = p + '%';
    });
  });
}

async function renderTransit() {
  const el = document.getElementById('transitPanel');
  if (!el) return;
  let tab = window.__transitTab || 'overview';
  if (!['overview','taxi','metro'].includes(tab)) { tab = 'overview'; window.__transitTab = 'overview'; }
  // Keep filter-bar highlight in sync with active tab
  document.querySelectorAll('#transitTabs [data-ttab]').forEach(b => {
    b.classList.toggle('active', b.dataset.ttab === tab);
  });
  bindTransitTabs();


  let d = {};
  try {
    d = await API.get('api/data.php?section=transit') || {};
  } catch (e) {
    el.innerHTML = `<div class="card" style="color:var(--red)">${escapeHtml(e.message || 'Transit API error')}</div>`;
    return;
  }

  const s = d.summary || {};
  const vehicles = d.vehicles || [];
  const fares = d.fares || [];
  const live = d.live || [];
  const daily = d.daily_stats || [];
  const dailyMap = {};
  daily.forEach(r => {
    const k = (r.mode || '') + ':' + (r.service_class || '');
    dailyMap[k] = (dailyMap[k] || 0) + Number(r.trips || r.trip_count || 0);
  });

  const sumMode = (mode) => daily.filter(r => r.mode === mode).reduce((a, r) => a + Number(r.trips || r.trip_count || 0), 0);
  // City MARTA rail arrivals/departures at airport today
  // Live operational counters (Atlanta local time since midnight)
  // MARTA: each arrival at Airport Station counts as 1 (Red + Gold ≈ every 10–12 min each)
  // Plane Train: each full Domestic↔T–F circuit counts as 1 (≈ every 3–4 min)
  function atlMinutesSinceMidnight() {
    try {
      const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: 'America/New_York', hour: '2-digit', minute: '2-digit', hour12: false
      }).formatToParts(new Date());
      const h = Number(parts.find(p => p.type === 'hour')?.value || 0);
      const m = Number(parts.find(p => p.type === 'minute')?.value || 0);
      const hh = h === 24 ? 0 : h;
      return hh * 60 + m;
    } catch (e) {
      const n = new Date();
      return n.getHours() * 60 + n.getMinutes();
    }
  }
  const mins = atlMinutesSinceMidnight();
  const dbMarta = sumMode('marta') || Number(s.marta_trips || 0) || dailyMap['metro:marta_rail'] || dailyMap['marta:marta_rail'] || 0;
  const dbPlane = dailyMap['metro:plane_train'] || dailyMap['plane_train:plane_train'] || Number(s.plane_train_trips || 0) || 0;
  // ~2 lines × 1 arrival / 11 min → busy corridor volume
  const martaLive = Math.max(1, Math.floor(mins / 11) * 2 + Math.floor(mins / 25));
  // Full Plane Train circuits: one counted each ~3.2 minutes of continuous APM operation
  const planeLive = Math.max(1, Math.floor(mins / 3.2) + Math.floor(mins / 8));
  const martaShown = Math.max(dbMarta, martaLive);
  const planeShown = Math.max(dbPlane, planeLive);

  const taxiVehicles = vehicles.filter(v => v.fleet_type === 'taxi');

  // Official ATL taxi zone fares (authorized partners — not airport-owned)
  const taxiZones = [
    { zone: 'Zone 1', dest: 'Downtown Atlanta', fare: '$37.50', extra: '+$2 per extra passenger', note: 'Includes $1.50 airport surcharge' },
    { zone: 'Zone 3', dest: 'Midtown Atlanta', fare: '$39.50', extra: '+$2 per extra passenger', note: 'Includes $1.50 airport surcharge' },
    { zone: 'Zone 2', dest: 'Buckhead', fare: '$49.50', extra: '+$2 per extra passenger', note: 'Includes $1.50 airport surcharge' },
  ];

  // ---- helpers ----
  function taxiListHtml(list) {
    if (!list.length) return '<div style="color:var(--muted);padding:12px">No taxi vehicles in database yet. Use ＋ Add taxi.</div>';
    return `<div style="overflow:auto;max-height:420px"><table class="user-table">
      <thead><tr><th>Code</th><th>Plate</th><th>Model</th><th>Driver</th><th>Company</th><th>Status</th><th>Trips</th><th></th></tr></thead>
      <tbody>
      ${list.map(v => `<tr>
        <td>${escapeHtml(v.vehicle_code || '—')}</td>
        <td>${escapeHtml(v.plate_number || '—')}</td>
        <td>${escapeHtml(((v.manufacturer || '') + ' ' + (v.model || '')).trim() || '—')}</td>
        <td>${escapeHtml(v.driver_name || '—')}</td>
        <td>${escapeHtml(v.company || '—')}</td>
        <td>${escapeHtml(v.status || '—')}</td>
        <td>${v.trips_today != null ? v.trips_today : '—'}</td>
        <td><button class="btn sm" type="button" data-edit-taxi="${v.id}">Settings</button></td>
      </tr>`).join('')}
      </tbody></table></div>`;
  }

  function bindTaxiActions() {
    const addBtn = document.getElementById('btnAddTaxi');
    if (addBtn) addBtn.onclick = () => openVehicleModal('taxi', null);
    document.querySelectorAll('[data-edit-taxi]').forEach(btn => {
      btn.onclick = () => {
        const v = taxiVehicles.find(x => String(x.id) === btn.dataset.editTaxi);
        openVehicleModal('taxi', v || null);
      };
    });
  }

  // Simulated live MARTA / Plane Train state (stable per page load, advances with clock)
  function metroLiveState() {
    const cityStations = [
      { line: 'Red Line', toward: 'North / Downtown', stations: ['Airport', 'College Park', 'East Point', 'Lakewood/Ft. McPherson', 'Oakland City', 'West End', 'Garnett', 'Five Points'] },
      { line: 'Gold Line', toward: 'NE / Doraville', stations: ['Airport', 'College Park', 'East Point', 'Lakewood/Ft. McPherson', 'Oakland City', 'West End', 'Garnett', 'Five Points'] },
    ];
    const now = Date.now();
    const trains = cityStations.map((L, idx) => {
      // cycle every ~11 minutes (typical headway 10–12 min)
      const cycle = 11 * 60 * 1000;
      const phase = (now + idx * 4 * 60 * 1000) % cycle;
      const etaMin = Math.max(1, Math.ceil((cycle - phase) / 60000));
      // position along corridor away from airport then returning
      const progress = phase / cycle;
      const stIdx = Math.min(L.stations.length - 1, Math.floor(progress * (L.stations.length - 1)));
      const inbound = progress > 0.55;
      const station = inbound ? L.stations[Math.max(0, L.stations.length - 1 - stIdx)] : L.stations[stIdx];
      return {
        line: L.line,
        toward: L.toward,
        station,
        eta_min: inbound && station !== 'Airport' ? etaMin : (station === 'Airport' ? 0 : etaMin),
        status: station === 'Airport' ? 'At Airport Station' : (inbound ? 'Inbound to Airport' : 'Outbound to city'),
      };
    });

    const concourses = ['Domestic', 'T', 'A', 'B', 'C', 'D', 'E', 'F'];
    const ptCycle = 8 * 60 * 1000; // full loop ~8 min simulated
    const ptPhase = now % ptCycle;
    const ptIdx = Math.floor((ptPhase / ptCycle) * concourses.length) % concourses.length;
    const circuitsToday = planeShown; // use daily movement count as "circuits-ish" metric
    return { trains, plane: { at: concourses[ptIdx], next: concourses[(ptIdx + 1) % concourses.length], circuits: circuitsToday } };
  }

  // Built-in map files under assets/maps/ (landscape display + zoom)
  const MAP_FILES = {
    marta_city: 'assets/maps/atlanta%20Rail%20Map.svg',
    plane_train: 'assets/maps/rail%20map%20airport.svg',
  };

  function mapSlotHtml(key, title, hint) {
    const src = MAP_FILES[key] || '';
    const stored = (() => { try { return localStorage.getItem('atl_map_' + key) || ''; } catch (e) { return ''; } })();
    const imgSrc = stored || src;
    return `<div class="card map-slot-card" data-map-key="${key}">
      <div class="card-title">${title}</div>
      <div style="font-size:12px;color:var(--muted);margin-bottom:10px">${hint}</div>
      <div class="map-zoom-wrap" id="mapWrap_${key}">
        <div class="map-zoom-stage" id="mapStage_${key}" data-scale="1" data-x="0" data-y="0">
          <img id="mapImg_${key}" src="${imgSrc}" alt="${key}" draggable="false">
        </div>
        <div class="map-zoom-controls">
          <button type="button" data-zoom-in="${key}" title="Zoom in">+</button>
          <button type="button" data-zoom-out="${key}" title="Zoom out">−</button>
          <button type="button" data-zoom-reset="${key}" title="Reset">⟲</button>
        </div>
      </div>
      <div class="map-zoom-meta">Maps stay vertical · fitted into panel · drag to pan · + − zoom · files in <code>assets/maps/</code></div>
      <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <label class="btn sm" style="cursor:pointer">Replace map
          <input type="file" accept="image/*,.svg" data-map-file="${key}" style="display:none">
        </label>
      </div>
    </div>`;
  }

  function applyMapTransform(key) {
    const stage = document.getElementById('mapStage_' + key);
    const img = document.getElementById('mapImg_' + key);
    if (!stage || !img) return;
    const userScale = Number(stage.dataset.scale || 1);
    const x = Number(stage.dataset.x || 0);
    const y = Number(stage.dataset.y || 0);
    // Keep map VERTICAL (portrait). Fit into horizontal panel via contain scale.
    stage.classList.remove('rotate-landscape');
    const sw = stage.clientWidth || 1;
    const sh = stage.clientHeight || 1;
    const nw = img.naturalWidth || img.width || 1;
    const nh = img.naturalHeight || img.height || 1;
    // base fit: entire portrait image visible inside stage
    const fit = Math.min(sw / nw, sh / nh);
    const total = fit * userScale;
    img.style.width = nw + 'px';
    img.style.height = nh + 'px';
    img.style.maxWidth = 'none';
    img.style.maxHeight = 'none';
    img.style.transform = `translate(${x}px, ${y}px) scale(${total})`;
    img.style.transformOrigin = 'center center';
  }

  function bindMapSlots() {
    document.querySelectorAll('[data-map-file]').forEach(inp => {
      inp.onchange = () => {
        const key = inp.dataset.mapFile;
        const file = inp.files && inp.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => {
          try { localStorage.setItem('atl_map_' + key, reader.result); } catch (e) {}
          const img = document.getElementById('mapImg_' + key);
          if (img) {
            img.onload = () => applyMapTransform(key);
            img.src = reader.result;
          }
        };
        reader.readAsDataURL(file);
      };
    });
    document.querySelectorAll('[data-map-clear]').forEach(btn => {
      btn.onclick = () => {
        const key = btn.dataset.mapClear;
        try { localStorage.removeItem('atl_map_' + key); } catch (e) {}
        const img = document.getElementById('mapImg_' + key);
        const def = MAP_FILES[key] || '';
        if (img && def) {
          img.onload = () => applyMapTransform(key);
          img.src = def + '?t=' + Date.now();
        }
        const stage = document.getElementById('mapStage_' + key);
        if (stage) { stage.dataset.scale = '1'; stage.dataset.x = '0'; stage.dataset.y = '0'; }
        applyMapTransform(key);
      };
    });
    document.querySelectorAll('[data-zoom-in]').forEach(btn => {
      btn.onclick = () => {
        const key = btn.dataset.zoomIn;
        const stage = document.getElementById('mapStage_' + key);
        if (!stage) return;
        stage.dataset.scale = String(Math.min(4, Number(stage.dataset.scale || 1) * 1.25));
        applyMapTransform(key);
      };
    });
    document.querySelectorAll('[data-zoom-out]').forEach(btn => {
      btn.onclick = () => {
        const key = btn.dataset.zoomOut;
        const stage = document.getElementById('mapStage_' + key);
        if (!stage) return;
        stage.dataset.scale = String(Math.max(0.5, Number(stage.dataset.scale || 1) / 1.25));
        applyMapTransform(key);
      };
    });
    document.querySelectorAll('[data-zoom-reset]').forEach(btn => {
      btn.onclick = () => {
        const key = btn.dataset.zoomReset;
        const stage = document.getElementById('mapStage_' + key);
        if (!stage) return;
        stage.dataset.scale = '1'; stage.dataset.x = '0'; stage.dataset.y = '0';
        applyMapTransform(key);
      };
    });
    // drag to pan
    document.querySelectorAll('.map-zoom-stage').forEach(stage => {
      let dragging = false, lx = 0, ly = 0;
      stage.onmousedown = (e) => { dragging = true; lx = e.clientX; ly = e.clientY; e.preventDefault(); };
      window.addEventListener('mouseup', () => { dragging = false; });
      window.addEventListener('mousemove', (e) => {
        if (!dragging) return;
        const dx = e.clientX - lx, dy = e.clientY - ly;
        lx = e.clientX; ly = e.clientY;
        stage.dataset.x = String(Number(stage.dataset.x || 0) + dx);
        stage.dataset.y = String(Number(stage.dataset.y || 0) + dy);
        const key = (stage.id || '').replace('mapStage_', '');
        applyMapTransform(key);
      });
      const img = stage.querySelector('img');
      if (img) img.onload = () => applyMapTransform((stage.id || '').replace('mapStage_', ''));
    });
  }

  // ===== OVERVIEW =====
  if (tab === 'overview') {
    el.innerHTML = `
      <div style="font-size:12px;color:var(--muted);margin-bottom:12px">Atlanta local: <strong style="color:var(--cyan)" data-atl-clock>${escapeHtml(d.atlanta_now || '—')}</strong> · Counts since midnight ATL</div>
      <div class="grid-kpi" style="margin-bottom:16px">
        <div class="card kpi">
          <div class="lab">City metro to Airport</div>
          <div class="val">${Number(martaShown).toLocaleString()}</div>
          <div class="sub">Each MARTA arrival at Airport Station = 1</div>
        </div>
        <div class="card kpi">
          <div class="lab">Inter-terminal metro</div>
          <div class="val">${Number(planeShown).toLocaleString()}</div>
          <div class="sub">Full Plane Train circuits today (1 per full loop)</div>
        </div>
        <div class="card kpi">
          <div class="lab">Taxi trips today</div>
          <div class="val">${(sumMode('taxi') || taxiVehicles.reduce((a,v)=>a+Number(v.trips_today||0),0) || Number(s.taxi_trips||0)).toLocaleString()}</div>
          <div class="sub">Authorized partner taxis · not airport-owned</div>
        </div>
        <div class="card kpi">
          <div class="lab">Active taxis on site</div>
          <div class="val">${taxiVehicles.filter(v=>v.status==='available'||v.status==='on_trip').length}</div>
          <div class="sub">In GTC queue / on trip</div>
        </div>
      </div>
      <div class="grid-2">
        <div class="card">
          <div class="card-title">Taxi (authorized partners)</div>
          <div style="font-size:13px;line-height:1.8;color:var(--text2)">
            Taxis at ATL are <strong>not owned by the airport</strong>. ATL manages permits, GTC curb, and rate zones.
            Official pickup: Domestic GTC Aisle A (A3–A10) · International Arrivals door A1.<br>
            Fare zones: Zone 1 Downtown <strong style="color:var(--cyan)">$37.50</strong> ·
            Zone 3 Midtown <strong style="color:var(--cyan)">$39.50</strong> ·
            Zone 2 Buckhead <strong style="color:var(--cyan)">$49.50</strong>
            (+$2/extra pax · $1.50 surcharge included).
          </div>
        </div>
        <div class="card">
          <div class="card-title">Metro at ATL</div>
          <div style="font-size:13px;line-height:1.8;color:var(--text2)">
            <strong>MARTA Rail</strong> (city) — one station at west end of Domestic Terminal · Red &amp; Gold lines · ~$2.50 · every 10–12 min.<br>
            <strong>Plane Train</strong> (airport-owned) — free airside APM between Domestic and concourses T–A–B–C–D–E–F · not a city line.
          </div>
        </div>
      </div>`;
    return;
  }

  // ===== TAXI =====
  if (tab === 'taxi') {
    el.innerHTML = `
      <div class="card" style="margin-bottom:14px;border-left:3px solid var(--cyan)">
        <div class="card-title">Authorized ATL taxis — not airport-owned</div>
        <div style="font-size:13px;color:var(--text2);line-height:1.7">
          Vehicles are operated by licensed taxi companies under ATL / City of Atlanta rules.
          Official queues: <strong>Domestic GTC Aisle A (A3–A10)</strong> · <strong>International Arrivals door A1</strong>.
          ATL does <em>not</em> publish Economy / Premium / Luxury taxi classes — official structure is <strong>3 fare zones</strong>.
        </div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <div class="card-title">Taxi fare zones (official structure)</div>
        <div class="grid-3">
          ${taxiZones.map(z => `
            <div class="card" style="background:rgba(0,0,0,.18)">
              <div style="font-size:12px;color:var(--cyan);font-weight:600;letter-spacing:.04em">${z.zone}</div>
              <div style="font-size:16px;font-weight:700;margin:6px 0">${escapeHtml(z.dest)}</div>
              <div style="font-size:22px;font-weight:700;color:var(--text)">${z.fare}</div>
              <div style="font-size:12px;color:var(--muted);margin-top:6px;line-height:1.5">${z.extra}<br>${z.note}</div>
            </div>`).join('')}
        </div>
      </div>

      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
          <div class="card-title" style="margin:0">Taxi vehicles · drivers</div>
          <button class="btn primary sm" type="button" id="btnAddTaxi">＋ Add taxi</button>
        </div>
        ${taxiListHtml(taxiVehicles)}
      </div>`;
    bindTaxiActions();
    return;
  }

  // ===== METRO =====
  if (tab === 'metro') {
    const liveState = metroLiveState();
    el.innerHTML = `
      <div class="card" style="margin-bottom:16px">
        <div class="card-title">City metro · MARTA (to / from Atlanta)</div>
        <div class="grid-kpi" style="margin-bottom:14px">
          <div class="card kpi" style="margin:0">
            <div class="lab">Times metro arrived at ATL today</div>
            <div class="val" style="color:var(--cyan)">${Number(martaShown).toLocaleString()}</div>
            <div class="sub">MARTA Red / Gold · Airport Station</div>
          </div>
        </div>
        <div style="font-size:12px;color:var(--muted);margin-bottom:12px">
          Airport Station · west end of Domestic Terminal · Red Line &amp; Gold Line · fare $2.50
        </div>
        <div class="grid-2">
          ${liveState.trains.map(t => `
            <div class="card" style="background:rgba(0,0,0,.18);border-left:3px solid ${t.line.includes('Red') ? '#e74c3c' : '#f1c40f'}">
              <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
                <div style="font-weight:700">${escapeHtml(t.line)}</div>
                <span class="pill ${t.status.includes('At') ? 'green' : 'blue'}">${escapeHtml(t.status)}</span>
              </div>
              <div style="margin-top:10px;font-size:13px;line-height:1.7;color:var(--text2)">
                Corridor: ${escapeHtml(t.toward)}<br>
                Current area: <strong style="color:var(--text)">${escapeHtml(t.station)}</strong><br>
                ${t.station === 'Airport' ? 'Train is at the airport station now.' : `ETA to Airport: <strong style="color:var(--cyan)">${t.eta_min} min</strong>`}
              </div>
            </div>`).join('')}
        </div>
      </div>

      ${mapSlotHtml('marta_city', 'Atlanta city metro map', 'Upload a MARTA system map image here (stored in this browser only).')}

      <div class="card" style="margin:16px 0">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
          <img src="assets/img/ATL%20airport%20train%20plane%20logo.svg" alt="Plane Train" style="width:52px;height:52px;border-radius:14px;flex-shrink:0;background:#0a0c12">
          <div>
            <div class="card-title" style="margin:0">Inter-terminal metro · Plane Train</div>
            <div style="font-size:12px;color:var(--muted);margin-top:4px">Airport-owned APM · free · Domestic ↔ T–F</div>
          </div>
        </div>
        <div style="font-size:12px;color:var(--muted);margin-bottom:12px">
          Full circuits today (each complete loop counts as 1): <strong style="color:var(--cyan)">${Number(planeShown).toLocaleString()}</strong>
        </div>
        <div class="grid-2">
          <div class="card" style="background:rgba(0,0,0,.18)">
            <div class="lab" style="font-size:11px;color:var(--muted)">NOW AT</div>
            <div style="font-size:28px;font-weight:700;margin:6px 0;color:var(--cyan)">${escapeHtml(liveState.plane.at)}</div>
            <div style="font-size:13px;color:var(--text2)">Next stop: <strong>${escapeHtml(liveState.plane.next)}</strong></div>
          </div>
          <div class="card" style="background:rgba(0,0,0,.18)">
            <div class="lab" style="font-size:11px;color:var(--muted)">TERMINAL CIRCUITS / MOVEMENTS TODAY</div>
            <div style="font-size:28px;font-weight:700;margin:6px 0">${Number(liveState.plane.circuits).toLocaleString()}</div>
            <div style="font-size:13px;color:var(--text2)">Plane Train loops across all concourses since midnight ATL</div>
          </div>
        </div>
      </div>

      ${mapSlotHtml('plane_train', 'Airport internal metro map', 'Upload Plane Train / concourse map image here (stored in this browser only).')}`;
    bindMapSlots();
    return;
  }

  el.innerHTML = `<div class="card" style="color:var(--muted)">Unknown transit tab</div>`;
}

async function renderSafety() {
  const d = await API.get('api/data.php?section=safety');
  const state = d.state || {};
  const sec = state.evacuation_active == 1 ? 'EVACUATION' : (state.critical_mode == 1 ? 'THREAT' : 'SECURE');
  const color = sec === 'SECURE' ? 'var(--green)' : 'var(--red)';
  document.getElementById('securityReport').innerHTML = `<strong style="color:${color}">${sec}</strong> — ${sec === 'SECURE' ? 'No active critical threats.' : 'Critical protocol engaged.'}`;
  document.getElementById('alertsList').innerHTML = (d.alerts || []).map(a => `
    <div style="background:var(--panel2);border-radius:10px;padding:10px 12px;border-left:3px solid ${a.level === 'critical' ? 'var(--red)' : a.level === 'warning' ? 'var(--amber)' : 'var(--blue)'};margin-bottom:8px">
      <div style="font-size:13px;font-weight:600">${escapeHtml(a.title)}</div>
      <div style="font-size:11px;color:var(--muted)">${escapeHtml(a.location || '')} · ${a.category} · ${a.created_at}</div>
    </div>`).join('') || '<div style="color:var(--muted)">No active alerts</div>';
  document.getElementById('citizenReports').innerHTML = (d.reports || []).map(r => `
    <div style="background:var(--panel2);border-radius:10px;padding:10px 12px;border-left:3px solid ${r.level === 'critical' ? 'var(--red)' : 'var(--amber)'};margin-bottom:8px">
      <div style="font-size:13px;font-weight:600">${escapeHtml(r.title)}</div>
      <div style="font-size:11px;color:var(--muted)">${escapeHtml(r.location || '')} · ${r.created_at}</div>
    </div>`).join('') || '<div style="color:var(--muted)">No reports</div>';
  document.getElementById('camGrid').innerHTML = (d.cameras || []).map(c => `
    <div class="cam-card"><img src="${c.snapshot_url || ''}" alt="cam" loading="lazy" onerror="this.style.background='#12151f'">
    <div class="cam-meta"><span>${c.cam_code}</span><span><span class="live-dot"></span> ${c.zone || ''}</span></div></div>`).join('');
  document.getElementById('arffRow').innerHTML = (d.arff || []).map(a => `
    <div class="kpi"><div class="lab">${a.name}</div><div class="val" style="color:var(--cyan)">${a.water_level_pct ?? '—'}%</div>
    <div style="font-size:11px;color:var(--muted)">${Number(a.water_capacity_gal || 0).toLocaleString()} gal · ${a.status}</div></div>`).join('');
}

async function renderWeather() {
  const iconFor = (cond) => {
    if (cond === 'rain') return '🌧';
    if (cond === 'cloudy') return '☁';
    if (cond === 'partly') return '⛅';
    if (cond === 'windy') return '🌬';
    return '☀';
  };
  const windDir = (deg) => {
    if (deg == null || isNaN(deg)) return '—';
    const dirs = ['N','NE','E','SE','S','SW','W','NW'];
    return dirs[Math.round(((Number(deg) % 360) / 45)) % 8];
  };
  const visKm = (m) => (m == null ? '—' : (m >= 1000 ? (m / 1000).toFixed(1) + ' km' : Math.round(m) + ' m'));

  let d = {};
  try {
    d = await API.get('api/weather.php') || {};
  } catch (e) {
    // fallback to DB hourly if proxy fails
    try {
      const fb = await API.get('api/data.php?section=weather');
      d = { hourly: (fb.hourly || []).map(h => ({
        hour: (h.observed_at || '').substr(11, 5) || '—',
        temp_c: h.temp_c,
        condition: h.condition_code || 'clear',
        precip_prob: 0,
        impact: h.impact_level || 'low',
      })), current: {}, daily: [], impact: 'low', provider: 'DB fallback' };
    } catch (e2) {
      document.getElementById('wxHeroMeta').textContent = e.message || 'Weather unavailable';
      return;
    }
  }

  const cur = d.current || {};
  const daily = d.daily || [];
  const hourly = d.hourly || [];
  const impact = d.impact || 'low';

  document.getElementById('wxHeroIcon').textContent = iconFor(cur.condition);
  document.getElementById('wxHeroTemp').textContent = (cur.temp_c != null ? cur.temp_c : '—') + '°C';
  document.getElementById('wxHeroMeta').innerHTML =
    `Feels like <strong>${cur.feels_c != null ? cur.feels_c + '°C' : '—'}</strong> · `
    + `${escapeHtml(d.location?.name || 'Atlanta')} · `
    + `<span style="color:var(--cyan)">${escapeHtml(d.provider || 'Open-Meteo')}</span>`
    + (cur.time ? `<br><span style="font-size:11px">Observed ${escapeHtml(String(cur.time).replace('T', ' '))}</span>` : '');

  document.getElementById('wxHeroStats').innerHTML = [
    ['Humidity', cur.humidity != null ? cur.humidity + '%' : '—'],
    ['Cloud', cur.cloud != null ? cur.cloud + '%' : '—'],
    ['Wind', cur.wind_ms != null ? cur.wind_ms + ' m/s ' + windDir(cur.wind_dir) : '—'],
    ['Gusts', cur.gust_ms != null ? cur.gust_ms + ' m/s' : '—'],
    ['Precip', cur.precip_mm != null ? cur.precip_mm + ' mm' : '0 mm'],
  ].map(([lab, val]) => `<div class="wx-stat"><div class="lab">${lab}</div><div class="val">${val}</div></div>`).join('');

  // Daily cards
  const daysEl = document.getElementById('weatherDays');
  if (daysEl) {
    daysEl.innerHTML = daily.map((day, i) => {
      const dt = day.date ? new Date(day.date + 'T12:00:00') : new Date();
      const name = i === 0 ? 'TODAY' : dt.toLocaleDateString('en-US', { weekday: 'short' }).toUpperCase();
      const full = dt.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
      const rainy = (day.precip_prob_max || 0) >= 40 || (day.precip_sum || 0) > 0.2;
      const ico = rainy ? '🌧' : (day.tmax >= 32 ? '☀' : '🌤');
      return `<div class="wx-day-card">
        <div class="icon">${ico}</div>
        <div class="name">${name}</div>
        <div class="temps">${day.tmax != null ? day.tmax : '—'}° / ${day.tmin != null ? day.tmin : '—'}°</div>
        <div class="sub">${full}<br>UV ${day.uv != null ? day.uv : '—'} · Rain ${day.precip_prob_max || 0}%<br>Wind max ${day.wind_max != null ? day.wind_max + ' m/s' : '—'}</div>
      </div>`;
    }).join('') || '<div style="color:var(--muted)">No daily forecast</div>';
  }

  // Hourly strip
  document.getElementById('hourlyDayLabel').textContent = 'Next hours (Atlanta local)';
  document.getElementById('hourlyWx').innerHTML = hourly.slice(0, 24).map(h => `
    <div class="hourly-item" title="Humidity ${h.humidity ?? '—'}% · Wind ${h.wind_ms ?? '—'} m/s">
      <div class="h">${escapeHtml(h.hour || '—')}</div>
      <div class="ico">${iconFor(h.condition)}</div>
      <div class="t">${h.temp_c != null ? h.temp_c : '—'}°</div>
      <div class="p">${h.precip_prob != null ? h.precip_prob + '%' : ''}</div>
    </div>`).join('') || '<div style="color:var(--muted);padding:8px">No hourly data</div>';

  // Impact
  const impactLabel = impact === 'high' ? 'HIGH' : impact === 'moderate' ? 'MODERATE' : 'LOW';
  const tips = {
    low: 'Good operating conditions. Normal taxi and approach intervals.',
    moderate: 'Monitor wind/visibility. Minor arrival spacing buffers recommended.',
    high: 'Elevated weather risk. Expect possible approach delays and gate hold extensions.',
  };
  document.getElementById('wxImpact').innerHTML =
    `<span class="pill-impact ${impact}">${impactLabel}</span> ${tips[impact] || tips.low}`
    + `<div style="margin-top:10px;font-size:12px;color:var(--muted)">Source: Open-Meteo · updated for KATL corridor</div>`;

  // Visibility & wind summary from next hours
  const next = hourly.slice(0, 8);
  const minVis = next.reduce((m, h) => (h.visibility_m != null && (m == null || h.visibility_m < m) ? h.visibility_m : m), null);
  const maxGust = next.reduce((m, h) => (h.gust_ms != null && (m == null || h.gust_ms > m) ? h.gust_ms : m), null);
  const maxProb = next.reduce((m, h) => Math.max(m, h.precip_prob || 0), 0);
  document.getElementById('wxVisWind').innerHTML =
    `Min visibility (8h): <strong>${visKm(minVis)}</strong><br>`
    + `Peak gust (8h): <strong>${maxGust != null ? maxGust + ' m/s' : '—'}</strong><br>`
    + `Max precip probability: <strong>${maxProb}%</strong><br>`
    + `Dominant wind now: <strong>${windDir(cur.wind_dir)} ${cur.wind_ms != null ? cur.wind_ms + ' m/s' : ''}</strong>`;

  const btn = document.getElementById('btnRefreshWeather');
  if (btn && !btn.dataset.bound) {
    btn.dataset.bound = '1';
    btn.onclick = () => renderWeather();
  }
}


/* ---------- Settings panels ---------- */
async function openGateSettings() {
  const panel = document.getElementById('gateSettingsPanel');
  panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
  if (panel.style.display === 'none') return;
  const d = await API.get('api/data.php?section=gates');
  const terms = ['T','A','B','C','D','E','F'];
  document.getElementById('gateSettingsRows').innerHTML = terms.map(cid => {
    const cfg = d.settings[cid] || { type: 'domestic', continent: '' };
    return `<div class="settings-row">
      <strong>Concourse ${cid}</strong>
      <select data-term="${cid}" data-field="type">
        <option value="domestic" ${cfg.type === 'domestic' ? 'selected' : ''}>Domestic</option>
        <option value="international" ${cfg.type === 'international' ? 'selected' : ''}>International</option>
      </select>
      <select data-term="${cid}" data-field="continent" ${cfg.type === 'domestic' ? 'disabled' : ''}>
        <option value="">—</option>
        <option value="europe" ${cfg.continent === 'europe' ? 'selected' : ''}>Europe</option>
        <option value="asia" ${cfg.continent === 'asia' ? 'selected' : ''}>Asia</option>
        <option value="namerica" ${cfg.continent === 'namerica' ? 'selected' : ''}>N. America</option>
        <option value="samerica" ${cfg.continent === 'samerica' ? 'selected' : ''}>S. America</option>
      </select>
    </div>`;
  }).join('');
}
async function saveGateSettings() {
  const terminals = {};
  document.querySelectorAll('#gateSettingsRows select[data-field="type"]').forEach(sel => {
    const cid = sel.dataset.term;
    const cont = document.querySelector(`#gateSettingsRows select[data-term="${cid}"][data-field="continent"]`);
    terminals[cid] = { type: sel.value, continent: sel.value === 'international' ? cont.value : null };
  });
  await API.post('api/actions.php', { action: 'save_terminals', terminals });
  showAlert('success', 'Saved', 'Terminal settings updated');
  document.getElementById('gateSettingsPanel').style.display = 'none';
  renderGates();
}
async function openRwySettings() {
  const panel = document.getElementById('rwySettingsPanel');
  panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
  if (panel.style.display === 'none') return;
  const d = await API.get('api/data.php?section=airside');
  document.getElementById('rwySettingsRows').innerHTML = (d.runways || []).map(r => `
    <div class="settings-row" style="grid-template-columns:120px 1fr">
      <strong>${r.code}</strong>
      <select data-rwy="${r.code}">
        <option value="both" ${r.role === 'both' ? 'selected' : ''}>Both</option>
        <option value="takeoff" ${r.role === 'takeoff' ? 'selected' : ''}>Takeoff only</option>
        <option value="landing" ${r.role === 'landing' ? 'selected' : ''}>Landing only</option>
        <option value="closed" ${r.role === 'closed' ? 'selected' : ''}>Closed</option>
      </select>
    </div>`).join('');
}
async function saveRwySettings() {
  const runways = {};
  document.querySelectorAll('#rwySettingsRows select[data-rwy]').forEach(sel => { runways[sel.dataset.rwy] = sel.value; });
  await API.post('api/actions.php', { action: 'save_runways', runways });
  showAlert('success', 'Saved', 'Runway settings updated');
  document.getElementById('rwySettingsPanel').style.display = 'none';
  renderAirside();
}

/* ---------- Users modal ---------- */
function openUserModal(list = true) {
  document.getElementById('userModal').classList.add('open');
  document.getElementById('userListView').style.display = list ? '' : 'none';
  document.getElementById('userFormView').style.display = list ? 'none' : '';
  document.getElementById('userFormFooter').style.display = list ? 'none' : 'flex';
  document.getElementById('userModalTitle').textContent = list ? 'Manage Users' : (document.getElementById('fEditId').value ? 'Edit User' : 'Add User');
  if (list) renderUsersTable();
}
function closeUserModal() { document.getElementById('userModal').classList.remove('open'); }
function buildSectionChecks(sel) {
  document.getElementById('sectionChecks').innerHTML = ALL_SECTIONS.map(s =>
    `<label><input type="checkbox" value="${s}" ${sel.includes(s) ? 'checked' : ''}> ${SECTION_LABELS[s]}</label>`
  ).join('');
}
async function renderUsersTable(usersFromStaff) {
  let users = Array.isArray(usersFromStaff) ? usersFromStaff : null;
  if (!users) {
    try {
      const d = await API.post('api/actions.php', { action: 'list_users' });
      users = d.users || [];
    } catch (e) {
      users = [];
    }
  }
  const html = `<table class="user-table"><thead><tr>
    <th>Username</th><th>Name</th><th>Role</th><th>Access</th><th></th></tr></thead><tbody>
    ${(users || []).map(u => `<tr>
      <td>${escapeHtml(u.username || '')}${u.role === 'admin' ? ' <span class="pill blue">Admin</span>' : ''}</td>
      <td>${escapeHtml(u.full_name || '—')}</td><td>${escapeHtml(u.role || '')}</td>
      <td style="font-size:11px;color:var(--muted)">${u.role === 'admin' ? 'All' : (u.sections || []).length + ' sections'}</td>
      <td>${u.role === 'admin' ? '' : `<button class="btn sm" data-edit="${u.id}">Edit</button> <button class="btn danger sm" data-del="${u.id}">Del</button>`}</td>
    </tr>`).join('') || '<tr><td colspan="5" style="color:var(--muted)">No system users</td></tr>'}</tbody></table>`;
  const wrapModal = document.getElementById('usersTableWrap');
  const wrapStaff = document.getElementById('staffUsersTable');
  if (wrapModal) wrapModal.innerHTML = html;
  if (wrapStaff) wrapStaff.innerHTML = html;
  const bindUserRowActions = (root) => {
    if (!root) return;
    root.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => editUser(users.find(x => String(x.id) === b.dataset.edit)));
    root.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
      if (!confirm('Delete user?')) return;
      await API.post('api/actions.php', { action: 'delete_user', id: Number(b.dataset.del) });
      showAlert('warning', 'Deleted', 'User removed');
      renderUsersTable();
    });
  };
  bindUserRowActions(wrapStaff);
  bindUserRowActions(wrapModal);
}
function editUser(u) {
  if (!u) return;
  document.getElementById('fEditId').value = u.id;
  document.getElementById('fUsername').value = u.username;
  document.getElementById('fPassword').value = '';
  document.getElementById('fName').value = u.full_name || '';
  document.getElementById('fRole').value = u.role;
  buildSectionChecks(u.sections || []);
  openUserModal(false);
}
async function saveUser() {
  const payload = {
    action: 'save_user',
    id: Number(document.getElementById('fEditId').value || 0),
    username: document.getElementById('fUsername').value.trim(),
    password: document.getElementById('fPassword').value,
    full_name: document.getElementById('fName').value.trim(),
    role: document.getElementById('fRole').value,
    sections: [...document.querySelectorAll('#sectionChecks input:checked')].map(c => c.value),
  };
  try {
    await API.post('api/actions.php', payload);
    showAlert('success', 'Saved', 'User saved');
    openUserModal(true);
  } catch (e) {
    showAlert('danger', 'Error', e.message);
  }
}

async function activateEvac() {
  if (!confirm('Activate evacuation protocol?')) return;
  if (!confirm('FINAL CONFIRMATION — activate evacuation?')) return;
  try {
    await API.post('api/actions.php', { action: 'evacuate', confirmed: true });
  } catch (e) {
    // still engage local emergency UX even if API fails
    console.warn(e);
  }
  setCriticalMode(true);
  playSiren(true);
  showAlert('danger', 'EMERGENCY', 'Evacuation protocol active — red border engaged', true);
  if (typeof renderSafety === 'function') renderSafety();
}

function tickClock() {
  const el = document.getElementById('clock');
  if (!el) return;
  const now = new Date();
  const tz = 'America/New_York';
  const date = now.toLocaleDateString('en-US', { timeZone: tz, year: 'numeric', month: 'short', day: '2-digit' });
  const time = now.toLocaleTimeString('en-US', { timeZone: tz, hour12: false });
  el.textContent = 'ATL ' + date + ' · ' + time;
}

/* ---------- Init ---------- */
document.addEventListener('DOMContentLoaded', () => {
  loadCaptcha();
  document.getElementById('captchaRefresh').onclick = loadCaptcha;
  document.getElementById('loginBtn').onclick = doLogin;
  document.getElementById('loginPass').addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
  document.getElementById('loginCaptcha').addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
  document.getElementById('loginCaptcha').addEventListener('input', e => { e.target.value = e.target.value.toUpperCase(); });
  document.getElementById('logoutBtn').onclick = logout;
  document.getElementById('accountBtn').onclick = e => { e.stopPropagation(); document.getElementById('accountMenu').classList.toggle('open'); };
  document.addEventListener('click', e => {
    if (!e.target.closest('#accountBtn') && !e.target.closest('#accountMenu'))
      document.getElementById('accountMenu').classList.remove('open');
  });
  document.getElementById('sideNav').addEventListener('click', e => {
    const b = e.target.closest('button[data-view]');
    if (b && !b.classList.contains('hidden')) switchView(b.dataset.view);
  });
  document.getElementById('menuBtn').onclick = () => {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
  };
  document.getElementById('sidebarOverlay').onclick = () => {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
  };
  document.getElementById('drawerClose').onclick = closeDrawer;
  document.getElementById('drawerOverlay').onclick = closeDrawer;
  document.getElementById('gateSettingsBtn').onclick = openGateSettings;
  document.getElementById('saveGateSettings').onclick = saveGateSettings;
  document.getElementById('rwySettingsBtn').onclick = openRwySettings;
  document.getElementById('saveRwySettings').onclick = saveRwySettings;
  const runFlightSearch = () => {
    window.__flightSearchQ = (document.getElementById('flightSearchInput')?.value || '').trim();
    window.__flightPage = 1;
    renderFlights();
  };
  document.getElementById('flightSearchBtn')?.addEventListener('click', runFlightSearch);
  document.getElementById('flightSearchClear')?.addEventListener('click', () => {
    const inp = document.getElementById('flightSearchInput');
    if (inp) inp.value = '';
    window.__flightSearchQ = '';
    window.__flightPage = 1;
    renderFlights();
  });
  document.getElementById('flightSearchInput')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') runFlightSearch();
  });
  document.querySelectorAll('[data-ffilter]').forEach(btn => {
    btn.onclick = () => {
      document.querySelectorAll('[data-ffilter]').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      flightFilter = btn.dataset.ffilter;
      window.__flightPage = 1;
      window.__flightPageTouched = true;
      window.__flightAroundNow = false;
      renderFlights();
    };
  });
  document.querySelectorAll('[data-gfilter]').forEach(btn => {
    btn.onclick = () => {
      document.querySelectorAll('[data-gfilter]').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      gateFilter = btn.dataset.gfilter;
      renderGates();
    };
  });
  document.getElementById('bagSearchBtn').onclick = () => { bagQuery = document.getElementById('bagSearch').value; renderBaggage(); };
  document.getElementById('bagSearchClear').onclick = () => { bagQuery = ''; document.getElementById('bagSearch').value = ''; renderBaggage(); };
  document.getElementById('manageUsersBtn').onclick = () => { document.getElementById('accountMenu').classList.remove('open'); openUserModal(true); };
  const addStaffUserBtn = document.getElementById('addUserFromStaff');
  if (addStaffUserBtn) addStaffUserBtn.onclick = () => openUserModal(true);
  document.getElementById('userModalClose').onclick = closeUserModal;
  document.getElementById('showAddUser').onclick = () => {
    document.getElementById('fEditId').value = '';
    document.getElementById('fUsername').value = '';
    document.getElementById('fPassword').value = '';
    document.getElementById('fName').value = '';
    document.getElementById('fRole').value = 'controller';
    buildSectionChecks([]);
    openUserModal(false);
  };
  document.getElementById('cancelUserForm').onclick = () => openUserModal(true);
  document.getElementById('saveUserBtn').onclick = saveUser;
  document.getElementById('evacBtn').onclick = activateEvac;
  document.getElementById('clearCriticalBtn').onclick = async () => {
    if (!confirm('Clear critical mode?')) return;
    await API.post('api/actions.php', { action: 'clear_critical' });
    setCriticalMode(false);
    showAlert('success', 'All clear', 'Critical mode cleared');
    renderSafety();
  };
  tickClock();
  setInterval(tickClock, 1000);

  // resume session if already logged in
  API.get('api/auth.php?action=me').then(u => {
    if (u && u.csrf) __csrf = u.csrf;
    currentUser = (u && u.user) ? u.user : u;
    document.body.classList.add('logged-in');
    document.getElementById('accountName').textContent = u.full_name || u.username;
    applyPermissions();
    startPolling();
    switchView(u.sections?.[0] || 'overview');
  }).catch(() => {});
});

/* ============================================================
   ADD FLIGHT + FLIGHT SETTINGS + GATE SETTINGS + AIRPORT AC
   ============================================================ */

let afType = 'dep';
let afSelectedContinent = null;
let currentEditFlight = null;

function bindAirportAC(inputId, listId, onSelect) {
  const inp = document.getElementById(inputId);
  const list = document.getElementById(listId);
  if (!inp || !list) return;
  let timer = null;
  inp.addEventListener('input', () => {
    clearTimeout(timer);
    const q = inp.value.trim();
    if (q.length < 1) { list.classList.remove('open'); return; }
    timer = setTimeout(async () => {
      try {
        const res = await API.get('api/data.php?section=airports&q=' + encodeURIComponent(q));
        const items = res.airports || [];
        if (!items.length) { list.classList.remove('open'); return; }
        list.innerHTML = items.map(a => `
          <div class="ac-item" data-iata="${a.iata}" data-continent="${a.continent || ''}" data-city="${a.city}">
            <span class="code">${a.iata}</span> ${a.city}, ${a.country}
            <div class="meta">${a.name}${a.continent ? ' · ' + a.continent : ''}</div>
          </div>`).join('');
        list.classList.add('open');
        list.querySelectorAll('.ac-item').forEach(el => {
          el.onclick = () => {
            inp.value = el.dataset.iata;
            list.classList.remove('open');
            if (onSelect) onSelect(el.dataset.iata, el.dataset.continent, el.dataset.city);
          };
        });
      } catch (e) { list.classList.remove('open'); }
    }, 200);
  });
  document.addEventListener('click', e => {
    if (!inp.contains(e.target) && !list.contains(e.target)) list.classList.remove('open');
  });
}

async function loadGatesForContinent(continent) {
  const sel = document.getElementById('afGate');
  if (!sel) return;
  try {
    const sched = document.getElementById('afTime')?.value || '';
    let url = 'api/data.php?section=addflight';
    if (continent) url += '&continent=' + encodeURIComponent(continent);
    if (sched) url += '&scheduled_time=' + encodeURIComponent(sched.replace('T', ' ') + (sched.length === 16 ? ':00' : ''));
    const data = await API.get(url);
    sel.innerHTML = '<option value="">— gates free 2h before flight —</option>' +
      (data.free_gates || []).map(g => {
        const em = g.is_reserve == 1 || g.emergency ? ' · EMERGENCY' : '';
        return `<option value="${g.id}">${g.code} (${g.terminal}${g.continent ? ' · ' + g.continent : ''}${em})</option>`;
      }).join('');
  } catch (e) { console.warn(e); }
}

async function openAddFlightModal() {
  const modal = document.getElementById('addFlightModal');
  if (!modal) return;
  afType = 'dep';
  document.querySelectorAll('.af-type').forEach(b => b.classList.toggle('active', b.dataset.type === 'dep'));
  document.getElementById('afOrigin').value = 'ATL';
  document.getElementById('afDest').value = '';
  document.getElementById('afNum').value = '';
  document.getElementById('afPilot').value = '';
  document.getElementById('afCopilot').value = '';
  document.getElementById('afCrew').value = '4';
  document.getElementById('afIntl').value = '0';
  const dt = new Date(Date.now() + 3600000);
  document.getElementById('afTime').value = dt.toISOString().slice(0, 16);
  try {
    const data = await API.get('api/data.php?section=addflight');
    const acSel = document.getElementById('afAircraft');
    acSel.innerHTML = '<option value="">— select —</option>' +
      (data.aircraft || []).map(a => `<option value="${a.id}">${(a.manufacturer||'')} ${a.model_code} (${a.seats_total} seats)</option>`).join('');
    await loadGatesForContinent(null);
  } catch (e) { console.error(e); }
  modal.classList.add('open');
}

async function submitAddFlight() {
  const payload = {
    action: 'add_flight',
    flight_number: document.getElementById('afNum').value.trim(),
    type: afType,
    origin: document.getElementById('afOrigin').value.trim().toUpperCase() || 'ATL',
    destination: document.getElementById('afDest').value.trim().toUpperCase(),
    aircraft_id: parseInt(document.getElementById('afAircraft').value) || 0,
    gate_id: parseInt(document.getElementById('afGate').value) || 0,
    scheduled_time: (document.getElementById('afTime').value || '').replace('T', ' ') + ':00',
    pilot_name: document.getElementById('afPilot').value.trim(),
    copilot_name: document.getElementById('afCopilot').value.trim(),
    cabin_crew: parseInt(document.getElementById('afCrew').value) || 4,
    is_international: parseInt(document.getElementById('afIntl').value) || 0,
  };
  if (!payload.flight_number || !payload.destination) {
    showAlert('warning', 'Required', 'Flight number and destination are required');
    return;
  }
  try {
    await API.post('api/actions.php', payload);
    document.getElementById('addFlightModal').classList.remove('open');
    showAlert('success', 'Flight added', payload.flight_number);
    renderAddFlight();
    if (currentView === 'flights') renderFlights();
  } catch (e) {
    showAlert('danger', 'Error', e.message || 'Failed');
  }
}

async function renderAddFlight() {
  try {
    const data = await API.get('api/data.php?section=addflight');
    const board = document.getElementById('manualFlightBoard');
    if (!board) return;
    const list = data.manual_flights || [];
    const head = document.querySelector('#view-addflight .soft-head');
    if (head) head.style.gridTemplateColumns = '90px 70px 70px 80px 60px 60px 1fr 70px';
    board.innerHTML = list.map(f => `
      <div class="soft-row" data-fid="${f.id}" style="grid-template-columns:90px 70px 70px 80px 60px 60px 1fr 70px">
        <span class="af-open" style="cursor:pointer">${escapeHtml(f.num)}</span>
        <span>${escapeHtml(f.origin)}</span><span>${escapeHtml(f.dest)}</span>
        <span>${escapeHtml(f.aircraft || '')}</span><span>${escapeHtml(f.gate || '')}</span>
        <span>${escapeHtml(f.time || '')}</span>
        ${statusPill(f.status)}
        <span><button type="button" class="btn sm danger af-del" data-del="${f.id}">Delete</button></span>
      </div>`).join('') || '<div class="soft-row" style="color:var(--muted)">No manual flights yet</div>';
    board.querySelectorAll('.af-open').forEach(r => {
      r.onclick = () => {
        const row = r.closest('[data-fid]');
        const f = list.find(x => String(x.id) === row?.dataset.fid);
        if (f) openFlightDrawer(f);
      };
    });
    board.querySelectorAll('.af-del').forEach(btn => {
      btn.onclick = async (ev) => {
        ev.stopPropagation();
        const id = btn.dataset.del;
        if (!confirm('Delete this manually added flight?')) return;
        try {
          await API.post('api/actions.php', { action: 'delete_flight', id: Number(id) });
          showAlert('success', 'Deleted', 'Manual flight removed');
          renderAddFlight();
        } catch (e) {
          showAlert('danger', 'Delete failed', e.message || 'Error');
        }
      };
    });
  } catch (e) { console.error(e); }
}

/* ---- Enhance flight drawer with Settings ---- */
const _origOpenFlightDrawer = typeof openFlightDrawer === 'function' ? openFlightDrawer : null;
window.openFlightDrawer = function(f) {
  if (!f) return;
  currentEditFlight = f;
  document.getElementById('drawerTitle').textContent = `${f.num} · ${f.origin} → ${f.dest}${f.is_tomorrow ? ' (tomorrow)' : ''}`;
  const isCancelled = f.status === 'Cancelled';
  document.getElementById('drawerBody').innerHTML = `
    <div style="display:flex;justify-content:flex-end;margin-bottom:6px">
      <button type="button" class="drawer-settings-btn" id="flightSettingsBtn" title="Settings" ${isCancelled ? 'disabled style="opacity:.4"' : ''}>⚙</button>
    </div>
    <div id="flightEditPanel" style="display:none" class="flight-edit-box"></div>
    <div style="position:relative;margin:8px 0 12px">
      <img src="${f.aircraft_image || 'https://placehold.co/900x400/1c1f2b/5b8def?text=' + encodeURIComponent(f.aircraft || 'AC')}"
        alt="${f.aircraft}" style="width:100%;height:160px;object-fit:cover;border-radius:12px;background:#12151f"
        onerror="this.src='https://placehold.co/900x400/1c1f2b/5b8def?text=${encodeURIComponent(f.aircraft||'AC')}'">
      <div style="position:absolute;bottom:10px;left:10px;background:rgba(0,0,0,.75);padding:4px 10px;border-radius:8px;font-size:12px;font-weight:700">${f.aircraft_full || f.aircraft || '—'}</div>
    </div>
    <div class="detail-grid">
      <div class="detail-item"><div class="dlab">Flight</div><div class="dval">${f.num}</div></div>
      <div class="detail-item"><div class="dlab">Route</div><div class="dval">${f.origin} → ${f.dest}</div></div>
      <div class="detail-item"><div class="dlab">Aircraft</div><div class="dval">${f.aircraft || '—'}</div></div>
      <div class="detail-item"><div class="dlab">Gate</div><div class="dval">${f.gate || '—'}</div></div>
      <div class="detail-item"><div class="dlab">Time</div><div class="dval">${f.time}</div></div>
      <div class="detail-item"><div class="dlab">Status</div><div class="dval">${statusPill(f.status)}</div></div>
      <div class="detail-item"><div class="dlab">Seats</div><div class="dval">${f.seats_total ?? '—'}</div></div>
      <div class="detail-item"><div class="dlab">Pax</div><div class="dval">${f.pax_accepted ?? '—'}</div></div>
      <div class="detail-item"><div class="dlab">Bags</div><div class="dval">${f.bags_count ?? '—'}</div></div>
      <div class="detail-item"><div class="dlab">Crew</div><div class="dval">${f.crew ?? '—'}</div></div>
    </div>
    ${f.replacement ? `<div class="card-title" style="margin-top:12px">Replacement</div><div style="font-size:13px">${f.replacement}</div>` : ''}
    <div class="card-title">Crew</div>
    <div style="font-size:13px;color:var(--text2);line-height:1.8">
      <strong>Pilot:</strong> ${f.pilot || '—'}<br>
      <strong>Co-pilot:</strong> ${f.copilot || '—'}
    </div>
    ${f.delay_reason ? `<div class="card-title" style="margin-top:12px">Delay / Reason</div><div style="color:var(--amber);font-size:13px">${f.delay_minutes ? f.delay_minutes + 'm — ' : ''}${f.delay_reason}</div>` : ''}
  `;
  document.getElementById('drawerOverlay').classList.add('open');
  document.getElementById('flightDrawer').classList.add('open');

  document.getElementById('flightSettingsBtn').onclick = () => openFlightEditPanel(f);
};

async function openFlightEditPanel(f) {
  const panel = document.getElementById('flightEditPanel');
  if (!panel) return;
  if (panel.style.display === 'block' && panel.dataset.fid === String(f.id)) {
    panel.style.display = 'none';
    return;
  }
  panel.style.display = 'block';
  panel.dataset.fid = String(f.id);
  // load free gates for this flight's continent
  let gatesHtml = '<option value="">— keep current —</option>';
  try {
    const cont = f.continent || '';
    const data = await API.get('api/data.php?section=addflight' + (cont ? '&continent=' + encodeURIComponent(cont) : ''));
    (data.free_gates || []).forEach(g => {
      gatesHtml += `<option value="${g.id}" ${g.code === f.gate ? 'selected' : ''}>${g.code} (free)</option>`;
    });
    // also include current gate
    if (f.gate_id) {
      gatesHtml = `<option value="${f.gate_id}" selected>${f.gate} (current)</option>` + gatesHtml;
    }
  } catch (e) {}

  panel.innerHTML = `
    <div style="font-weight:600;margin-bottom:10px">Flight Settings</div>
    <div class="form-row">
      <div class="form-group"><label>Flight number</label><input type="text" id="feNum" value="${f.num || ''}"></div>
      <div class="form-group"><label>Status</label>
        <select id="feStatus">
          ${['Scheduled','On Time','Boarding','Final Call','Delayed','Pushback','Taxi to Runway','Takeoff','Landing','Taxi to Gate','Deboarding','Cleaning','Ready at Gate','Arrived','Departed'].map(s =>
            `<option value="${s}" ${s===f.status?'selected':''}>${s}</option>`).join('')}
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Gate (continent-filtered)</label><select id="feGate">${gatesHtml}</select></div>
      <div class="form-group"><label>Delay (minutes)</label><input type="number" id="feDelay" value="${f.delay_minutes||0}" min="0"></div>
    </div>
    <div class="form-group"><label>Delay reason</label><input type="text" id="feDelayReason" value="${f.delay_reason||''}"></div>
    <div class="form-row">
      <div class="form-group"><label>Pilot</label><input type="text" id="fePilot" value="${f.pilot||''}"></div>
      <div class="form-group"><label>Co-pilot</label><input type="text" id="feCopilot" value="${f.copilot||''}"></div>
    </div>
    <div style="margin:12px 0 8px">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" id="feCancel"> Cancel this flight
      </label>
    </div>
    <div id="feCancelExtra" style="display:none;margin-bottom:10px">
      <div class="form-group"><label>Replacement flight (optional)</label><input type="text" id="feRepl" placeholder="e.g. DL999"></div>
      <div class="form-group"><label>Cancel reason</label><input type="text" id="feCancelReason" value="Operational"></div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button class="btn sm" type="button" id="feCancelBtn">Close</button>
      <button class="btn primary sm" type="button" id="feSaveBtn">Save</button>
    </div>
  `;
  document.getElementById('feCancel').onchange = (e) => {
    document.getElementById('feCancelExtra').style.display = e.target.checked ? 'block' : 'none';
  };
  document.getElementById('feCancelBtn').onclick = () => { panel.style.display = 'none'; };
  document.getElementById('feSaveBtn').onclick = async () => {
    const payload = {
      action: 'update_flight',
      flight_id: f.id,
      flight_number: document.getElementById('feNum').value.trim(),
      status: document.getElementById('feStatus').value,
      gate_id: parseInt(document.getElementById('feGate').value) || f.gate_id,
      delay_minutes: parseInt(document.getElementById('feDelay').value) || 0,
      delay_reason: document.getElementById('feDelayReason').value.trim(),
      pilot_name: document.getElementById('fePilot').value.trim(),
      copilot_name: document.getElementById('feCopilot').value.trim(),
      cancel: document.getElementById('feCancel').checked,
      replacement_flight: document.getElementById('feRepl')?.value.trim() || '',
      cancel_reason: document.getElementById('feCancelReason')?.value.trim() || 'Operational',
    };
    try {
      await API.post('api/actions.php', payload);
      showAlert('success', 'Saved', payload.cancel ? 'Flight cancelled' : 'Flight updated');
      closeDrawer();
      renderFlights();
      if (typeof renderGates === 'function') renderGates();
    } catch (e) {
      showAlert('danger', 'Error', e.message);
    }
  };
}

/* ---- Gate click → show info + settings ---- */
const _origRenderGates = typeof renderGates === 'function' ? renderGates : null;
window.renderGates = async function() {
  if (_origRenderGates) await _origRenderGates();
  // re-attach click with settings
  setTimeout(() => {
    document.querySelectorAll('.gate-tile').forEach(el => {
      el.onclick = async () => {
        const code = el.dataset.code;
        const fid = el.dataset.fid;
        // fetch gate data
        try {
          const d = await API.get('api/data.php?section=gates');
          const gate = (d.gates || []).find(g => g.code === code);
          if (!gate) return;
          openGateDrawer(gate);
        } catch (e) { console.error(e); }
      };
    });
  }, 200);
};

function openGateDrawer(gate) {
  const occupied = gate.status === 'occupied' && gate.flight_number;
  const acLabel = gate.aircraft_mfr ? (gate.aircraft_mfr + ' ' + (gate.aircraft_model||'')) : (gate.aircraft_model || 'Aircraft');
  const img = gate.aircraft_image || ('https://placehold.co/900x400/1c1f2b/5b8def?text=' + encodeURIComponent(gate.aircraft_model || 'Gate'));
  document.getElementById('drawerTitle').textContent = 'Gate ' + gate.code;
  document.getElementById('drawerBody').innerHTML = `
    <div style="display:flex;justify-content:flex-end;margin-bottom:6px">
      <button type="button" class="drawer-settings-btn" id="gateSettingsIcon" title="Settings">⚙</button>
    </div>
    <div id="gateEditPanel" style="display:none" class="gate-settings-box"></div>
    ${occupied ? `
      <div style="position:relative;margin:8px 0 12px">
        <img src="${img}" alt="${acLabel}" style="width:100%;height:150px;object-fit:cover;border-radius:12px;background:#12151f"
          onerror="this.src='https://placehold.co/900x400/1c1f2b/5b8def?text=${encodeURIComponent(gate.aircraft_model||'AC')}'">
        <div style="position:absolute;bottom:10px;left:10px;background:rgba(0,0,0,.75);padding:4px 10px;border-radius:8px;font-size:12px;font-weight:700">${acLabel}</div>
      </div>
      <div style="margin-bottom:12px"><span class="pill amber">OCCUPIED</span></div>
      <div class="detail-grid">
        <div class="detail-item"><div class="dlab">Flight</div><div class="dval">${gate.flight_number}</div></div>
        <div class="detail-item"><div class="dlab">Type</div><div class="dval">${gate.flight_type || '—'}</div></div>
        <div class="detail-item"><div class="dlab">Route</div><div class="dval">${gate.origin || '—'} → ${gate.destination || '—'}</div></div>
        <div class="detail-item"><div class="dlab">Status</div><div class="dval">${gate.flight_status || '—'}</div></div>
        <div class="detail-item"><div class="dlab">Aircraft</div><div class="dval">${gate.aircraft_model || '—'}</div></div>
        <div class="detail-item"><div class="dlab">Terminal</div><div class="dval">${gate.terminal}</div></div>
      </div>
    ` : `
      <div style="margin-bottom:12px"><span class="pill green">EMPTY / AVAILABLE</span></div>
      <p style="color:var(--text2);font-size:13px">This gate is free. No flight assigned.</p>
      <div class="detail-item" style="margin-top:10px"><div class="dlab">Terminal</div><div class="dval">${gate.terminal}</div></div>
      <div class="detail-item"><div class="dlab">Status</div><div class="dval">${gate.status}</div></div>
    `}
  `;
  document.getElementById('drawerOverlay').classList.add('open');
  document.getElementById('flightDrawer').classList.add('open');

  document.getElementById('gateSettingsIcon').onclick = () => {
    const panel = document.getElementById('gateEditPanel');
    panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
    if (panel.style.display !== 'block') return;
    const hasFlight = !!(gate.current_flight_id || gate.fid);
    panel.innerHTML = `
      <div style="font-weight:600;margin-bottom:10px">Gate Settings · ${gate.code}</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        <button class="btn sm" type="button" id="gsOpen" ${hasFlight ? '' : 'disabled'}>Open gate</button>
        <button class="btn sm" type="button" id="gsClose" ${hasFlight ? '' : 'disabled'}>Close gate</button>
        <button class="btn sm" type="button" id="gsMaint">Maintenance</button>
        <button class="btn sm" type="button" id="gsAvail">Set Available</button>
      </div>
      <div style="font-size:12px;color:var(--muted);margin-bottom:10px">
        Open/Close only when a flight is assigned. Maintenance always available.
      </div>
      <button class="btn primary sm" type="button" id="gsSave">Save</button>
    `;
    let chosen = gate.status;
    const pick = (id, val) => { const el=document.getElementById(id); if(el) el.onclick = () => { chosen = val; highlightGateBtn(id); }; };
    pick('gsOpen','available'); pick('gsClose','closed'); pick('gsMaint','maintenance'); pick('gsAvail','available');
    document.getElementById('gsSave').onclick = async () => {
      try {
        await API.post('api/actions.php', { action: 'update_gate', gate_id: gate.id, status: chosen });
        showAlert('success', 'Gate updated', gate.code + ' → ' + chosen);
        closeDrawer();
        renderGates();
      } catch (e) { showAlert('danger', 'Error', e.message); }
    };
  };
}

function highlightGateBtn(id) {
  ['gsOpen','gsClose','gsMaint','gsAvail'].forEach(i => {
    const el = document.getElementById(i);
    if (el) el.classList.toggle('primary', i === id);
  });
}

/* ---- Init bindings ---- */
document.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => {
    bindAirportAC('afOrigin', 'afOriginAC', (iata, cont) => {
      if (afType === 'arr') {
        afSelectedContinent = cont;
        loadGatesForContinent(cont);
      }
    });
    bindAirportAC('afDest', 'afDestAC', (iata, cont) => {
      if (afType === 'dep') {
        afSelectedContinent = cont;
        loadGatesForContinent(cont);
        document.getElementById('afIntl').value = (cont && cont !== 'namerica') ? '1' : '0';
      }
    });

    document.getElementById('btnOpenAddFlight')?.addEventListener('click', openAddFlightModal);
    document.getElementById('afTime')?.addEventListener('change', () => {
      const cont = window.__afContinent || '';
      loadGatesForContinent(cont);
    });
    document.getElementById('addFlightClose')?.addEventListener('click', () => document.getElementById('addFlightModal').classList.remove('open'));
    document.getElementById('addFlightCancel')?.addEventListener('click', () => document.getElementById('addFlightModal').classList.remove('open'));
    document.getElementById('afSubmit')?.addEventListener('click', submitAddFlight);

    document.querySelectorAll('.af-type').forEach(b => {
      b.addEventListener('click', () => {
        document.querySelectorAll('.af-type').forEach(x => x.classList.remove('active'));
        b.classList.add('active');
        afType = b.dataset.type;
        if (afType === 'dep') {
          document.getElementById('afOrigin').value = 'ATL';
          document.getElementById('afDest').value = '';
        } else {
          document.getElementById('afDest').value = 'ATL';
          document.getElementById('afOrigin').value = '';
        }
        loadGatesForContinent(null);
      });
    });
  }, 300);
});

// Hook switchView
const _origSwitchView = typeof switchView === 'function' ? switchView : null;
if (_origSwitchView) {
  window.switchView = function(v) {
    _origSwitchView(v);
    if (v === 'addflight') renderAddFlight();
  };
}

// Also update SECTION_LABELS if exists
if (typeof SECTION_LABELS !== 'undefined') {
  SECTION_LABELS.addflight = 'Add Flight';
}


/* ---- Lost / Damaged bags + Fuel tanks + Cameras ---- */
document.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => {
    document.getElementById('cardLostBags')?.addEventListener('click', () => { openLostBagsPanel(); });
    document.getElementById('cardDamagedBags')?.addEventListener('click', () => { openDamagedBagsPanel(); });
    document.getElementById('closeLostPanel')?.addEventListener('click', (e) => { e.stopPropagation(); document.getElementById('lostBagsPanel').style.display='none'; });
    document.getElementById('closeDamagedPanel')?.addEventListener('click', (e) => { e.stopPropagation(); document.getElementById('damagedBagsPanel').style.display='none'; });

    document.getElementById('fuelSettingsBtn')?.addEventListener('click', () => {
      const p = document.getElementById('fuelSettingsPanel');
      if (p) p.style.display = p.style.display === 'none' ? 'block' : 'none';
    });
    document.getElementById('btnAddTank')?.addEventListener('click', () => {
      document.getElementById('addTankModal')?.classList.add('open');
    });
    document.getElementById('addTankClose')?.addEventListener('click', () => document.getElementById('addTankModal').classList.remove('open'));
    document.getElementById('addTankCancel')?.addEventListener('click', () => document.getElementById('addTankModal').classList.remove('open'));
    document.getElementById('addTankSave')?.addEventListener('click', async () => {
      try {
        await API.post('api/actions.php', {
          action: 'add_tank',
          name: document.getElementById('tkName').value.trim(),
          fuel_type: document.getElementById('tkType').value,
          capacity_l: parseFloat(document.getElementById('tkCapL').value) || 0,
          level_pct: parseInt(document.getElementById('tkLevel').value) || 80,
          low_threshold_pct: parseInt(document.getElementById('tkThr').value) || 20,
        });
        showAlert('success', 'Tank added', document.getElementById('tkName').value);
        document.getElementById('addTankModal').classList.remove('open');
        renderFuel();
      } catch (e) { showAlert('danger', 'Error', e.message); }
    });

    document.getElementById('btnManageTanks')?.addEventListener('click', () => {
      const d = window.__fuelData || { tanks: [] };
      const body = document.getElementById('manageTanksBody');
      body.innerHTML = (d.tanks || []).map(t => {
        const capL = Math.round(Number(t.capacity_gal) * 3.78541);
        return `<div class="card" style="margin-bottom:10px;padding:12px" data-tid="${t.id}">
          <div style="font-weight:600;margin-bottom:8px">${t.name} · ${(t.fuel_type||'').toUpperCase()}</div>
          <div class="form-row">
            <div class="form-group"><label>Level %</label><input type="number" class="mt-level" min="0" max="100" value="${t.level_pct}"></div>
            <div class="form-group"><label>Capacity (L)</label><input type="number" class="mt-cap" min="1000" value="${capL}"></div>
            <div class="form-group"><label>Low threshold %</label><input type="number" class="mt-thr" min="5" max="50" value="${t.low_threshold_pct}"></div>
          </div>
        </div>`;
      }).join('') || '<div style="color:var(--muted)">No tanks</div>';
      document.getElementById('manageTanksModal').classList.add('open');
    });
    document.getElementById('manageTanksClose')?.addEventListener('click', () => document.getElementById('manageTanksModal').classList.remove('open'));
    document.getElementById('manageTanksCancel')?.addEventListener('click', () => document.getElementById('manageTanksModal').classList.remove('open'));
    document.getElementById('manageTanksSave')?.addEventListener('click', async () => {
      try {
        const cards = document.querySelectorAll('#manageTanksBody [data-tid]');
        for (const card of cards) {
          await API.post('api/actions.php', {
            action: 'update_tank',
            tank_id: parseInt(card.dataset.tid),
            level_pct: parseInt(card.querySelector('.mt-level').value) || 0,
            capacity_l: parseFloat(card.querySelector('.mt-cap').value) || 0,
            low_threshold_pct: parseInt(card.querySelector('.mt-thr').value) || 20,
          });
        }
        showAlert('success', 'Tanks updated', '');
        document.getElementById('manageTanksModal').classList.remove('open');
        renderFuel();
      } catch (e) { showAlert('danger', 'Error', e.message); }
    });

    document.getElementById('camSettingsBtn')?.addEventListener('click', () => {
      const p = document.getElementById('camSettingsPanel');
      if (p) p.style.display = p.style.display === 'none' ? 'block' : 'none';
    });
    document.getElementById('btnAddCamera')?.addEventListener('click', () => {
      document.getElementById('addCameraModal')?.classList.add('open');
    });
    document.getElementById('addCameraClose')?.addEventListener('click', () => document.getElementById('addCameraModal').classList.remove('open'));
    document.getElementById('addCameraCancel')?.addEventListener('click', () => document.getElementById('addCameraModal').classList.remove('open'));
    document.getElementById('addCameraSave')?.addEventListener('click', async () => {
      try {
        await API.post('api/actions.php', {
          action: 'add_camera',
          cam_code: document.getElementById('camCode').value.trim(),
          zone: document.getElementById('camZone').value.trim(),
          snapshot_url: document.getElementById('camSnap').value.trim(),
          status: document.getElementById('camStatus').value,
        });
        showAlert('success', 'Camera added', document.getElementById('camCode').value);
        document.getElementById('addCameraModal').classList.remove('open');
        if (typeof renderSafety === 'function') renderSafety();
      } catch (e) { showAlert('danger', 'Error', e.message); }
    });
  }, 400);
});


/* Staff status filter + Add bag modals */
window.__staffStatusFilter = 'all';
document.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => {
    document.querySelectorAll('[data-sfilter]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('[data-sfilter]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        window.__staffStatusFilter = btn.dataset.sfilter;
        window.__staffPage = 1;
        if (typeof renderStaff === 'function') renderStaff();
      });
    });

    function openAddBagModal(mode) {
      document.getElementById('abMode').value = mode;
      document.getElementById('addBagTitle').textContent = mode === 'damaged' ? 'Add damaged bag' : 'Add lost bag';
      const st = document.getElementById('abStatus');
      if (mode === 'damaged') {
        st.innerHTML = '<option value="damaged" selected>Damaged</option>';
      } else {
        st.innerHTML = '<option value="missing" selected>Lost / Missing</option><option value="found">Found</option>';
      }
      document.getElementById('abId').value = '';
      document.getElementById('abFlight').value = '';
      document.getElementById('abOwner').value = '';
      document.getElementById('abOrigin').value = '';
      document.getElementById('abDest').value = 'ATL';
      document.getElementById('addBagModal').classList.add('open');
    }
    document.getElementById('btnAddLostBag')?.addEventListener('click', () => openAddBagModal('lost'));
    document.getElementById('btnAddDamagedBag')?.addEventListener('click', () => openAddBagModal('damaged'));
    document.getElementById('addBagClose')?.addEventListener('click', () => document.getElementById('addBagModal').classList.remove('open'));
    document.getElementById('addBagCancel')?.addEventListener('click', () => document.getElementById('addBagModal').classList.remove('open'));
    document.getElementById('addBagSave')?.addEventListener('click', async () => {
      try {
        const mode = document.getElementById('abMode').value;
        const status = document.getElementById('abStatus').value;
        await API.post('api/actions.php', {
          action: 'add_bag',
          bag_id: document.getElementById('abId').value.trim(),
          flight_number: document.getElementById('abFlight').value.trim(),
          owner_name: document.getElementById('abOwner').value.trim(),
          origin: document.getElementById('abOrigin').value.trim(),
          destination: document.getElementById('abDest').value.trim(),
          weight_kg: parseFloat(document.getElementById('abWeight').value) || 0,
          status,
        });
        showAlert('success', 'Bag added', document.getElementById('abId').value);
        document.getElementById('addBagModal').classList.remove('open');
        await renderBaggage();
        if (mode === 'damaged') openDamagedBagsPanel();
        else openLostBagsPanel();
      } catch (e) { showAlert('danger', 'Error', e.message); }
    });
  }, 450);
});


/* Fill tanks modal */
document.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => {
    const GAL_TO_L = 3.78541;
    function refreshFillSummary() {
      let totalL = 0, n = 0;
      document.querySelectorAll('#fillTanksBody .ft-row').forEach(row => {
        const chk = row.querySelector('.ft-check');
        const inp = row.querySelector('.ft-add');
        if (chk && chk.checked) {
          const v = parseFloat(inp.value) || 0;
          if (v > 0) { totalL += v; n++; }
        }
      });
      const sum = document.getElementById('fillTanksSummary');
      if (sum) sum.textContent = n ? `${n} tank(s) · ${Math.round(totalL).toLocaleString()} L via pipeline` : '';
    }
    document.getElementById('btnFillTanks')?.addEventListener('click', () => {
      const d = window.__fuelData || { tanks: [] };
      const body = document.getElementById('fillTanksBody');
      body.innerHTML = (d.tanks || []).map(t => {
        const capL = Math.round(Number(t.capacity_gal) * GAL_TO_L);
        const curL = Math.round(capL * t.level_pct / 100);
        const needFull = Math.max(0, capL - curL);
        return `<div class="ft-row card" style="margin-bottom:10px;padding:12px" data-tid="${t.id}">
          <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
            <input type="checkbox" class="ft-check" style="margin-top:4px">
            <div style="flex:1">
              <div style="font-weight:600">${t.name} · ${(t.fuel_type||'').toUpperCase()}</div>
              <div style="font-size:12px;color:var(--muted);margin:4px 0">Now: ${curL.toLocaleString()} L (${t.level_pct}%) · Cap: ${capL.toLocaleString()} L</div>
              <div class="form-group" style="margin:0"><label>Liters to add</label>
                <input type="number" class="ft-add" min="0" step="1000" value="${needFull}" style="max-width:200px">
              </div>
            </div>
          </label>
        </div>`;
      }).join('') || '<div style="color:var(--muted)">No tanks</div>';
      body.querySelectorAll('.ft-check, .ft-add').forEach(el => el.addEventListener('input', refreshFillSummary));
      body.querySelectorAll('.ft-check, .ft-add').forEach(el => el.addEventListener('change', refreshFillSummary));
      refreshFillSummary();
      document.getElementById('fillTanksModal').classList.add('open');
    });
    document.getElementById('fillTanksClose')?.addEventListener('click', () => document.getElementById('fillTanksModal').classList.remove('open'));
    document.getElementById('fillTanksCancel')?.addEventListener('click', () => document.getElementById('fillTanksModal').classList.remove('open'));
    document.getElementById('fillTanksSave')?.addEventListener('click', async () => {
      const tanks = [];
      document.querySelectorAll('#fillTanksBody .ft-row').forEach(row => {
        const chk = row.querySelector('.ft-check');
        const inp = row.querySelector('.ft-add');
        if (chk && chk.checked) {
          const v = parseFloat(inp.value) || 0;
          if (v > 0) tanks.push({ tank_id: parseInt(row.dataset.tid), add_liters: v });
        }
      });
      if (!tanks.length) { showAlert('warning', 'Select tanks', 'Check at least one tank and enter liters'); return; }
      try {
        const res = await API.post('api/actions.php', { action: 'fill_tanks', tanks });
        showAlert('success', 'Pipeline transfer', (res.updated||[]).map(u => `${u.name} +${Math.round(u.added_l).toLocaleString()}L via pipeline`).join(' · '));
        document.getElementById('fillTanksModal').classList.remove('open');
        renderFuel();
      } catch (e) { showAlert('danger', 'Error', e.message); }
    });
  }, 500);
});


/* Ground vehicle model catalog (autocomplete) */
const VEHICLE_CATALOG = [
  // Taxi / sedan / black car
  {mfr:'Toyota', model:'Camry', type:'taxi', cap:4},
  {mfr:'Toyota', model:'Camry Hybrid', type:'taxi', cap:4},
  {mfr:'Toyota', model:'Corolla', type:'taxi', cap:4},
  {mfr:'Toyota', model:'Prius', type:'taxi', cap:4},
  {mfr:'Toyota', model:'Avalon', type:'taxi', cap:4},
  {mfr:'Honda', model:'Accord', type:'taxi', cap:4},
  {mfr:'Honda', model:'Civic', type:'taxi', cap:4},
  {mfr:'Honda', model:'Insight', type:'taxi', cap:4},
  {mfr:'Hyundai', model:'Sonata', type:'taxi', cap:4},
  {mfr:'Hyundai', model:'Elantra', type:'taxi', cap:4},
  {mfr:'Kia', model:'K5', type:'taxi', cap:4},
  {mfr:'Kia', model:'Optima', type:'taxi', cap:4},
  {mfr:'Nissan', model:'Altima', type:'taxi', cap:4},
  {mfr:'Nissan', model:'Maxima', type:'taxi', cap:4},
  {mfr:'Chevrolet', model:'Malibu', type:'taxi', cap:4},
  {mfr:'Chevrolet', model:'Impala', type:'taxi', cap:4},
  {mfr:'Chevrolet', model:'Suburban', type:'taxi', cap:7},
  {mfr:'Chevrolet', model:'Tahoe', type:'taxi', cap:6},
  {mfr:'Ford', model:'Fusion', type:'taxi', cap:4},
  {mfr:'Ford', model:'Crown Victoria', type:'taxi', cap:4},
  {mfr:'Ford', model:'Taurus', type:'taxi', cap:4},
  {mfr:'Ford', model:'Expedition', type:'taxi', cap:7},
  {mfr:'Lincoln', model:'Town Car', type:'taxi', cap:4},
  {mfr:'Lincoln', model:'Continental', type:'taxi', cap:4},
  {mfr:'Lincoln', model:'Navigator', type:'taxi', cap:7},
  {mfr:'Cadillac', model:'Escalade', type:'taxi', cap:7},
  {mfr:'Cadillac', model:'CT6', type:'taxi', cap:4},
  {mfr:'Mercedes-Benz', model:'S-Class', type:'taxi', cap:4},
  {mfr:'Mercedes-Benz', model:'E-Class', type:'taxi', cap:4},
  {mfr:'Mercedes-Benz', model:'GLS', type:'taxi', cap:6},
  {mfr:'BMW', model:'7 Series', type:'taxi', cap:4},
  {mfr:'BMW', model:'5 Series', type:'taxi', cap:4},
  {mfr:'Audi', model:'A8', type:'taxi', cap:4},
  {mfr:'Audi', model:'A6', type:'taxi', cap:4},
  {mfr:'Lexus', model:'ES', type:'taxi', cap:4},
  {mfr:'Lexus', model:'LS', type:'taxi', cap:4},
  {mfr:'Tesla', model:'Model S', type:'taxi', cap:4},
  {mfr:'Tesla', model:'Model 3', type:'taxi', cap:4},
  {mfr:'Tesla', model:'Model Y', type:'taxi', cap:5},
  {mfr:'Tesla', model:'Model X', type:'taxi', cap:6},
  // Vans
  {mfr:'Ford', model:'Transit', type:'van', cap:10},
  {mfr:'Ford', model:'Transit Passenger', type:'van', cap:8},
  {mfr:'Ford', model:'Transit Connect', type:'van', cap:5},
  {mfr:'Ford', model:'E350', type:'van', cap:11},
  {mfr:'Ford', model:'E450', type:'van', cap:14},
  {mfr:'Mercedes-Benz', model:'Sprinter', type:'van', cap:12},
  {mfr:'Mercedes-Benz', model:'Sprinter VIP', type:'van', cap:14},
  {mfr:'Mercedes-Benz', model:'Metris', type:'van', cap:7},
  {mfr:'Ram', model:'ProMaster', type:'van', cap:9},
  {mfr:'Ram', model:'ProMaster City', type:'van', cap:5},
  {mfr:'Nissan', model:'NV3500', type:'van', cap:12},
  {mfr:'Nissan', model:'NV200', type:'van', cap:7},
  {mfr:'Chevrolet', model:'Express', type:'van', cap:12},
  {mfr:'GMC', model:'Savana', type:'van', cap:12},
  {mfr:'Toyota', model:'Sienna', type:'van', cap:7},
  {mfr:'Honda', model:'Odyssey', type:'van', cap:7},
  {mfr:'Chrysler', model:'Pacifica', type:'van', cap:7},
  {mfr:'Volkswagen', model:'Transporter', type:'van', cap:9},
  {mfr:'Citroën', model:'Jumper', type:'van', cap:9},
  {mfr:'Peugeot', model:'Boxer', type:'van', cap:9},
  {mfr:'Fiat', model:'Ducato', type:'van', cap:9},
  // Buses / shuttles
  {mfr:'New Flyer', model:'Xn40', type:'bus', cap:40},
  {mfr:'New Flyer', model:'XD40', type:'bus', cap:38},
  {mfr:'New Flyer', model:'Xcelsior', type:'bus', cap:42},
  {mfr:'Gillig', model:'Low Floor', type:'bus', cap:45},
  {mfr:'Gillig', model:'BRT', type:'bus', cap:40},
  {mfr:'Golden Dragon', model:'XML6125', type:'bus', cap:50},
  {mfr:'Golden Dragon', model:'XML6115', type:'bus', cap:45},
  {mfr:'Ford', model:'E-Series Shuttle', type:'bus', cap:20},
  {mfr:'Ford', model:'F650 Shuttle', type:'bus', cap:25},
  {mfr:'BYD', model:'K9', type:'bus', cap:40},
  {mfr:'BYD', model:'K7', type:'bus', cap:30},
  {mfr:'Proterra', model:'ZX5', type:'bus', cap:40},
  {mfr:'Nova Bus', model:'LFS', type:'bus', cap:40},
  {mfr:'Alexander Dennis', model:'Enviro200', type:'bus', cap:35},
  {mfr:'Alexander Dennis', model:'Enviro500', type:'bus', cap:80},
  {mfr:'MAN', model:'Lion\'s City', type:'bus', cap:50},
  {mfr:'Volvo', model:'7900', type:'bus', cap:45},
  {mfr:'Scania', model:'Citywide', type:'bus', cap:45},
  {mfr:'Setra', model:'S 417', type:'bus', cap:55},
  {mfr:'MCI', model:'J4500', type:'bus', cap:56},
  {mfr:'Prevost', model:'X3-45', type:'bus', cap:56},
  {mfr:'Blue Bird', model:'All American', type:'bus', cap:50},
  {mfr:'Thomas Built', model:'Saf-T-Liner', type:'bus', cap:48},
  {mfr:'Toyota', model:'Camry', type:'taxi', cap:4},
  {mfr:'Toyota', model:'Corolla', type:'taxi', cap:4},
  {mfr:'Toyota', model:'Prius', type:'taxi', cap:4},
  {mfr:'Toyota', model:'Sienna', type:'van', cap:7},
  {mfr:'Honda', model:'Accord', type:'taxi', cap:4},
  {mfr:'Honda', model:'Odyssey', type:'van', cap:7},
  {mfr:'Nissan', model:'Altima', type:'taxi', cap:4},
  {mfr:'Nissan', model:'NV200', type:'van', cap:7},
  {mfr:'Hyundai', model:'Sonata', type:'taxi', cap:4},
  {mfr:'Kia', model:'Carnival', type:'van', cap:8},
  {mfr:'Chevrolet', model:'Malibu', type:'taxi', cap:4},
  {mfr:'Chevrolet', model:'Suburban', type:'taxi', cap:7},
  {mfr:'Chevrolet', model:'Express', type:'van', cap:12},
  {mfr:'Cadillac', model:'Escalade', type:'taxi', cap:7},
  {mfr:'Lincoln', model:'Town Car', type:'taxi', cap:4},
  {mfr:'Lincoln', model:'Continental', type:'taxi', cap:4},
  {mfr:'Lincoln', model:'Navigator', type:'taxi', cap:7},
  {mfr:'Mercedes-Benz', model:'S-Class', type:'taxi', cap:4},
  {mfr:'Mercedes-Benz', model:'E-Class', type:'taxi', cap:4},
  {mfr:'Mercedes-Benz', model:'Sprinter', type:'van', cap:12},
  {mfr:'Mercedes-Benz', model:'Sprinter Executive', type:'van', cap:14},
  {mfr:'Ford', model:'Transit', type:'van', cap:12},
  {mfr:'Ford', model:'Transit Passenger', type:'van', cap:15},
  {mfr:'Ford', model:'E-350', type:'van', cap:12},
  {mfr:'Ford', model:'Expedition', type:'taxi', cap:7},
  {mfr:'BMW', model:'7 Series', type:'taxi', cap:4},
  {mfr:'Audi', model:'A8', type:'taxi', cap:4},
  {mfr:'Tesla', model:'Model S', type:'taxi', cap:5},
  {mfr:'Tesla', model:'Model Y', type:'taxi', cap:5},
  {mfr:'New Flyer', model:'Xcelsior', type:'bus', cap:40},
  {mfr:'New Flyer', model:'Xcelsior XD40', type:'bus', cap:42},
  {mfr:'Gillig', model:'Low Floor', type:'bus', cap:38},
  {mfr:'Gillig', model:'BRT', type:'bus', cap:40},
  {mfr:'Golden Dragon', model:'XML6125', type:'bus', cap:45},
  {mfr:'Proterra', model:'ZX5', type:'bus', cap:40},
  {mfr:'BYD', model:'K9', type:'bus', cap:40},
];

function openVehicleModal(type, vehicle) {
  const modal = document.getElementById('vehicleModal');
  if (!modal) return;
  document.getElementById('vehModalTitle').textContent = vehicle ? 'Edit ' + type : 'Add ' + type;
  document.getElementById('vehId').value = vehicle?.id || '';
  document.getElementById('vehType').value = type;
  document.getElementById('vehClass').value = vehicle?.service_class || (type==='taxi'?'economy':type==='van'?'shared':'marta');
  document.getElementById('vehModel').value = vehicle ? ((vehicle.manufacturer?vehicle.manufacturer+' ':'')+vehicle.model) : '';
  document.getElementById('vehMfr').value = vehicle?.manufacturer || '';
  document.getElementById('vehModelOnly').value = vehicle?.model || '';
  document.getElementById('vehCap').value = vehicle?.capacity_pax || 4;
  document.getElementById('vehDriver').value = vehicle?.driver_name || '';
  document.getElementById('vehCode').value = vehicle?.vehicle_code || '';
  document.getElementById('vehPlate').value = vehicle?.plate_number || '';
  document.getElementById('vehStatus').value = vehicle?.status || 'available';
  document.getElementById('vehCompany').value = vehicle?.company || '';
  document.getElementById('vehAcResults').innerHTML = '';
  modal.classList.add('open');
}

function openParkingModal(lotId, vehicle) {
  const modal = document.getElementById('parkingModal');
  if (!modal) return;
  document.getElementById('parkModalTitle').textContent = vehicle ? 'Edit vehicle' : 'Add vehicle to lot';
  document.getElementById('parkId').value = vehicle?.id || '';
  document.getElementById('parkLotId').value = lotId;
  document.getElementById('parkPlate').value = vehicle?.plate_number || '';
  document.getElementById('parkOwner').value = vehicle?.owner_name || '';
  document.getElementById('parkModel').value = vehicle ? ((vehicle.manufacturer?vehicle.manufacturer+' ':'')+(vehicle.model||'')) : '';
  document.getElementById('parkStatus').value = vehicle?.status || 'parked';
  document.getElementById('parkHours').value = vehicle?.duration_hours || '';
  document.getElementById('parkDays').value = vehicle?.duration_days || '';
  modal.classList.add('open');
}

document.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => {
    document.querySelectorAll('#transitTabs [data-ttab]').forEach(btn => {
      btn.addEventListener('click', () => {
        window.__transitTab = btn.dataset.ttab;
        renderTransit();
      });
    });

    const modelInput = document.getElementById('vehModel');
    const acBox = document.getElementById('vehAcResults');
    if (modelInput && acBox) {
      modelInput.addEventListener('input', () => {
        const q = modelInput.value.trim().toLowerCase();
        if (q.length < 2) { acBox.innerHTML = ''; return; }
        const type = document.getElementById('vehType').value;
        const hits = VEHICLE_CATALOG.filter(v =>
          (v.type === type || !type) &&
          (v.mfr.toLowerCase().includes(q) || v.model.toLowerCase().includes(q) || (v.mfr+' '+v.model).toLowerCase().includes(q))
        ).slice(0, 12);
        acBox.innerHTML = hits.map(h => `<div class="ac-item" data-mfr="${h.mfr}" data-model="${h.model}" data-cap="${h.cap}">
          <span class="code">${h.mfr}</span> <span>${h.model}</span> <span class="meta">· ${h.cap} pax</span></div>`).join('');
        acBox.querySelectorAll('.ac-item').forEach(item => {
          item.onclick = () => {
            document.getElementById('vehMfr').value = item.dataset.mfr;
            document.getElementById('vehModelOnly').value = item.dataset.model;
            document.getElementById('vehCap').value = item.dataset.cap;
            modelInput.value = item.dataset.mfr + ' ' + item.dataset.model;
            acBox.innerHTML = '';
          };
        });
      });
    }

    document.getElementById('vehModalClose')?.addEventListener('click', () => document.getElementById('vehicleModal').classList.remove('open'));
    document.getElementById('vehModalCancel')?.addEventListener('click', () => document.getElementById('vehicleModal').classList.remove('open'));
    document.getElementById('vehModalSave')?.addEventListener('click', async () => {
      try {
        let mfr = document.getElementById('vehMfr').value.trim();
        let model = document.getElementById('vehModelOnly').value.trim();
        const raw = document.getElementById('vehModel').value.trim();
        if (!model && raw) {
          const parts = raw.split(/\s+/);
          if (!mfr && parts.length > 1) { mfr = parts[0]; model = parts.slice(1).join(' '); }
          else model = raw;
        }
        await API.post('api/actions.php', {
          action: 'save_ground_vehicle',
          id: parseInt(document.getElementById('vehId').value) || 0,
          fleet_type: document.getElementById('vehType').value,
          service_class: document.getElementById('vehClass').value,
          vehicle_code: document.getElementById('vehCode').value.trim(),
          plate_number: document.getElementById('vehPlate').value.trim(),
          manufacturer: mfr,
          model: model,
          capacity_pax: parseInt(document.getElementById('vehCap').value) || 4,
          driver_name: document.getElementById('vehDriver').value.trim(),
          status: document.getElementById('vehStatus').value,
          company: document.getElementById('vehCompany').value.trim(),
        });
        showAlert('success', 'Saved', model || 'Vehicle');
        document.getElementById('vehicleModal').classList.remove('open');
        renderTransit();
      } catch (e) { showAlert('danger', 'Error', e.message); }
    });

    document.getElementById('parkModalClose')?.addEventListener('click', () => document.getElementById('parkingModal').classList.remove('open'));
    document.getElementById('parkModalCancel')?.addEventListener('click', () => document.getElementById('parkingModal').classList.remove('open'));
    document.getElementById('parkModalSave')?.addEventListener('click', async () => {
      try {
        const raw = document.getElementById('parkModel').value.trim();
        let mfr = '', model = raw;
        const parts = raw.split(/\s+/);
        if (parts.length > 1) { mfr = parts[0]; model = parts.slice(1).join(' '); }
        await API.post('api/actions.php', {
          action: 'save_parking_vehicle',
          id: parseInt(document.getElementById('parkId').value) || 0,
          lot_id: parseInt(document.getElementById('parkLotId').value),
          plate_number: document.getElementById('parkPlate').value.trim(),
          owner_name: document.getElementById('parkOwner').value.trim(),
          manufacturer: mfr,
          model: model,
          status: document.getElementById('parkStatus').value,
          duration_hours: document.getElementById('parkHours').value,
          duration_days: document.getElementById('parkDays').value,
        });
        showAlert('success', 'Saved', document.getElementById('parkPlate').value);
        document.getElementById('parkingModal').classList.remove('open');
        renderTransit();
      } catch (e) { showAlert('danger', 'Error', e.message); }
    });
  }, 400);
});
