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
  document.body.classList.toggle('night', mode === 'night');
  document.body.classList.toggle('tall', mode === 'tall');
  document.querySelector('meta[name=theme-color]').content = mode === 'night' ? '#0C0F12' : '#FFFFFF';
  $sheet.innerHTML = html; if (!keepTimers) $sheet.scrollTop = 0;
  handlers = on || {};
  ZD.refreshMap();
}
$sheet.addEventListener('click', e => { const b = e.target.closest('[data-act]'); if (b && handlers[b.dataset.act]) handlers[b.dataset.act](b, e); });
function busy(b, t) { if (b) { b.disabled = true; b.dataset.t = b.textContent; b.textContent = t || 'Please wait…'; } }
function unbusy(b) { if (b) { b.disabled = false; b.textContent = b.dataset.t || b.textContent; } }
function fail(e, b) { unbusy(b); if (e.status === 401) { ZD.store(TK, null); return signIn(); } ZD.toast(e.message); }
const nav = (on) => `<div class="tabsbar sticky">
  ${[['home', 'Requests'], ['wallet', 'Wallet'], ['menu', 'Account']].map(([k, l]) => `<button class="btn small ${k === on ? 'primary' : 'ghost'}" style="width:100%" data-act="go_${k}">${l}</button>`).join('')}</div>`;
const navHandlers = { go_home: () => home(), go_wallet: () => wallet(), go_menu: () => menu() };

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

