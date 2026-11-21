/* ZarDrive driver app: sign up, go online, answer requests (accept or counter), run the trip, collect cash, wallet and top-ups. */
'use strict';

const TK = 'zd_d_token';
const api = (m, p, b) => ZD.api(m, p, b, TK);
const $sheet = document.getElementById('sheet');
const esc = ZD.esc;
const D = { me: null, cfg: null, sent: {}, trip: null, pos: null, watch: null, lastSent: 0, seq: 0 };

let handlers = {};
function screen(html, on, mode, keepTimers) {
  if (!keepTimers) ZD.clearTimers();
  document.body.classList.toggle('full', mode === 'full' || mode === 'night');
  document.body.classList.toggle('night', mode === 'night' || mode === 'nightmap');
  document.body.classList.toggle('reqm', mode === 'nightmap');
  document.body.classList.toggle('tall', mode === 'tall');
  document.querySelectorAll('.map-ov').forEach(n => n.remove());
  document.querySelector('meta[name=theme-color]').content = (mode === 'night' || mode === 'nightmap') ? '#0C0F12' : '#FFFFFF';
  $sheet.innerHTML = html; if (!keepTimers) $sheet.scrollTop = 0;
  handlers = on || {};
  ZD.refreshMap();
}
$sheet.addEventListener('click', e => { const b = e.target.closest('[data-act]'); if (b && handlers[b.dataset.act]) handlers[b.dataset.act](b, e); });
function busy(b, t) { if (b) { b.disabled = true; b.dataset.t = b.textContent; b.textContent = t || 'Please wait…'; } }
function unbusy(b) { if (b) { b.disabled = false; b.textContent = b.dataset.t || b.textContent; } }
function fail(e, b) { unbusy(b); if (e.status === 401) { ZD.store(TK, null); return signIn(); } ZD.toast(e.message); }
const DI = {
  menu: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
  gear: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2l2 3 3.5-.8.8 3.5 3 2-2 3 .8 3.5-3.5.8-2 3-3-2-3.5.8-.8-3.5-3-2 2-3-.8-3.5 3.5-.8z"/></svg>',
  list: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></svg>',
  perf: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
  dots: '<svg width="6" height="22" viewBox="0 0 6 22" aria-hidden="true"><circle cx="3" cy="3" r="2.6" fill="currentColor"/><circle cx="3" cy="11" r="2.6" fill="currentColor"/><circle cx="3" cy="19" r="2.6" fill="currentColor"/></svg>',
  clock: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 13l4-4 4 4"/></svg>',
  chev: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>',
  x: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
  car: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M3 16v-3l2-5h14l2 5v3zM6 16v2M18 16v2"/><circle cx="7.5" cy="13" r="1"/><circle cx="16.5" cy="13" r="1"/></svg>',
  wallet: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M16 11h5v4h-5a2 2 0 0 1 0-4zM6 6l9-3 1 3"/></svg>',
  shield: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/></svg>',
  chat: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="13" rx="2.5"/><path d="M8 17v4l4-4M8 10h.01M12 10h.01M16 10h.01"/></svg>',
};
const VLABEL = { economy: 'Mini', bike: 'Moto', comfort: 'Ride A/C', rickshaw: 'Rickshaw', loader: 'Freight' };
const SVC_TAG = { food: 'Food', courier: 'Courier', intercity: 'City to city', freight: 'Freight' };
const km1 = (k) => (k == null ? '' : '~' + String((+k).toFixed(1)).replace('.', ',') + ' km');
const nav = (on) => `<nav class="dnav sticky"><button data-act="go_home" class="${on === 'home' ? 'on' : ''}">${DI.list}<span>Ride requests</span></button><button data-act="go_perf" class="${on === 'perf' ? 'on' : ''}">${DI.perf}<span>Performance</span></button></nav>`;
const navHandlers = { go_home: () => home(), go_wallet: () => wallet(), go_menu: () => menu(), go_perf: () => perf(), go_settings: () => settings() };

// ---------- location: sent while online or on a trip
function startTracking() {
  if (D.watch || !navigator.geolocation) return;
  D.watch = navigator.geolocation.watchPosition(p => { D.pos = { lat: p.coords.latitude, lng: p.coords.longitude }; sendPos(false); }, () => {}, { enableHighAccuracy: true, maximumAge: 10000 });
}
function stopTracking() { if (D.watch) navigator.geolocation.clearWatch(D.watch); D.watch = null; }
async function sendPos(force) {
  if (!D.pos || (!force && Date.now() - D.lastSent < 10000)) return;
  D.lastSent = Date.now();
  try { await api('POST', '/driver/location', D.pos); } catch (e) { /* outside Pakistan or offline: ignore */ }
}

