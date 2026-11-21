/* ZarDrive passenger app: sign in, choose service, where to, set your fare, pick a driver, ride, pay cash, rate. */
'use strict';

const TK = 'zd_p_token';
const api = (m, p, b) => ZD.api(m, p, b, TK);
const $sheet = document.getElementById('sheet');
const esc = ZD.esc;

const SERVICES = [
  { k: 'mini', l: 'Mini', svc: 'ride', veh: 'economy', ic: () => ZD.vehIcon('economy') },
  { k: 'moto', l: 'Moto', svc: 'ride', veh: 'bike', ic: () => ZD.vehIcon('bike') },
  { k: 'ac', l: 'Ride A/C', svc: 'ride', veh: 'comfort', ic: () => ZD.vehIcon('comfort') },
  { k: 'rick', l: 'Rickshaw', svc: 'ride', veh: 'rickshaw', ic: () => ZD.vehIcon('rickshaw') },
  { k: 'city', l: 'City to city', svc: 'intercity', veh: 'economy', ic: () => ZD.icon.city() },
  { k: 'courier', l: 'Couriers', svc: 'courier', veh: 'bike', ic: () => ZD.icon.scooter() },
  { k: 'food', l: 'Food', svc: 'food', veh: 'bike', ic: () => ZD.icon.bowl('#E9C46A') },
  { k: 'freight', l: 'Freight', svc: 'freight', veh: 'loader', ic: () => ZD.vehIcon('loader') },
];
const VEH_NOTE = { bike: 'No traffic, lower prices', rickshaw: 'Short trips, cheap', economy: 'Lower fares, no AC', comfort: 'Cars with AC', loader: 'Loaders and pickups' };
const SEATS = { bike: 1, rickshaw: 3, economy: 4, comfort: 4, loader: 0 };
const VLABEL = { economy: 'Mini', bike: 'Moto', comfort: 'Ride A/C', rickshaw: 'Rickshaw', loader: 'Freight' };
const vname = (v) => VLABEL[v] || (P.cfg && P.cfg.vehicles[v]) || v;
const seatTxt = (v) => SEATS[v] ? `<svg class="seat" width="13" height="13" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="7" r="4" fill="currentColor"/><path d="M4 21c0-4.5 3.6-7 8-7s8 2.5 8 7z" fill="currentColor"/></svg> ${SEATS[v]}` : 'Goods';

const P = { cfg: null, me: null, s: SERVICES[0], from: null, to: null, route: null, guides: {}, offer: {}, veh: null, note: '', trip: null, field: 'to', tags: [] };

// ---------- screen plumbing
let handlers = {};
function screen(html, on, mode, keepTimers) {
  if (!keepTimers) ZD.clearTimers();
  document.body.classList.toggle('full', mode === 'full');
  document.body.classList.toggle('tall', mode === 'tall');
  document.body.classList.toggle('home', mode === 'home');
  document.body.classList.toggle('farem', mode === 'fare');
  document.querySelectorAll('.map-ov').forEach(n => n.remove());
  $sheet.innerHTML = html;
  $sheet.scrollTop = 0;
  handlers = on || {};
  ZD.refreshMap();
}
$sheet.addEventListener('click', e => { const b = e.target.closest('[data-act]'); if (b && handlers[b.dataset.act]) handlers[b.dataset.act](b, e); });
function overlay(html, cls) { const d = document.createElement('div'); d.className = 'map-ov ' + (cls || 'map-top'); d.innerHTML = html; document.body.appendChild(d);
  d.addEventListener('click', e => { const b = e.target.closest('[data-act]'); if (b && handlers[b.dataset.act]) handlers[b.dataset.act](b, e); }); return d; }
function busy(btn, text) { if (btn) { btn.disabled = true; btn.dataset.t = btn.textContent; btn.textContent = text || 'Please wait…'; } }
function unbusy(btn) { if (btn) { btn.disabled = false; btn.textContent = btn.dataset.t || btn.textContent; } }
function fail(e, btn) { unbusy(btn); if (e.status === 401) { ZD.store(TK, null); return signIn(); } ZD.toast(e.message); }

// ---------- start
async function boot() {
  try { P.cfg = await api('GET', '/config'); } catch (e) { P.cfg = null; }
  await ZD.initMap(P.cfg && P.cfg.maps);
  if (!ZD.store(TK)) return signIn();
  try {
    P.me = await api('GET', '/me');
    if (P.me.role !== 'passenger') { ZD.store(TK, null); return signIn(); }
    const a = (await api('GET', '/my/active')).trip;
    if (a) return resume(a);
    home();
  } catch (e) { fail(e); }
}
function resume(t) {
  P.trip = t;
  if (t.status === 'searching') return searching();
  if (t.status === 'completed') return done();
  return onTrip();
}