// ---------- home: online toggle and requests
async function home() {
  const my = ++D.seq;
  try { await refreshMe(); } catch (e) { return fail(e); }
  if (my !== D.seq) return;  // the driver moved to another screen meanwhile
  const dv = D.me.driver; const online = +dv.online === 1;
  if (online) startTracking(); else stopTracking();
  screen(`<div class="row"><span class="av" style="background:var(--red);color:#fff">${esc(ZD.initials(D.me.name))}</span>
      <span class="grow"><b>${esc(D.me.name)}</b><span class="s" style="display:block">★ ${(+dv.rating).toFixed(1)} · ${esc(dv.vehicle_label)} · ${esc(dv.plate || '')}</span></span>
      <button class="chip" data-act="go_wallet" style="height:auto;padding:6px 12px;text-align:right"><span class="s" style="display:block">Wallet</span><b class="num">${ZD.rs(dv.wallet)}</b></button></div>
    <button class="toggle ${online ? 'on' : 'off'}" data-act="toggle" aria-pressed="${online}"><i></i>${online ? 'Online · finding requests' : 'Go online'}</button>
    ${dv.blocked && online ? `<div class="warn-note row"><span class="grow">${esc(dv.blocked)}</span>${/wallet|Top up/i.test(dv.blocked) ? '<button class="btn small primary" data-act="go_wallet">Top up</button>' : ''}</div>` : ''}
    <div class="kpis"><div class="kpi"><b>${ZD.rs(dv.today.fares)}</b><span>fares today</span></div><div class="kpi"><b>${dv.today.trips}</b><span>trips today</span></div><div class="kpi"><b>${(+dv.commission_pct)}%</b><span>${dv.plan === 'per_trip' ? 'per trip' : esc(dv.plan) + ' pass'}</span></div></div>
    <div class="col" id="reqs">${online ? '<div class="muted" style="text-align:center;padding:16px">Looking for requests near you…</div>' : '<div class="muted" style="text-align:center;padding:16px">You are offline. No requests are sent to you.</div>'}</div>
    ${nav('home')}`, Object.assign({
    async toggle(b) { busy(b); const pos = await ZD.here(6000); if (pos) D.pos = pos;
      try { await api('POST', '/driver/online', Object.assign({ online: online ? 0 : 1 }, D.pos || {})); home(); } catch (e) { fail(e, b); } },
    async offer(b) { const id = +b.dataset.id, price = +b.dataset.p; busy(b, 'Sending…');
      try { await api('POST', `/trips/${id}/offers`, { price }); D.sent[id] = { price, at: Date.now() }; drawReqs(D.lastReqs || []); } catch (e) { fail(e, b); delete D.sent[id]; } },
  }, navHandlers), 'night');
  if (!online) return;
  ZD.every(4000, async () => {
    try {
      const a = (await api('GET', '/my/active')).trip;
      if (a && a.status === 'accepted') { D.trip = a; ZD.toast('Your offer was accepted'); return trip(); }
      const r = await api('GET', '/driver/requests'); D.lastReqs = r.requests; drawReqs(r.requests);
    } catch (e) {
      if (e.status === 401) return fail(e);
      const box = document.getElementById('reqs'); if (box) box.innerHTML = `<div class="warn-note">${esc(e.message)}</div>`;
    }
  });
}
function drawReqs(list) {
  const box = document.getElementById('reqs'); if (!box) return;
  const waitingIds = Object.keys(D.sent).filter(id => Date.now() - D.sent[id].at < 35000);
  const sentCards = waitingIds.map(id => `<div class="card row"><div class="pulse" style="width:36px;height:36px;margin:0"><i style="width:12px;height:12px"></i></div><span class="grow">Offer ${ZD.rs(D.sent[id].price)} sent. Waiting for the passenger…</span></div>`).join('');
  const open = list.filter(r => !D.sent[r.id]);
  box.innerHTML = sentCards + (open.map(r => {
    const base = +r.offer; const ups = [1.1, 1.2, 1.3].map(f => Math.ceil(base * f / 10) * 10);
    const svc = D.cfg ? D.cfg.services[r.service] : r.service;
    return `<div class="offer">
      <div class="row small"><span class="chip" style="height:24px;padding:0 8px;font-size:11.5px;font-weight:700">${esc(svc)}</span><span class="grow muted">${esc(r.passenger_name)}${r.passenger_rating ? ' · ★ ' + r.passenger_rating : ''}</span><span class="muted">${r.pickup_km != null ? '~' + r.pickup_km + ' km away' : ''}</span></div>
      <div class="col" style="gap:4px;font-size:14.5px"><span class="row"><span class="dot" style="background:#fff"></span><span class="grow">${esc(r.from_text)}</span></span><span class="row"><span class="sq"></span><span class="grow">${esc(r.to_text)}</span></span></div>
      ${r.note ? `<div class="soft small">${esc(r.note)}</div>` : ''}
      <div class="row"><b class="num" style="font-size:28px">${ZD.rs(base)}</b><span class="muted grow">offered · ${r.km} km</span></div>
      <div class="tabsbar">${ups.map(p => `<button class="btn ghost small" style="width:100%" data-act="offer" data-id="${r.id}" data-p="${p}">${ZD.rs(p)}</button>`).join('')}</div>
      <button class="btn primary" data-act="offer" data-id="${r.id}" data-p="${base}">Accept ${ZD.rs(base)}</button></div>`;
  }).join('') || (waitingIds.length ? '' : '<div class="muted" style="text-align:center;padding:16px">No requests right now. Stay online, busy areas: Saddar, University Road, Hayatabad.</div>'));
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
  const draw = () => screen(`<h2 class="title">Wallet</h2>
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
    ${nav('wallet')}`, Object.assign({
    method(b) { method = b.dataset.m; draw(); }, amt(b) { amt = +b.dataset.a; draw(); },
    async topup(b) { const tid = document.getElementById('tid').value.trim(); if (tid.length < 6) return ZD.toast('Enter the transaction ID from the SMS'); busy(b);
      try { await api('POST', '/wallet/topups', { method, tid, amount: amt }); ZD.toast('Top-up sent. It shows here once Accounts checks it.'); wallet(); } catch (e) { fail(e, b); } },
    async pass(b) { busy(b); try { await api('POST', '/wallet/pass', { kind: b.dataset.k }); ZD.toast('Pass active: 0% fee'); wallet(); } catch (e) { fail(e, b); } },
  }, navHandlers), 'full');
  draw();
}

function menu() {
  ++D.seq;
  const dv = D.me.driver;
  screen(`<h2 class="title">Account</h2>
    <div class="card row"><span class="av" style="background:var(--red);color:#fff">${esc(ZD.initials(D.me.name))}</span><span class="grow"><b>${esc(D.me.name)}</b><span class="s" style="display:block">${esc(D.me.phone)} · ★ ${(+dv.rating).toFixed(1)}</span></span></div>
    <div class="card col"><div class="row"><span class="grow muted">Vehicle</span><b>${esc(dv.vehicle_label)}</b></div><div class="row"><span class="grow muted">Number plate</span><b>${esc(dv.plate || '—')}</b></div><div class="row"><span class="grow muted">Plan</span><b>${dv.plan === 'per_trip' ? dv.commission_pct + '% per trip' : esc(dv.plan) + ' pass'}</b></div></div>
    <button class="btn ghost" data-act="out">Sign out</button>
    <div class="muted small">Map data © OpenStreetMap contributors. ZarDrive Driver test version.</div>
    ${nav('menu')}`, Object.assign({ async out() { try { await api('POST', '/driver/online', { online: 0 }); } catch (e) {} stopTracking(); ZD.store(TK, null); signIn(); } }, navHandlers), 'full');
}

boot();