// ---------- start
async function boot() {
  try { D.cfg = await api('GET', '/config'); } catch (e) {}
  await ZD.initMap(D.cfg && D.cfg.maps);
  if (!ZD.store(TK)) return signIn();
  try { await refreshMe(); route(); } catch (e) { fail(e); }
}
async function refreshMe() { D.me = await api('GET', '/me'); if (D.me.role !== 'driver') { ZD.store(TK, null); throw Object.assign(new Error('Please sign in with a driver number'), { status: 401 }); } }
async function route() {
  if (!+D.me.driver.verified) return waiting();
  const a = (await api('GET', '/my/active')).trip;
  if (a && a.status !== 'searching') { D.trip = a; return trip(); }
  home();
}

// ---------- sign in / sign up
function signIn(step, phone, code) {
  step = step || 'phone';
  const vehs = Object.entries((D.cfg && D.cfg.vehicles) || { bike: 'Motorbike', rickshaw: 'Rickshaw', economy: 'Car economy', comfort: 'Car comfort (AC)', loader: 'Loader / pickup' });
  let body = '';
  if (step === 'phone') body = `<label class="field"><span>Mobile number</span><div class="phone"><b>+92</b><input id="ph" inputmode="tel" placeholder="312 7654321" value="${esc(phone || '')}"></div></label><button class="btn primary" data-act="send">Send code</button>`;
  if (step === 'code') body = `<label class="field"><span>Code sent to ${esc(phone)}</span><input id="code" class="input" inputmode="numeric" maxlength="4" style="font-size:24px;letter-spacing:10px;text-align:center"></label>
    ${D.debugCode ? `<div class="warn-note">Test site: your code is <b>${esc(D.debugCode)}</b> (SMS is not connected yet).</div>` : ''}<button class="btn primary" data-act="verify">Verify</button><button class="btn ghost" data-act="back">Change number</button>`;
  if (step === 'new') body = `<h2 class="title">Become a ZarDrive driver</h2>
    <label class="field"><span>Your name</span><input id="nm" class="input" placeholder="Full name as on CNIC"></label>
    <label class="field"><span>Vehicle</span><select id="veh" class="input">${vehs.map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join('')}</select></label>
    <label class="field"><span>Number plate</span><input id="plate" class="input" placeholder="e.g. LEB 4521" maxlength="20"></label>
    <div class="soft small">Next, our team checks your CNIC, driving licence, selfie and vehicle papers, usually within 24 hours. You can go online after approval and a first wallet top-up.</div>
    <button class="btn primary" data-act="create">Sign up</button>`;
  screen(`<div class="row" style="margin-top:6px"><div style="width:40px;height:40px;border-radius:12px;background:var(--ink);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Space Grotesk',Inter,sans-serif;font-weight:700;font-size:20px">Z</div><b style="font-family:'Space Grotesk',Inter,sans-serif;font-size:22px">ZarDrive <span style="color:var(--red)">Driver</span></b></div>
    ${step === 'phone' ? '<h1 class="title" style="font-size:26px">Set your price. Keep 94%.</h1><div class="muted">Rides, food delivery, parcels, intercity and loads. 6% per trip, or a day or week pass.</div>' : ''}${body}`, {
    async send(b) { const ph = document.getElementById('ph').value; busy(b, 'Sending…'); try { D.debugCode = (await api('POST', '/auth/otp', { phone: ph })).debug_code; signIn('code', ph); } catch (e) { fail(e, b); } },
    back() { signIn('phone', phone); },
    async verify(b) { const c = document.getElementById('code').value.trim(); busy(b, 'Checking…');
      try { await finish(phone, c, {}); } catch (e) { if (e.message === 'Enter your name' || e.message === 'Choose your vehicle') return signIn('new', phone, c); fail(e, b); } },
    async create(b) { const n = document.getElementById('nm').value.trim(); if (!n) return ZD.toast('Enter your name'); busy(b);
      try { await finish(phone, code, { name: n, driver: { vehicle: document.getElementById('veh').value, plate: document.getElementById('plate').value.trim() } }); } catch (e) { fail(e, b); } },
  }, 'full');
}
async function finish(phone, code, extra) {
  const r = await api('POST', '/auth/verify', Object.assign({ phone, code, role: 'driver' }, extra));
  ZD.store(TK, r.token); D.debugCode = null; await refreshMe(); route();
}