// ---------- sign in
function signIn(step, phone, code, err) {
  step = step || 'phone';
  const head = `<div class="row" style="gap:10px;margin-top:6px"><div style="width:40px;height:40px;border-radius:12px;background:var(--red);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Space Grotesk',Inter,sans-serif;font-weight:700;font-size:20px">Z</div><b style="font-family:'Space Grotesk',Inter,sans-serif;font-size:22px">ZarDrive</b></div>
    <h1 class="title" style="font-size:28px;line-height:1.15">Name your price.<br>Ride across Peshawar.</h1>
    <div class="muted" style="font-size:14px">Rides, food delivery, parcels, intercity and loaders in one app.</div>`;
  let body = '';
  if (step === 'phone') body = `<label class="field"><span>Mobile number</span><div class="phone"><b>+92</b><input id="ph" inputmode="tel" autocomplete="tel" placeholder="300 1234567" value="${esc(phone || '')}"></div></label>
    <div class="muted">We send a 4-digit code by SMS. No password to remember.</div>
    <button class="btn primary" data-act="send">Send code</button>`;
  if (step === 'code') body = `<label class="field"><span>Code sent to ${esc(phone)}</span><input id="code" class="input" inputmode="numeric" maxlength="4" autocomplete="one-time-code" style="font-size:24px;letter-spacing:10px;text-align:center"></label>
    ${P.debugCode ? `<div class="warn-note">Test site: your code is <b>${esc(P.debugCode)}</b> (SMS is not connected yet).</div>` : ''}
    <button class="btn primary" data-act="verify">Verify</button><button class="btn ghost" data-act="back">Change number</button>`;
  if (step === 'name') body = `<label class="field"><span>What should drivers call you?</span><input id="nm" class="input" autocomplete="given-name" placeholder="Your name"></label>
    <div class="soft small">Drivers see only your first name. Your number stays hidden.</div>
    <button class="btn primary" data-act="name">Start riding</button>`;
  screen(`${head}${err ? `<div class="note">${esc(err)}</div>` : ''}${body}<div class="muted small" style="text-align:center">By continuing you agree to the Terms and Privacy policy.</div>`, {
    async send(b) { const ph = document.getElementById('ph').value; busy(b, 'Sending…');
      try { const r = await api('POST', '/auth/otp', { phone: ph }); P.debugCode = r.debug_code; signIn('code', ph); } catch (e) { fail(e, b); } },
    back() { signIn('phone', phone); },
    async verify(b) { const c = document.getElementById('code').value.trim(); busy(b, 'Checking…');
      try { await finishSignIn(phone, c, ''); } catch (e) { if (e.message === 'Enter your name') return signIn('name', phone, c); fail(e, b); } },
    async name(b) { const n = document.getElementById('nm').value.trim(); if (!n) return ZD.toast('Enter your name'); busy(b);
      try { await finishSignIn(phone, code, n); } catch (e) { fail(e, b); } },
  }, 'full');
  setTimeout(() => { const i = document.querySelector('#sheet input'); if (i) i.focus(); }, 50);
}
async function finishSignIn(phone, code, name) {
  const r = await api('POST', '/auth/verify', { phone, code, role: 'passenger', name });
  ZD.store(TK, r.token); P.debugCode = null;
  P.me = await api('GET', '/me');
  home();
}

// ---------- home
async function home() {
  P.to = null; P.route = null; P.trip = null; P.note = '';
  const svcBtns = SERVICES.map((s, i) => `<button class="svc" data-act="svc" data-i="${i}" aria-pressed="${s.k === P.s.k}">${s.ic()}<span>${esc(s.l)}</span></button>`).join('');
  const prompt = P.s.svc === 'food' ? 'Which restaurant & drop?' : P.s.svc === 'courier' ? 'Send to & for how much?' : 'Where to & for how much?';
  screen(`<div class="chips" role="tablist" aria-label="Services">${svcBtns}</div>
    <button class="search-btn" data-act="where">${ZD.icon.search}<span>${prompt}</span></button>
    <div class="list" id="recent"></div>`, {
    svc(b) { P.s = SERVICES[+b.dataset.i]; home(); },
    where() { whereTo(P.s.svc === 'food' ? 'from' : 'to'); },
    recent(b) { const r = P.recent[+b.dataset.i]; P.to = { name: r.to_text, lat: +r.to_lat, lng: +r.to_lng }; whereTo('to'); },
    menu() { menu(); },
    pickup() { whereTo('from'); },
    locate() { locateMe(true); },
  }, 'home');
  overlay(`<button class="icon-btn big" data-act="menu" aria-label="Menu">${ZD.icon.menu}</button>`);
  overlay(`<button class="from-card" data-act="pickup"><span class="grow"><span class="muted small" style="display:block">Where from</span><span class="ell" style="display:block;font-weight:600;font-size:16px" id="pickName">${esc(P.from ? P.from.name : 'Finding your location…')}</span></span>${I.chev}</button>`, 'from-bubble');
  overlay(`<button class="icon-btn big" data-act="locate" aria-label="My location">${ZD.icon.locate}</button>`, 'locate-fab');
  ZD.clearMap();
  if (P.from) { ZD.pin(P.from.lat, P.from.lng, 'pickup'); ZD.center(P.from.lat, P.from.lng, 16); } else locateMe(false);
  try {
    const t = (await api('GET', '/my/trips')).trips || [];
    const seen = new Set(); P.recent = t.filter(x => x.to_lat && !seen.has(x.to_text) && seen.add(x.to_text)).slice(0, 4);
    const box = document.getElementById('recent');
    if (box) box.innerHTML = P.recent.map((r, i) => `<button class="li plain" data-act="recent" data-i="${i}"><span class="ic">${ZD.icon.pinO}</span><span class="t grow ell">${esc(r.to_text)}</span></button>`).join('')
      || `<div class="muted" style="padding:6px 2px">Your recent places will show here.</div>`;
  } catch (e) { if (e.status === 401) fail(e); }
}
async function locateMe(force) {
  const pos = await ZD.here();
  if (!pos) { if (force) ZD.toast('Turn on location, or tap the pick-up box to search for it.'); setPickName('Tap to choose pick-up'); return; }
  let name = 'Current location';
  try { const r = (await api('GET', `/places/reverse?lat=${pos.lat}&lng=${pos.lng}`)).place; if (r) name = r.name; } catch (e) { /* keep default */ }
  P.from = { name, lat: pos.lat, lng: pos.lng };
  setPickName(name);
  ZD.clearMap(); ZD.pin(pos.lat, pos.lng, 'pickup'); ZD.center(pos.lat, pos.lng, 16);
}
function setPickName(n) { const el = document.getElementById('pickName'); if (el) el.textContent = n; }

// ---------- where to (own map search)
function whereTo(field) {
  P.field = field || 'to';
  const food = P.s.svc === 'food';
  screen(`<div class="row"><button class="icon-btn" style="box-shadow:none" data-act="back" aria-label="Back">${ZD.icon.back}</button><h2 class="title grow">${food ? 'Food delivery' : esc(P.s.l)}</h2></div>
    <div class="route">
      <div class="stop ${P.field === 'from' ? 'active' : ''}"><span class="dot"></span><input id="fFrom" placeholder="${food ? 'Restaurant to pick up from' : 'Pick-up'}" value="${esc(P.from ? P.from.name : '')}" data-f="from"></div>
      <div class="stop ${P.field === 'to' ? 'active' : ''}"><span class="sq"></span><input id="fTo" placeholder="${food ? 'Deliver to' : 'Where to?'}" value="${esc(P.to ? P.to.name : '')}" data-f="to"></div>
    </div>
    <button class="li" data-act="mine" style="border:0"><span class="ic" style="color:var(--red)">${ZD.icon.locate}</span><span class="t" style="font-weight:600;color:var(--red)">Use my current location for ${P.field === 'from' ? 'pick-up' : 'drop'}</span></button>
    <div class="list" id="results"></div>
    <div class="muted small">Places come from ZarDrive's own map of Pakistan (© OpenStreetMap contributors). Can't find it? Pick a nearby place and add a note for the driver.</div>`, {
    back() { home(); },
    async mine() { const pos = await ZD.here(); if (!pos) return ZD.toast('Location is off'); let name = 'Current location';
      try { const r = (await api('GET', `/places/reverse?lat=${pos.lat}&lng=${pos.lng}`)).place; if (r) name = r.name; } catch (e) {}
      choose({ name, lat: pos.lat, lng: pos.lng }); },
    pick(b) { const r = P.results[+b.dataset.i]; choose({ name: r.name, lat: r.lat, lng: r.lng }); },
  }, 'full');
  const inputs = [document.getElementById('fFrom'), document.getElementById('fTo')];
  let t;
  inputs.forEach(inp => {
    inp.addEventListener('focus', () => { P.field = inp.dataset.f; document.querySelectorAll('.route .stop').forEach((s, i) => s.classList.toggle('active', i === (P.field === 'from' ? 0 : 1))); inp.select(); });
    inp.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => search(inp.value), 220); });
  });
  const cur = P.field === 'from' ? inputs[0] : inputs[1];
  setTimeout(() => cur.focus(), 60);
  if (cur.value) search(cur.value);
}
async function search(q) {
  const box = document.getElementById('results'); if (!box) return;
  if (q.trim().length < 2) { box.innerHTML = ''; return; }
  const near = P.from || { lat: 34.0086, lng: 71.5249 };
  try {
    P.results = (await api('GET', `/places/search?q=${encodeURIComponent(q)}&lat=${near.lat}&lng=${near.lng}`)).places;
    const IC = { hospital: 'HSP', clinic: 'HSP', road: 'RD', bus_station: 'BUS', bus_stop: 'BUS', railway_station: 'RLY', area: 'AREA', city: 'CITY', restaurant: 'FOOD', fast_food: 'FOOD', cafe: 'FOOD', bakery: 'FOOD', mosque: 'MSJ', school: 'SCH', university: 'UNI', college: 'UNI', bank: 'BANK', market: 'MKT', marketplace: 'MKT', mall: 'MALL', hotel: 'HTL', park: 'PARK' };
    box.innerHTML = P.results.map((r, i) => `<button class="li" data-act="pick" data-i="${i}"><span class="ic">${IC[r.kind] || 'PIN'}</span><span class="grow" style="min-width:0"><span class="t ell" style="display:block">${esc(r.name)}</span><span class="s ell" style="display:block">${esc(r.label || r.kind)}</span></span>${r.km != null ? `<span class="s">${r.km} km</span>` : ''}</button>`).join('')
      || `<div class="muted" style="padding:8px 2px">No place found. Try another spelling, or a nearby landmark.</div>`;
  } catch (e) { if (e.status === 401) fail(e); }
}
function choose(place) {
  if (P.field === 'from') { P.from = place; if (!P.to) return whereTo('to'); }
  else P.to = place;
  if (P.from && P.to) fare(); else whereTo(P.from ? 'to' : 'from');
}