// ---------- waiting for document checks
function waiting() {
  screen(`<h2 class="title">Thanks, ${esc(D.me.name)}</h2>
    <div class="warn-note">Your account is waiting for checks. Our team will call you to collect photos of your CNIC (both sides), driving licence, a selfie and your vehicle papers.</div>
    <div class="card col"><div class="row"><span class="grow muted">Vehicle</span><b>${esc(D.me.driver.vehicle_label)}</b></div><div class="row"><span class="grow muted">Number plate</span><b>${esc(D.me.driver.plate || '—')}</b></div></div>
    <button class="btn dark" data-act="check">Check again</button><button class="btn ghost" data-act="out">Sign out</button>`, {
    async check(b) { busy(b); try { await refreshMe(); route(); } catch (e) { fail(e, b); } },
    out() { ZD.store(TK, null); signIn(); },
  }, 'full');
}

// ---------- home: online switch and ride requests (night mode, inDrive layout)
async function home() {
  const my = ++D.seq;
  try { await refreshMe(); } catch (e) { return fail(e); }
  if (my !== D.seq) return;  // the driver moved to another screen meanwhile
  const dv = D.me.driver; const online = +dv.online === 1;
  if (online) startTracking(); else stopTracking();
  screen(`<div class="dtop"><button class="dt-btn" data-act="go_menu" aria-label="Menu">${DI.menu}</button>
      <button class="olt" data-act="toggle" role="switch" aria-checked="${online}" aria-label="${online ? 'Online. Tap to go offline' : 'Offline. Tap to go online'}"><span class="${online ? '' : 'act off'}">Offline</span><span class="${online ? 'act on' : ''}">Online</span></button>
      <button class="dt-btn" data-act="go_settings" aria-label="Settings">${DI.gear}</button></div>
    <button class="goal" data-act="go_perf"><span class="coin">Rs</span><span class="grow"><b>${ZD.rs(dv.today.fares)}</b> today</span><span class="goal-n">${dv.today.trips} ${+dv.today.trips === 1 ? 'trip' : 'trips'}</span></button>
    ${dv.blocked && online ? `<div class="warn-note row"><span class="grow">${esc(dv.blocked)}</span>${/wallet|Top up/i.test(dv.blocked) ? '<button class="btn small primary" data-act="go_wallet">Top up</button>' : ''}</div>` : ''}
    <div id="reqs" class="rq-list">${online ? '<div class="muted" style="text-align:center;padding:24px 16px">Looking for ride requests near you…</div>' : '<div class="muted" style="text-align:center;padding:24px 16px">You are offline. Go online to see ride requests.</div>'}</div>
    ${nav('home')}`, Object.assign({
    async toggle(b) { busy(b); const pos = await ZD.here(6000); if (pos) D.pos = pos;
      try { await api('POST', '/driver/online', Object.assign({ online: online ? 0 : 1 }, D.pos || {})); home(); } catch (e) { fail(e, b); } },
    open(b) { const r = (D.lastReqs || []).find(x => x.id == b.dataset.id); if (r) request(r); },
  }, navHandlers), 'night');
  if (!online) return;
  ZD.every(4000, async () => {
    try {
      const a = (await api('GET', '/my/active')).trip;
      if (a && a.status === 'accepted') { D.trip = a; ZD.toast('Your offer was accepted'); return trip(); }
      const r = await api('GET', '/driver/requests'); D.lastReqs = r.requests; drawReqs(r.requests);
    } catch (e) {
      if (e.status === 401) return fail(e);
      const box = document.getElementById('reqs'); if (box) box.innerHTML = `<div class="warn-note" style="margin:12px 16px">${esc(e.message)}</div>`;
    }
  });
}
function rqTags(r) {
  return `<span class="tags"><span class="tag cash">Cash</span>${SVC_TAG[r.service] ? `<span class="tag svc">${esc(SVC_TAG[r.service])}</span>` : ''}<span class="tag tveh">${esc(VLABEL[r.vehicle] || r.vehicle)}</span></span>`;
}
function rqWho(r) {
  return `<span class="rq-who"><span class="av gray">${esc(ZD.initials(r.passenger_name))}</span><span class="nm ell">${esc(r.passenger_name)}</span>
    <span class="rt"><span style="color:#E5A52E">★</span> ${r.passenger_rating ? (+r.passenger_rating).toFixed(1) : 'New'}${r.passenger_trips ? ` (${r.passenger_trips})` : ''}</span>${r.pickup_min ? `<span class="rt">${r.pickup_min} min.</span>` : ''}</span>`;
}
function drawReqs(list) {
  const box = document.getElementById('reqs'); if (!box) return;
  const waitingIds = Object.keys(D.sent).filter(id => Date.now() - D.sent[id].at < 35000);
  const sentCards = waitingIds.map(id => `<div class="rq-wait row"><div class="pulse" style="width:36px;height:36px;margin:0"><i style="width:12px;height:12px"></i></div><span class="grow">Offer ${ZD.rs(D.sent[id].price)} sent. Waiting for the passenger…</span></div>`).join('');
  const open = list.filter(r => !D.sent[r.id]);
  box.innerHTML = sentCards + (open.map(r => `<button class="rq" data-act="open" data-id="${r.id}">${rqWho(r)}
      <span class="rq-body"><span class="km">${km1(r.pickup_km)}</span><span class="pr"><b class="num">${ZD.rs(r.offer)}</b>${r.fair ? `<span class="fair">${DI.clock} Fair price</span>` : ''}</span>
      <span class="a">${esc(r.from_text)}</span><span class="b">${esc(r.to_text)}</span>${rqTags(r)}</span><span class="rq-dots">${DI.dots}</span></button>`).join('')
    || (waitingIds.length ? '' : '<div class="muted" style="text-align:center;padding:24px 16px">No requests right now. Stay online: busy areas are Saddar, University Road and Hayatabad.</div>'));
}