// ---------- choose vehicle and fare
const VORDER = ['economy', 'bike', 'comfort', 'rickshaw', 'loader'];
function vehiclesFor(svc) { return vehiclesFor0(svc).slice().sort((x, y) => VORDER.indexOf(x) - VORDER.indexOf(y)); }
function vehiclesFor0(svc) { const s = (P.cfg && P.cfg.serves && P.cfg.serves[svc]) || ({ ride: ['bike', 'rickshaw', 'economy', 'comfort'], food: ['bike'], courier: ['bike', 'rickshaw'], intercity: ['economy', 'comfort'], freight: ['loader'] })[svc]; return s; }
async function fare() {
  const svc = P.s.svc; const vehs = vehiclesFor(svc);
  if (!P.veh || !vehs.includes(P.veh)) P.veh = vehs.includes(P.s.veh) ? P.s.veh : vehs[0];
  screen(`<div class="pulse"><i></i></div><div class="muted" style="text-align:center">Working out the route…</div>`, {}, 'fare');
  try {
    P.route = await api('POST', '/route', { from_lat: P.from.lat, from_lng: P.from.lng, to_lat: P.to.lat, to_lng: P.to.lng, vehicle: P.veh });
    await Promise.all(vehs.map(async v => { P.guides[v] = await api('POST', '/fare-guide', { service: svc, vehicle: v, km: P.route.km }); if (!P.offer[v + svc]) P.offer[v + svc] = P.guides[v].suggested; }));
  } catch (e) { return fail(e); }
  drawRoute();
  renderFare();
}
function drawRoute() {
  ZD.clearMap();
  const pts = ZD.decode(P.route.geometry || '');
  const a = [P.from.lat, P.from.lng], b = [P.to.lat, P.to.lng];
  ZD.line(pts.length > 1 ? pts : [a, b]); ZD.pin(a[0], a[1], 'pickup'); ZD.pin(b[0], b[1], 'flag'); ZD.fit([a, b].concat(pts));
}
function renderFare() {
  const svc = P.s.svc; const vehs = vehiclesFor(svc);
  const showNote = svc === 'food' || svc === 'courier' || P.showNote || P.note;
  const extra = !showNote ? '' : svc === 'food'
    ? `<label class="field"><span>What to pick up</span><textarea id="note" class="input" maxlength="180" placeholder="e.g. 1 chicken karahi, 4 naan. Extra raita, no onions">${esc(P.note)}</textarea></label>
       <div class="note">The rider pays the restaurant. You pay the rider the food bill at your door, plus the delivery charge. Ask for the restaurant receipt.</div>`
    : svc === 'courier' ? `<label class="field"><span>What are you sending?</span><input id="note" class="input" maxlength="180" placeholder="e.g. documents, 2 kg box" value="${esc(P.note)}"></label>`
    : `<label class="field"><span>Comment for the driver</span><input id="note" class="input" maxlength="180" placeholder="e.g. gate 2, call on arrival" value="${esc(P.note)}"></label>`;
  const val = () => P.offer[P.veh + svc];
  const list = vehs.map(v => {
    const g = P.guides[v]; const on = v === P.veh; const x = P.offer[v + svc];
    const hint = x < g.suggested ? 'Below recommended: fewer drivers may answer' : x > g.suggested ? 'Above recommended: faster pick-up' : 'Recommended fare';
    const head = `${ZD.vehIcon(v)}<span class="grow"><b style="display:block;font-size:17px">${esc(vname(v))}${on ? ' <span class="info" aria-hidden="true">i</span>' : ''}</b><span class="s" style="display:block;color:var(--ink-2)">${seatTxt(v)}${svc === 'food' ? ' · food rates' : ''}</span><span class="s" style="display:block">${VEH_NOTE[v]}</span></span>`;
    if (!on) return `<button class="veh" data-act="veh" data-v="${v}" aria-pressed="false">${head}<b class="price-r">~${ZD.rs(g.suggested)}</b></button>`;
    return `<div class="veh-wrap on"><div class="veh">${head}<button class="pen" data-act="pen" aria-label="Type your fare">${I.pen}</button></div>
      ${P.typing ? `<div class="fare"><input id="fareIn" class="input grow" inputmode="numeric" value="${x}" aria-label="Your fare in rupees" style="text-align:center;font-size:24px;font-weight:700"><button class="btn dark small" data-act="setfare">Done</button></div>`
        : `<div class="fare"><button class="round" data-act="minus" aria-label="Lower by Rs 10">−</button><span class="grow" style="text-align:center"><span class="big" style="display:block;font-size:30px">${ZD.rs(x)}</span><span class="s">${hint}</span></span><button class="round" data-act="plus" aria-label="Raise by Rs 10">+</button></div>`}</div>`;
  }).join('');
  screen(`${list}${extra}
    <div class="sticky col" style="gap:10px">
      <label class="row auto-row"><span class="auto-ic">${I.plane}</span><span class="grow" style="font-size:16px">Auto-accept offer of ${ZD.rs(val())}</span><input type="checkbox" class="switch" id="auto" ${P.auto ? 'checked' : ''}></label>
      <div class="row"><span class="cash-ic" title="Cash"><svg width="30" height="20" viewBox="0 0 20 14" aria-label="Cash"><rect width="20" height="14" rx="2" fill="#2E9E5B"/><circle cx="10" cy="7" r="3" fill="#E7F6EE"/></svg></span>
      <button class="btn primary grow" data-act="find">Find drivers</button>
      <button class="icon-plain" data-act="opts" aria-label="Comment for the driver">${I.sliders}</button></div>
    </div>`, {
    veh(b) { keepNote(); P.veh = b.dataset.v; P.typing = false; renderFare(); },
    minus() { keepNote(); const k = P.veh + svc; const lo = Math.ceil(P.guides[P.veh].min_offer / 10) * 10; if (P.offer[k] - 10 < lo) ZD.toast('Lowest you can offer: ' + ZD.rs(lo)); P.offer[k] = Math.max(lo, P.offer[k] - 10); renderFare(); },
    plus() { keepNote(); const k = P.veh + svc; P.offer[k] = P.offer[k] + 10; renderFare(); },
    pen() { keepNote(); P.typing = true; renderFare(); setTimeout(() => { const i = document.getElementById('fareIn'); if (i) { i.focus(); i.select(); } }, 50); },
    setfare() { keepNote(); const n = Math.round(+document.getElementById('fareIn').value || 0); const lo = Math.ceil(P.guides[P.veh].min_offer); if (n < lo) return ZD.toast('Lowest you can offer: ' + ZD.rs(lo)); P.offer[P.veh + svc] = n; P.typing = false; renderFare(); },
    opts() { keepNote(); P.showNote = true; renderFare(); setTimeout(() => { const n = document.getElementById('note'); if (n) { n.scrollIntoView({ block: 'center' }); n.focus(); } }, 50); },
    edit() { keepNote(); whereTo('to'); },
    back() { keepNote(); home(); },
    async find(b) { keepNote(); if (svc === 'food' && !P.note.trim()) return ZD.toast('Write what the rider should pick up');
      busy(b, 'Sending your request…');
      try { P.trip = await api('POST', '/trips', { service: svc, vehicle: P.veh, from: P.from.name, to: P.to.name, from_lat: P.from.lat, from_lng: P.from.lng, to_lat: P.to.lat, to_lng: P.to.lng, offer: val(), note: P.note, auto_accept: P.auto ? 1 : 0 });
        searching(); } catch (e) { fail(e, b); } },
  }, 'fare');
  document.getElementById('auto').addEventListener('change', e => { P.auto = e.target.checked; });
  const sel = document.querySelector('.veh-wrap.on'); if (sel && sel.offsetTop > 120) $sheet.scrollTop = sel.offsetTop - 16;
  overlay(`<div class="route-card grow"><button class="rc-row" data-act="back" aria-label="Change pick-up"><span class="rc-ic">${I.person}</span><span class="grow ell">${esc(P.from.name)}</span></button>
    <div class="rc-row"><span class="rc-ic">${I.flag}</span><span class="grow" style="min-width:0"><span class="ell" style="display:block">${esc(P.to.name)}</span><span style="display:block">~${P.route.minutes} min.${P.route.source === 'estimate' ? ' (estimate)' : ''}</span></span><button class="plus-btn" data-act="edit" aria-label="Change drop-off">+</button></div></div>`);
  overlay(`<button class="icon-btn big" data-act="back" aria-label="Back">${ZD.icon.back}</button>`, 'back-fab');
}
function keepNote() { const n = document.getElementById('note'); if (n) P.note = n.value; }

// ---------- waiting for drivers
function searching() {
  const t = P.trip; const seen = {};
  let started = Date.now(), cancelArm = false;
  screen(`<div class="pulse"><i></i></div>
    <div style="text-align:center"><h2 class="title">Finding drivers</h2><div class="muted">Your offer <b style="color:var(--ink)">${ZD.rs(t.offer)}</b> · ${esc(vname(t.vehicle))} · cash</div>${t.auto_accept ? `<div class="ok-note" style="margin-top:8px">Auto-accept is on: the first driver who takes ${ZD.rs(t.offer)} gets the trip.</div>` : ''}</div>
    <div class="col" id="offers"></div>
    <div class="muted small" id="hint" style="text-align:center">Drivers nearby can accept your price or offer theirs. You choose.</div>
    <div class="row"><button class="btn ghost" data-act="raise">Raise to ${ZD.rs(Math.ceil(t.offer * 1.1 / 10) * 10)}</button><button class="btn danger" data-act="cancel">Cancel</button></div>`, {
    async accept(b) { busy(b, 'Confirming…'); try { P.trip = await api('POST', `/offers/${b.dataset.id}/accept`); onTrip(); } catch (e) { fail(e, b); } },
    async cancel(b) { if (!cancelArm) { cancelArm = true; b.textContent = 'Tap again to cancel'; return; } busy(b);
      try { await api('POST', `/trips/${t.id}/cancel`, { reason: 'changed my mind while searching' }); ZD.toast('Request cancelled'); home(); } catch (e) { fail(e, b); } },
    async raise(b) { busy(b); const newOffer = Math.ceil(t.offer * 1.1 / 10) * 10;
      try { await api('POST', `/trips/${t.id}/cancel`, { reason: 'raised fare' });
        P.trip = await api('POST', '/trips', { service: t.service, vehicle: t.vehicle, from: t.from_text, to: t.to_text, from_lat: t.from_lat, from_lng: t.from_lng, to_lat: t.to_lat, to_lng: t.to_lng, offer: newOffer, note: t.note || '' });
        P.offer[t.vehicle + t.service] = newOffer; searching(); } catch (e) { fail(e, b); } },
  }, 'tall');
  if (t.from_lat) { ZD.clearMap(); ZD.pin(+t.from_lat, +t.from_lng, 'from'); if (t.to_lat) ZD.pin(+t.to_lat, +t.to_lng, 'to'); ZD.center(+t.from_lat, +t.from_lng, 14); }
  ZD.every(2500, async () => {
    try {
      const v = await api('GET', `/trips/${t.id}`);
      if (v.status !== 'searching') { P.trip = v; return v.status === 'cancelled' ? (ZD.toast('Request ended'), home()) : onTrip(); }
      const offers = (await api('GET', `/trips/${t.id}/offers`)).offers;
      const box = document.getElementById('offers'); if (!box) return;
      offers.forEach(o => { if (!seen[o.id]) seen[o.id] = Date.now(); });
      box.innerHTML = offers.map(o => {
        const left = Math.max(0, 30 - (Date.now() - seen[o.id]) / 1000);
        const counter = +o.price > +t.offer;
        return `<div class="offer"><div class="row"><span class="av">${esc(ZD.initials(o.name))}</span><span class="grow"><b>${esc(o.name)}</b> <span class="s">★ ${(+o.rating).toFixed(1)}</span><span class="s" style="display:block">${esc(vname(o.vehicle))} · ${esc(o.plate || '')}</span></span>
          <span style="text-align:right"><b class="num" style="font-size:20px">${ZD.rs(o.price)}</b><span class="s" style="display:block">${counter ? 'Counter offer' : 'Your price'}</span></span></div>
          <div class="timer"><i style="width:${left / 30 * 100}%"></i></div>
          <button class="btn primary small" style="width:100%" data-act="accept" data-id="${o.id}">Accept ${ZD.rs(o.price)}</button></div>`;
      }).join('');
      const hint = document.getElementById('hint');
      if (hint && !offers.length && Date.now() - started > 45000) hint.textContent = 'No answers yet. Raising your fare a little often helps.';
    } catch (e) { if (e.status === 401) fail(e); }
  });
}

// ---------- driver on the way / on the trip
function onTrip() {
  let cancelArm = false;
  const render = (t) => {
    const d = t.driver || {};
    const title = { accepted: `${esc(d.name || 'Your driver')} is on the way`, arrived: 'Your driver has arrived', started: 'On your way' }[t.status] || 'Trip';
    screen(`<h2 class="title">${title}</h2>
      ${t.status === 'arrived' ? '<div class="ok-note">Meet your driver at the pick-up point. Check the number plate first.</div>' : ''}
      <div class="row"><span class="av" style="width:52px;height:52px;border-radius:26px">${esc(ZD.initials(d.name))}</span>
        <span class="grow"><b>${esc(d.name || '')}</b> <span class="s">★ ${(+d.rating || 5).toFixed(1)}</span><span class="s" style="display:block">${esc(d.vehicle_label || '')}</span></span><span class="plate">${esc(d.plate || '')}</span></div>
      <div class="soft row"><span class="grow"><span class="s" style="display:block">${esc(t.from_text)} → ${esc(t.to_text)}</span><span class="small">Agreed fare · cash</span></span><b class="num" style="font-size:24px">${ZD.rs(t.fare)}</b></div>
      ${t.note ? `<div class="soft small"><b>Note:</b> ${esc(t.note)}</div>` : ''}
      <div class="tabsbar"><button class="btn ghost small" style="width:100%" data-act="share">Share trip</button><a class="btn ghost small" style="width:100%;color:#912018" href="tel:15">SOS · 15</a>
      ${t.status !== 'started' ? '<button class="btn danger small" style="width:100%" data-act="cancel">Cancel</button>' : '<span></span>'}</div>
      <div class="muted small">Pay the driver in cash at the end. Free to cancel for 2 minutes after the driver accepts.</div>`, {
      share() { const txt = `I'm on a ZarDrive trip: ${t.from_text} → ${t.to_text}. Driver ${d.name}, ${d.vehicle_label}, ${d.plate}.`;
        if (navigator.share) navigator.share({ title: 'My ZarDrive trip', text: txt }).catch(() => {}); else { navigator.clipboard && navigator.clipboard.writeText(txt); ZD.toast('Trip details copied'); } },
      async cancel(b) { if (!cancelArm) { cancelArm = true; b.textContent = 'Tap again'; return; } busy(b);
        try { await api('POST', `/trips/${t.id}/cancel`, { reason: 'passenger cancelled' }); ZD.toast('Trip cancelled'); home(); } catch (e) { fail(e, b); } },
    }, 'tall', true);
    ZD.clearMap();
    const pts = [];
    if (t.from_lat) { ZD.pin(+t.from_lat, +t.from_lng, 'from'); pts.push([+t.from_lat, +t.from_lng]); }
    if (t.to_lat && t.status === 'started') { ZD.pin(+t.to_lat, +t.to_lng, 'to'); pts.push([+t.to_lat, +t.to_lng]); }
    if (d.lat != null) { ZD.pin(d.lat, d.lng, 'car'); pts.push([d.lat, d.lng]); }
    if (pts.length) ZD.fit(pts);
  };
  let last = '';
  ZD.clearTimers();
  render(P.trip);
  ZD.every(4000, async () => {
    try {
      const t = await api('GET', `/trips/${P.trip.id}`); P.trip = t;
      if (t.status === 'completed') return done();
      if (t.status === 'cancelled') { ZD.toast('The trip was cancelled' + (String(t.cancel_reason).startsWith('driver') ? ' by the driver' : '')); return home(); }
      const key = t.status + (t.driver ? t.driver.lat + ',' + t.driver.lng : '');
      if (key !== last) { last = key; if (!cancelArm) render(t); }
    } catch (e) { if (e.status === 401) fail(e); }
  });
}