// ---------- one ride request: map, accept or offer your fare
function request(r) {
  ++D.seq;
  const base = +r.offer; let more = false;
  const ups = () => (more ? [1.2, 1.3, 1.4] : [1.05, 1.1, 1.15]).map(f => Math.ceil(Math.round(base * f * 100) / 500) * 5);
  const draw = () => screen(`<div class="row" style="align-items:flex-start;gap:14px">${rqWho(r)}
      <span class="rq-body"><span class="km">${km1(r.pickup_km)}</span><span class="pr"><b class="num">${ZD.rs(base)}</b>${r.fair ? `<span class="fair">${DI.clock} Fair price</span>` : ''}</span>
        <span class="ab"><i class="pa">A</i><span>${esc(r.from_text)}</span></span><span class="ab"><i class="pb">B</i><span>${esc(r.to_text)}</span></span>${rqTags(r)}</span></div>
    ${r.note ? `<div class="soft small"><b>Note:</b> ${esc(r.note)}</div>` : ''}
    <div class="muted small" style="text-align:center">Trip ${r.km} km · passenger pays cash</div>
    <button class="btn primary big-btn" data-act="offer" data-p="${base}">Accept for ${ZD.rs(base)}</button>
    <div class="muted" style="text-align:center;font-size:17px">Offer your fare</div>
    <div class="row" style="gap:8px">${ups().map(p => `<button class="fare-chip" data-act="offer" data-p="${p}">${ZD.rs(p)}</button>`).join('')}<button class="fare-more" data-act="more" aria-label="${more ? 'Lower fares' : 'Higher fares'}">${more ? '‹' : DI.chev}</button></div>
    <button class="btn close-btn" data-act="close">Close</button>`, {
    async offer(b) { const price = +b.dataset.p; busy(b, 'Sending…');
      try { await api('POST', `/trips/${r.id}/offers`, { price }); D.sent[r.id] = { price, at: Date.now() }; ZD.toast(price === base ? 'Accepted. Waiting for the passenger…' : `Offer ${ZD.rs(price)} sent`); home(); } catch (e) { fail(e, b); } },
    more() { more = !more; draw(); poll(); },
    close() { home(); },
  }, 'nightmap');
  const poll = () => ZD.every(4000, async () => {
    try {
      const a = (await api('GET', '/my/active')).trip;
      if (a && a.status === 'accepted') { D.trip = a; ZD.toast('Your offer was accepted'); return trip(); }
      const list = (await api('GET', '/driver/requests')).requests; D.lastReqs = list;
      if (!list.some(x => x.id === r.id)) { ZD.toast('This request is no longer open'); home(); }
    } catch (e) { if (e.status === 401) fail(e); }
  });
  draw(); poll();
  const ov = document.createElement('div'); ov.className = 'map-ov req-title'; ov.textContent = 'Ride request'; document.body.appendChild(ov);
  ZD.clearMap(); const pts = [];
  if (r.from_lat) { ZD.pin(+r.from_lat, +r.from_lng, 'from'); pts.push([+r.from_lat, +r.from_lng]); }
  if (r.to_lat) { ZD.pin(+r.to_lat, +r.to_lng, 'to'); pts.push([+r.to_lat, +r.to_lng]); }
  if (pts.length === 2) ZD.line(pts);
  if (D.pos) { ZD.pin(D.pos.lat, D.pos.lng, 'car'); pts.push([D.pos.lat, D.pos.lng]); }
  if (pts.length) ZD.fit(pts);
}

// ---------- live trip
function trip() {
  ++D.seq;
  D.sent = {};
  startTracking(); sendPos(true);
  let cancelArm = false;
  const STEP = { accepted: 0, arrived: 1, started: 2, completed: 3 };
  const draw = (t) => {
    const st = STEP[t.status] ?? 0;
    if (t.status === 'completed') return collect(t);
    const toPickup = t.status !== 'started';
    const navLat = toPickup ? t.from_lat : t.to_lat, navLng = toPickup ? t.from_lng : t.to_lng;
    const title = { accepted: 'Go to pick-up', arrived: `Waiting for ${esc(t.passenger.name)}`, started: 'On trip' }[t.status];
    const btn = { accepted: ["arrived", "I've arrived"], arrived: ['started', 'Start trip'], started: ['completed', 'Finish trip'] }[t.status];
    screen(`<div class="steps">${[0, 1, 2, 3].map(i => `<i class="${i <= st ? 'on' : ''}"></i>`).join('')}</div>
      <div class="row"><h2 class="title grow">${title}</h2><a class="btn small danger" href="tel:15">SOS</a></div>
      <div class="row"><span class="av">${esc(ZD.initials(t.passenger.name))}</span><span class="grow"><b>${esc(t.passenger.name)}</b>${t.passenger.rating ? ` <span class="s">★ ${t.passenger.rating}</span>` : ''}<span class="s" style="display:block">${esc(toPickup ? 'Pick-up: ' + t.from_text : 'Drop: ' + t.to_text)}</span></span></div>
      ${navLat ? `<a class="btn ghost" href="${ZD.googleNav(navLat, navLng)}" target="_blank" rel="noopener">Navigate in Google Maps</a>` : ''}
      ${t.note ? `<div class="soft small"><b>Note:</b> ${esc(t.note)}</div>` : ''}
      <div class="soft row"><span class="grow small">Agreed fare · collect in cash at the end</span><b class="num" style="font-size:22px">${ZD.rs(t.fare)}</b></div>
      <button class="btn primary" data-act="next" data-to="${btn[0]}">${btn[1]}</button>
      ${t.status !== 'started' ? '<button class="btn danger" data-act="cancel">Cancel trip</button>' : ''}`, {
      async next(b) { busy(b); try { D.trip = await api('POST', `/trips/${t.id}/${b.dataset.to}`); D.trip = await api('GET', `/trips/${t.id}`); draw(D.trip); } catch (e) { fail(e, b); } },
      async cancel(b) { if (!cancelArm) { cancelArm = true; b.textContent = 'Tap again to cancel'; return; } busy(b);
        try { await api('POST', `/trips/${t.id}/cancel`, { reason: 'driver cancelled' }); ZD.toast('Trip cancelled'); home(); } catch (e) { fail(e, b); } },
    }, 'tall', true);
    ZD.clearMap(); const pts = [];
    if (t.from_lat) { ZD.pin(+t.from_lat, +t.from_lng, 'from'); pts.push([+t.from_lat, +t.from_lng]); }
    if (t.to_lat) { ZD.pin(+t.to_lat, +t.to_lng, 'to'); pts.push([+t.to_lat, +t.to_lng]); }
    if (D.pos) { ZD.pin(D.pos.lat, D.pos.lng, 'car'); pts.push([D.pos.lat, D.pos.lng]); }
    if (pts.length) ZD.fit(pts);
  };
  ZD.clearTimers();
  draw(D.trip);
  ZD.every(6000, async () => {
    sendPos(false);
    try { const t = await api('GET', `/trips/${D.trip.id}`);
      if (t.status === 'cancelled') { ZD.toast('The passenger cancelled the trip'); return home(); }
      if (t.status !== D.trip.status) { D.trip = t; draw(t); } } catch (e) { if (e.status === 401) fail(e); }
  });
}
function collect(t) {
  const taken = +t.wallet_taken || 0;
  screen(`<div class="steps"><i class="on"></i><i class="on"></i><i class="on"></i><i class="on"></i></div>
    <h2 class="title">Collect cash</h2>
    <div class="card col"><span class="muted">Collect from ${esc(t.passenger.name)}</span><span class="big">${ZD.rs(t.fare)}</span>
      ${t.service === 'food' ? '<span class="small">Plus the food bill you paid at the restaurant.</span>' : ''}
      <div class="row small" style="border-top:1px dashed #D5DADF;padding-top:8px"><span class="grow muted">ZarDrive ${t.service === 'food' ? 'share' : 'fee'} from your wallet</span><b>− ${ZD.rs2(taken)}</b></div>
      <div class="row small"><span class="grow muted">You keep</span><b style="color:var(--good)">${ZD.rs2(+t.fare - taken)}</b></div></div>
    <div class="muted small">Trip ZD-${t.id} · ${t.km} km. The fee is listed in Wallet history.</div>
    <button class="btn good" data-act="ok">Cash received</button>`, { ok() { D.trip = null; home(); } }, 'full');
}