// ---------- finished: pay and rate
function done() {
  const t = P.trip; let stars = 5; P.tags = [];
  const TAGS = ['Safe driving', 'On time', 'Clean car', 'Polite'];
  const draw = () => screen(`<div style="text-align:center" class="col"><div style="width:56px;height:56px;border-radius:28px;background:var(--good-soft);display:flex;align-items:center;justify-content:center;align-self:center"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#0E6B47" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg></div>
      <h2 class="title">You've arrived</h2><div class="muted">Pay ${esc(t.driver ? t.driver.name : 'the driver')} in cash</div><div class="big">${ZD.rs(t.fare)}</div></div>
    <div class="card col"><div class="row small"><span class="grow muted">Trip ZD-${t.id}</span><span>${t.km} km</span></div><div class="row small"><span class="grow muted">${esc(t.from_text)} → ${esc(t.to_text)}</span></div></div>
    <div style="text-align:center;font-weight:600">How was your trip?</div>
    <div class="stars" role="radiogroup" aria-label="Rating">${[1, 2, 3, 4, 5].map(n => `<button role="radio" aria-checked="${n === stars}" aria-label="${n} stars" data-act="star" data-n="${n}">${ZD.icon.star(n <= stars)}</button>`).join('')}</div>
    <div class="chips" style="justify-content:center;flex-wrap:wrap">${TAGS.map(x => `<button class="chip" data-act="tag" aria-pressed="${P.tags.includes(x)}">${x}</button>`).join('')}</div>
    <input id="rnote" class="input" placeholder="Anything else? (optional)" maxlength="150">
    <button class="btn primary" data-act="send">Submit and finish</button>`, {
    star(b) { stars = +b.dataset.n; draw(); },
    tag(b) { const x = b.textContent; P.tags = P.tags.includes(x) ? P.tags.filter(y => y !== x) : P.tags.concat(x); draw(); },
    async send(b) { busy(b); const note = [P.tags.join(', '), document.getElementById('rnote').value.trim()].filter(Boolean).join(' · ');
      try { await api('POST', `/trips/${t.id}/rate`, { stars, note }); ZD.toast('Thank you!'); home(); } catch (e) { if (e.message === 'Already rated') return home(); fail(e, b); } },
  }, 'full');
  draw();
}

// ---------- account, history, trip detail, help
function header(title) { return `<div class="row"><button class="icon-btn" style="box-shadow:none" data-act="back" aria-label="Back">${ZD.icon.back}</button><h2 class="title grow">${title}</h2></div>`; }
const ROUND = (act, label, svg) => `<button class="round-tile" data-act="${act}"><span>${svg}</span>${label}</button>`;
const I = {
  pen: '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16zM16 4l4 4 1-1a2 2 0 0 0 0-3 2 2 0 0 0-3 0z"/></svg>',
  plane: '<svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M22 2L2 10l7 3 3 7z"/><path d="M3 21l4-4M2 17l3-3M7 22l3-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
  sliders: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/></svg>',
  person: '<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="5" r="3" fill="#111418"/><path d="M4 21l2-8 4-3 5 2 3 4-2 1-3-3-2 2 1 5z" fill="#111418"/></svg>',
  flag: '<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3v18" stroke="#111418" stroke-width="2.4" stroke-linecap="round"/><path d="M6 4h13l-3 5 3 5H6z" fill="#111418"/></svg>',
  clock: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
  help: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="13" width="4" height="6" rx="1.5"/><rect x="17" y="13" width="4" height="6" rx="1.5"/></svg>',
  pin: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>',
  cog: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/></svg>',
  chev: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6B7680" stroke-width="2.2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg>',
  cash: '<svg width="30" height="20" viewBox="0 0 30 20"><rect width="30" height="20" rx="3" fill="#0E6B47"/><circle cx="15" cy="10" r="4.5" fill="#E7F6EE"/></svg>',
};
function row(act, title, sub, right, color) { return `<button class="li" data-act="${act}"><span class="grow" style="min-width:0"><span class="t" style="display:block;${color ? 'color:' + color + ';font-weight:600' : ''}">${title}</span>${sub ? `<span class="s" style="display:block">${sub}</span>` : ''}</span>${right || ''}${I.chev}</button>`; }

async function menu() {
  try { P.me = await api('GET', '/me'); } catch (e) { return fail(e); }
  const st = P.me.stats || { trips: 0, recent: 0, cancelled_by_you: 0 };
  const good = st.cancelled_by_you <= 1;
  screen(`${header('')}
    <div class="col" style="align-items:center;gap:6px;margin-top:-34px">
      <span class="av" style="width:84px;height:84px;border-radius:42px;font-size:30px;background:var(--red);color:#fff">${esc(ZD.initials(P.me.name))}</span>
      <h2 class="title" style="font-size:24px">${esc(P.me.name)}</h2>
      <span class="muted">${esc(P.me.phone)}${st.rating ? ' · ★ ' + st.rating : ''} · ${st.trips} trip${st.trips === 1 ? '' : 's'}</span>
    </div>
    <div class="tiles">${ROUND('trips', 'Trips', I.clock)}${ROUND('help', 'Support', I.help)}${ROUND('places', 'Places', I.pin)}${ROUND('settings', 'Settings', I.cog)}</div>
    <div class="group">${row('pay', 'Payment methods', 'Cash', `<span style="margin-right:6px">${I.cash}</span>`)}</div>
    <div class="group">
      <a class="li" href="/driver" style="text-decoration:none"><span class="grow"><span class="t" style="display:block">Earn as a driver</span><span class="s">Set your price and keep 94%</span></span>${I.chev}</a>
      <button class="li" data-act="cancels"><span class="ring ${good ? 'ok' : 'bad'}"></span><span class="grow"><span class="t" style="display:block">${good ? 'Great! Few cancelled rides' : 'Many cancelled rides'}</span><span class="s">${st.cancelled_by_you} of your last ${st.recent} trips cancelled by you · this affects how fast drivers answer</span></span>${I.chev}</button>
    </div>
    <div class="group">${row('info', 'Information', 'About ZarDrive, map data')}</div>`, {
    back() { home(); }, trips() { trips(); }, help() { help(); }, places() { places(); }, settings() { settings(); },
    pay() { ZD.toast('Cash only for now. JazzCash and Easypaisa are coming.'); },
    cancels() { ZD.toast('Cancelling after a driver accepts makes drivers slower to answer you next time.'); },
    info() { info(); },
  }, 'full');
}