// ---------- wallet, top-up, passes
async function wallet() {
  const my = ++D.seq;
  let w; try { await refreshMe(); w = await api('GET', '/wallet'); } catch (e) { return fail(e); }
  if (my !== D.seq) return;
  const dv = D.me.driver; let amt = 1000, method = 'jazzcash';
  const KIND = { topup: 'Top-up', commission: 'Trip fee', food_share: 'Food share', pass: 'Pass', refund: 'Refund' };
  const draw = () => screen(`<div class="row"><button class="icon-btn" style="box-shadow:none" data-act="go_home" aria-label="Back">${ZD.icon.back}</button><h2 class="title grow">Wallet</h2></div>
    <div class="card col" style="background:var(--ink);color:#fff;border:0"><span class="small" style="color:#C9CED4">Balance for ZarDrive fees</span><span class="big">${ZD.rs2(w.balance)}</span>
      <span class="small" style="color:#C9CED4">Passengers pay you cash. The ${dv.commission_pct}% fee comes off this balance after each trip; below ${ZD.rs(dv.min_wallet)} you stop getting requests.</span></div>
    <h3 style="margin:4px 0 0;font-size:16px">Top up</h3>
    <div class="chips">${[['jazzcash', 'JazzCash'], ['easypaisa', 'Easypaisa']].map(([k, l]) => `<button class="chip" data-act="method" data-m="${k}" aria-pressed="${k === method}">${l}</button>`).join('')}</div>
    <div class="chips">${[500, 1000, 2000, 5000].map(a => `<button class="chip" data-act="amt" data-a="${a}" aria-pressed="${a === amt}">${ZD.rs(a)}</button>`).join('')}</div>
    <div class="soft small">Send ${ZD.rs(amt)} to the ZarDrive ${method === 'jazzcash' ? 'JazzCash' : 'Easypaisa'} account shown by support, then enter the transaction ID (TID) from the SMS. Accounts adds it after checking the statement.</div>
    <label class="field"><span>Transaction ID (TID)</span><input id="tid" class="input" inputmode="numeric" maxlength="24" placeholder="e.g. 041277312290"></label>
    <button class="btn primary" data-act="topup">Send top-up for checking</button>
    <h3 style="margin:8px 0 0;font-size:16px">Passes · 0% fee</h3>
    <div class="tabsbar" style="grid-template-columns:1fr 1fr"><button class="btn ghost small" style="width:100%" data-act="pass" data-k="day">Day pass ${ZD.rs(dv.passes.day)}</button><button class="btn ghost small" style="width:100%" data-act="pass" data-k="week">Week pass ${ZD.rs(dv.passes.week)}</button></div>
    ${dv.plan !== 'per_trip' && dv.plan_until ? `<div class="ok-note">${esc(dv.plan)} pass active until ${esc(ZD.when(dv.plan_until))}</div>` : ''}
    <h3 style="margin:8px 0 0;font-size:16px">History</h3>
    <div>${(w.history || []).map(h => `<div class="hist"><span class="grow"><b style="display:block">${esc(KIND[h.kind] || h.kind)}</b><span class="s">${esc(h.note || '')} · ${esc(ZD.when(h.created_at))}</span></span><span style="text-align:right"><b style="color:${+h.amount < 0 ? '#912018' : 'var(--good)'}">${+h.amount < 0 ? '−' : '+'} ${ZD.rs2(Math.abs(+h.amount))}</b><span class="s" style="display:block">${ZD.rs2(h.balance_after)}</span></span></div>`).join('') || '<div class="muted">No wallet activity yet.</div>'}</div>
    `, Object.assign({
    method(b) { method = b.dataset.m; draw(); }, amt(b) { amt = +b.dataset.a; draw(); },
    async topup(b) { const tid = document.getElementById('tid').value.trim(); if (tid.length < 6) return ZD.toast('Enter the transaction ID from the SMS'); busy(b);
      try { await api('POST', '/wallet/topups', { method, tid, amount: amt }); ZD.toast('Top-up sent. It shows here once Accounts checks it.'); wallet(); } catch (e) { fail(e, b); } },
    async pass(b) { busy(b); try { await api('POST', '/wallet/pass', { kind: b.dataset.k }); ZD.toast('Pass active: 0% fee'); wallet(); } catch (e) { fail(e, b); } },
  }, navHandlers), 'full');
  draw();
}