async function trips() {
  screen(`${header('Trips')}<div class="list" id="tl"><div class="muted">Loading…</div></div>`, { back() { menu(); }, open(b) { tripDetail(+b.dataset.id); } }, 'full');
  try {
    const t = (await api('GET', '/my/trips')).trips;
    const pill = s => ({ completed: ['var(--good-soft)', 'var(--good)', 'Completed'], cancelled: ['#FDECEC', '#912018', 'Cancelled'] }[s] || ['var(--warn-soft)', 'var(--warn)', 'In progress']);
    document.getElementById('tl').innerHTML = t.map(x => { const [bg, fg, l] = pill(x.status);
      return `<button class="li" data-act="open" data-id="${x.id}"><span class="veh-ic">${ZD.vehIcon(x.vehicle)}</span><span class="grow" style="min-width:0"><span class="t ell" style="display:block">${esc(x.to_text)}</span><span class="s ell" style="display:block">${esc(ZD.when(x.created_at))} · from ${esc(x.from_text)}</span></span>
        <span style="text-align:right;flex:none"><b>${x.fare ? ZD.rs(x.fare) : '—'}</b><span style="display:block;font-size:11px;font-weight:600;padding:1px 6px;border-radius:6px;background:${bg};color:${fg}">${esc(l)}</span></span></button>`; }).join('')
      || '<div class="muted" style="padding:10px 2px">No trips yet. Your rides, deliveries and parcels will show here.</div>';
  } catch (e) { fail(e); }
}

async function tripDetail(id) {
  let t; try { t = await api('GET', `/trips/${id}`); } catch (e) { return fail(e); }
  const d = t.driver || {};
  const time = new Date(String(t.created_at).replace(' ', 'T'));
  const hhmm = isNaN(time) ? '' : time.toLocaleTimeString('en-PK', { hour: '2-digit', minute: '2-digit', hour12: false });
  const day = isNaN(time) ? '' : time.toLocaleDateString('en-PK', { day: 'numeric', month: 'long', year: 'numeric' });
  let removeArm = false;
  screen(`<div class="row"><button class="icon-btn" data-act="back" aria-label="Back">${ZD.icon.back}</button><span class="grow"></span></div>
    <div class="col" style="align-items:center;gap:4px;margin-top:-6px">
      <div class="row" style="gap:0"><span class="hero-veh">${ZD.vehIcon(t.vehicle)}</span>${t.driver ? `<span class="av" style="width:64px;height:64px;border-radius:20px;font-size:22px;margin-left:-14px;border:3px solid #fff;box-shadow:0 2px 8px rgba(17,20,24,.15)">${esc(ZD.initials(d.name))}</span>` : ''}</div>
      <h2 class="title" style="font-size:24px;text-align:center">${esc(t.service === 'ride' ? t.vehicle_label : t.service_label + ' · ' + t.vehicle_label)}, ${esc(hhmm)}</h2>
      <span class="muted">${esc(day)} · ZD-${t.id}</span>
    </div>
    <button class="help-btn" data-act="help">${I.help}<b>Help</b></button>
    <div class="group">
      <div class="li" style="cursor:default"><span class="dot" style="margin:0 8px"></span><span class="grow t">${esc(t.from_text)}</span></div>
      <div class="li" style="cursor:default"><span class="sq" style="margin:0 8px"></span><span class="grow"><span class="t" style="display:block">${esc(t.to_text)}</span><span class="s">${t.ride_minutes ? 'Ride duration: ' + t.ride_minutes + ' min · ' : ''}${t.km} km</span></span></div>
    </div>
    ${t.note ? `<div class="soft small"><b>Note:</b> ${esc(t.note)}</div>` : ''}
    ${t.driver ? `<h3 class="sec">Details</h3>
    <div class="list"><div class="li" style="cursor:default"><span class="grow"><span class="t" style="display:block">${esc(d.name)}</span><span class="s">Driver</span></span><b>★ ${(+d.rating || 5).toFixed(2)}</b></div>
      <div class="li" style="cursor:default"><span class="grow"><span class="t" style="display:block">${esc(d.vehicle_label)}</span><span class="s">${esc(t.service_label)} service</span></span><b class="num">${esc(d.plate || '')}</b></div></div>` : ''}
    <h3 class="sec">Price</h3>
    <div class="group" style="padding:4px 14px 12px">
      <div class="row" style="min-height:48px;border-bottom:1px solid var(--line-2)"><span class="grow">${t.service === 'food' ? 'Delivery charge' : 'Ride price'}</span><span>${t.fare ? ZD.rs(t.fare) : '—'}</span></div>
      <div class="row" style="min-height:52px"><b class="grow">Total</b><b class="num" style="font-size:22px">${t.status === 'completed' ? ZD.rs(t.fare) : t.status === 'cancelled' ? 'Cancelled' : ZD.rs(t.fare || t.offer)}</b></div>
      ${t.service === 'food' ? '<div class="s" style="margin-bottom:8px">Plus the restaurant bill, paid to the rider at your door.</div>' : ''}
      <div class="row" style="background:#fff;border-radius:14px;padding:12px 14px"><span class="grow">Cash</span>${I.cash}</div>
      ${t.passenger_rating ? `<div class="s" style="margin-top:8px">You rated ${'★'.repeat(t.passenger_rating)}${t.passenger_note ? ' · ' + esc(t.passenger_note) : ''}</div>` : ''}
    </div>
    <div class="group">
      ${row('receipt', 'Get receipt', 'Share or save the trip summary')}
      ${row('help', 'Report a problem', 'Lost item, fare, driver, safety')}
      ${['completed', 'cancelled'].includes(t.status) ? row('remove', 'Remove from history', 'Only hides it from your list', '', '#C8102E') : ''}
    </div>`, {
    back() { trips(); }, help() { help(t); },
    receipt() {
      const txt = `ZarDrive receipt ZD-${t.id}\n${day} ${hhmm}\n${t.from_text} → ${t.to_text} (${t.km} km)\n${d.name ? 'Driver: ' + d.name + ', ' + d.vehicle_label + ' ' + (d.plate || '') + '\n' : ''}Total: ${ZD.rs(t.fare || t.offer)} · paid in cash`;
      if (navigator.share) navigator.share({ title: 'ZarDrive receipt', text: txt }).catch(() => {}); else { navigator.clipboard && navigator.clipboard.writeText(txt); ZD.toast('Receipt copied'); } },
    async remove(b) { if (!removeArm) { removeArm = true; b.querySelector('.t').textContent = 'Tap again to remove'; return; }
      try { await api('POST', `/trips/${t.id}/hide`); ZD.toast('Removed from your history'); trips(); } catch (e) { fail(e); } },
  }, 'tall');
  ZD.clearMap();
  const a = t.from_lat ? [+t.from_lat, +t.from_lng] : null, z = t.to_lat ? [+t.to_lat, +t.to_lng] : null;
  if (a && z) {
    try { const r = await api('POST', '/route', { from_lat: a[0], from_lng: a[1], to_lat: z[0], to_lng: z[1], vehicle: t.vehicle }); const pts = ZD.decode(r.geometry || ''); ZD.line(pts.length > 1 ? pts : [a, z]); ZD.fit([a, z].concat(pts)); }
    catch (e) { ZD.line([a, z]); ZD.fit([a, z]); }
    ZD.pin(a[0], a[1], 'from'); ZD.pin(z[0], z[1], 'to');
  }
}