function menu() {
  ++D.seq;
  const dv = D.me.driver; const rt = +dv.rating || 5;
  const stars = [1, 2, 3, 4, 5].map(i => `<span style="color:${i <= Math.round(rt) ? '#E5A52E' : '#4A525B'}">★</span>`).join('');
  const item = (act, ic, label) => `<button class="dm-item" data-act="${act}">${ic}<span>${label}</span></button>`;
  screen(`<div class="row" style="justify-content:flex-end"><button class="dt-btn" data-act="go_home" aria-label="Close menu">${DI.x}</button></div>
    <button class="dm-head" data-act="go_settings"><span class="av" style="width:60px;height:60px;border-radius:30px;background:#2A3038;color:#fff">${esc(ZD.initials(D.me.name))}</span>
      <span class="grow" style="text-align:left"><b style="display:block;font-size:20px">${esc(D.me.name)}</b><span style="font-size:17px">${stars} <span class="muted" style="font-size:15px">${rt.toFixed(2)}${dv.trips_total ? ` (${dv.trips_total})` : ''}</span></span></span>${DI.chev}</button>
    <div class="dm-list">
      ${item('go_home', DI.car, 'City rides')}${item('go_wallet', DI.wallet, 'Wallet')}${item('go_perf', DI.perf, 'Performance')}
      ${item('safety', DI.shield, 'Safety')}${item('go_settings', DI.gear, 'Settings')}${item('support', DI.chat, 'Support')}
    </div>
    <a class="btn primary" href="/app" style="margin-top:auto">Passenger mode</a>
    <div class="muted small" style="text-align:center">ZarDrive Driver test version · map data © OpenStreetMap contributors</div>`, Object.assign({
    safety() { safety(); }, support() { support(); },
  }, navHandlers), 'night');
}
async function perf() {
  const my = ++D.seq;
  try { await refreshMe(); } catch (e) { return fail(e); }
  if (my !== D.seq) return;
  const dv = D.me.driver;
  screen(`<div class="dtop"><button class="dt-btn" data-act="go_menu" aria-label="Menu">${DI.menu}</button><h2 class="title grow" style="text-align:center;color:#fff">Performance</h2><span style="width:48px"></span></div>
    <div class="kpis"><div class="kpi"><b>${ZD.rs(dv.today.fares)}</b><span>fares today</span></div><div class="kpi"><b>${dv.today.trips}</b><span>trips today</span></div><div class="kpi"><b>★ ${(+dv.rating).toFixed(2)}</b><span>your rating</span></div></div>
    <div class="card col"><div class="row"><span class="grow muted">ZarDrive fee</span><b>${dv.plan === 'per_trip' ? (+dv.commission_pct) + '% per trip' : esc(dv.plan) + ' pass · 0%'}</b></div>
      <div class="row"><span class="grow muted">Wallet balance</span><b>${ZD.rs2(dv.wallet)}</b></div>
      <div class="row"><span class="grow muted">Requests stop below</span><b>${ZD.rs(dv.min_wallet)}</b></div></div>
    <button class="btn ghost" data-act="go_wallet">Wallet, top-up and passes</button>
    <div class="muted small">Tips: accept fair-price requests quickly, keep your rating above 4.5, and stay online in busy areas (Saddar, University Road, Hayatabad) at lunch and evening.</div>
    ${nav('perf')}`, navHandlers, 'night');
}
function settings() {
  ++D.seq;
  const dv = D.me.driver;
  screen(`<div class="row"><button class="dt-btn" data-act="go_home" aria-label="Back">${ZD.icon.back}</button><h2 class="title grow" style="color:#fff">Settings</h2></div>
    <div class="card col"><div class="row"><span class="grow muted">Name</span><b>${esc(D.me.name)}</b></div><div class="row"><span class="grow muted">Phone</span><b>${esc(D.me.phone)}</b></div>
      <div class="row"><span class="grow muted">Vehicle</span><b>${esc(dv.vehicle_label)}</b></div><div class="row"><span class="grow muted">Number plate</span><b>${esc(dv.plate || '—')}</b></div>
      <div class="row"><span class="grow muted">Plan</span><b>${dv.plan === 'per_trip' ? dv.commission_pct + '% per trip' : esc(dv.plan) + ' pass'}</b></div><div class="row"><span class="grow muted">Language</span><b>English</b></div></div>
    <div class="muted small">To change your name, vehicle or plate, write to Support. Our team checks the new papers first.</div>
    <button class="btn danger" data-act="out">Sign out</button>`, Object.assign({ async out() { try { await api('POST', '/driver/online', { online: 0 }); } catch (e) {} stopTracking(); ZD.store(TK, null); signIn(); } }, navHandlers), 'night');
}
function safety() {
  ++D.seq;
  screen(`<div class="row"><button class="dt-btn" data-act="go_menu" aria-label="Back">${ZD.icon.back}</button><h2 class="title grow" style="color:#fff">Safety</h2></div>
    <a class="btn primary" href="tel:15">Call police · 15</a><a class="btn ghost" href="tel:1122">Rescue · 1122</a>
    <div class="card col small" style="line-height:1.5"><b>Before every trip</b><span>Check the pick-up and the passenger's name. Don't start the trip until the passenger is in the vehicle.</span>
      <b>Cash</b><span>Collect the agreed fare only. Keep small notes for change.</span><b>Problems</b><span>If a passenger is rude or unsafe, end the trip in a public place and write to Support.</span></div>`, navHandlers, 'night');
}
function support() {
  ++D.seq;
  let topic = 'Fare or payment';
  const topics = ['Fare or payment', 'App problem', 'Safety', 'Lost item', 'Other'];
  const draw = (past) => screen(`<div class="row"><button class="dt-btn" data-act="go_menu" aria-label="Back">${ZD.icon.back}</button><h2 class="title grow" style="color:#fff">Support</h2></div>
    <div class="chips">${topics.map(t => `<button class="chip" data-act="topic" aria-pressed="${t === topic}">${t}</button>`).join('')}</div>
    <textarea id="hmsg" class="input" style="height:110px;background:#171B20;color:#fff;border-color:#343B43" maxlength="1000" placeholder="Tell us what happened"></textarea>
    <button class="btn primary" data-act="send">Send to support</button>
    ${past.length ? `<h3 style="margin:6px 0 0">Your requests</h3>${past.map(h => `<div class="card col small"><b>${esc(h.topic)} · ${esc(h.status)}</b><span>${esc(h.message)}</span><span class="muted">${esc(ZD.when(h.created_at))}</span></div>`).join('')}` : ''}`, Object.assign({
    topic(b) { const m = document.getElementById('hmsg').value; topic = b.textContent; draw(past); document.getElementById('hmsg').value = m; },
    async send(b) { busy(b, 'Sending…'); try { await api('POST', '/help', { topic, message: document.getElementById('hmsg').value }); ZD.toast('Sent. Support will call you soon.'); support(); } catch (e) { fail(e, b); } },
  }, navHandlers), 'night');
  draw([]);
  api('GET', '/help').then(r => { if (r.requests.length) { const m = document.getElementById('hmsg'); const keep = m ? m.value : ''; draw(r.requests); const n = document.getElementById('hmsg'); if (n) n.value = keep; } }).catch(() => {});
}

boot();