function help(trip) {
  const TOPICS = ['Lost item', 'Fare or payment', 'Driver behaviour', 'Safety', 'App problem', 'Other'];
  let topic = '';
  const draw = (past) => screen(`${header('Support')}
    ${trip ? `<div class="soft small">About trip ZD-${trip.id}: ${esc(trip.from_text)} → ${esc(trip.to_text)}</div>` : ''}
    <div class="muted">What is it about?</div>
    <div class="chips" style="flex-wrap:wrap">${TOPICS.map(x => `<button class="chip" data-act="topic" aria-pressed="${x === topic}">${x}</button>`).join('')}</div>
    <label class="field"><span>Tell us what happened</span><textarea id="hmsg" class="input" style="height:110px" maxlength="1000" placeholder="Our support team reads every message and calls you back."></textarea></label>
    <button class="btn primary" data-act="send">Send to support</button>
    <a class="btn ghost" href="tel:15" style="color:#912018">Emergency? Call police 15</a>
    ${past && past.length ? `<h3 class="sec">Your requests</h3><div class="list">${past.map(r => `<div class="li" style="cursor:default"><span class="grow"><span class="t" style="display:block">${esc(r.topic)}${r.trip_id ? ' · ZD-' + r.trip_id : ''}</span><span class="s">${esc(ZD.when(r.created_at))} · ${esc(r.message.slice(0, 60))}</span></span><b style="font-size:12px;color:${r.status === 'open' ? 'var(--warn)' : 'var(--good)'}">${r.status === 'open' ? 'Open' : 'Solved'}</b></div>`).join('')}</div>` : ''}`, {
    back() { trip ? tripDetail(trip.id) : menu(); },
    topic(b) { const m = document.getElementById('hmsg').value; topic = b.textContent; draw(past); document.getElementById('hmsg').value = m; },
    async send(b) { busy(b, 'Sending…');
      try { await api('POST', '/help', { trip_id: trip ? trip.id : undefined, topic, message: document.getElementById('hmsg').value }); ZD.toast('Sent. Support will contact you soon.'); help(trip); } catch (e) { fail(e, b); } },
  }, 'full');
  draw([]);
  api('GET', '/help').then(r => { if (r.requests.length) { const m = document.getElementById('hmsg'); const keep = m ? m.value : ''; draw(r.requests); const n = document.getElementById('hmsg'); if (n) n.value = keep; } }).catch(() => {});
}

async function places() {
  screen(`${header('Places')}<div class="muted">Places you went to recently. Tap one to book a trip there.</div><div class="list" id="pl"><div class="muted">Loading…</div></div>`, {
    back() { menu(); },
    go(b) { const r = P.placeList[+b.dataset.i]; P.to = { name: r.to_text, lat: +r.to_lat, lng: +r.to_lng }; P.from ? fare() : whereTo('from'); },
  }, 'full');
  try {
    const t = (await api('GET', '/my/trips')).trips; const seen = new Set();
    P.placeList = t.filter(x => x.to_lat && !seen.has(x.to_text) && seen.add(x.to_text));
    document.getElementById('pl').innerHTML = P.placeList.map((r, i) => `<button class="li" data-act="go" data-i="${i}"><span class="ic">${ZD.icon.pin}</span><span class="t grow ell">${esc(r.to_text)}</span>${I.chev}</button>`).join('') || '<div class="muted">No places yet.</div>';
  } catch (e) { fail(e); }
}

function settings() {
  screen(`${header('Settings')}
    <div class="group">
      <div class="li" style="cursor:default"><span class="grow t">Language</span><span class="muted">English</span></div>
      <div class="li" style="cursor:default"><span class="grow t">Phone number</span><span class="muted">${esc(P.me.phone)}</span></div>
    </div>
    <div class="group">${row('out', 'Sign out', '', '', '#C8102E')}</div>
    <div class="muted small">To close your account, write to Support.</div>`, {
    back() { menu(); }, out() { ZD.store(TK, null); P.me = null; signIn(); },
  }, 'full');
}

function info() {
  screen(`${header('Information')}
    <div class="group" style="padding:14px">
      <b>ZarDrive</b><div class="muted" style="margin-top:4px">Name your price for rides, food delivery, parcels, intercity and loaders. Drivers answer with your price or theirs, and you choose.</div>
    </div>
    <div class="group" style="padding:14px"><div class="muted small">Map data © OpenStreetMap contributors and Google. Test version.</div></div>`, { back() { menu(); } }, 'full');
}

boot();
