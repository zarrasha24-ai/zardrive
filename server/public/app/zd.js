/* ZarDrive shared helpers for the passenger and driver apps (no build step, plain browser JavaScript). */
'use strict';

const ZD = {
  RED: '#C8102E',
  store(k, v) { try { if (v === undefined) return localStorage.getItem(k); if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { return null; } },

  async api(method, path, body, tokenKey) {
    const tok = ZD.store(tokenKey);
    const res = await fetch('/api' + path, {
      method, headers: Object.assign({ 'Content-Type': 'application/json' }, tok ? { Authorization: 'Bearer ' + tok } : {}),
      body: body ? JSON.stringify(body) : undefined,
    });
    let j;
    try { j = await res.json(); } catch (e) { throw new Error('No connection. Check your internet.'); }
    if (!j.ok) { const err = new Error(j.error || 'Something went wrong'); err.status = res.status; throw err; }
    return j.data;
  },

  esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); },
  rs(v) { return 'Rs ' + Math.round(Number(v) || 0).toLocaleString('en-PK'); },
  rs2(v) { return 'Rs ' + (Number(v) || 0).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
  initials(n) { return String(n || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase(); },
  when(s) { const d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleString('en-PK', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }); },

  toastTimer: null,
  toast(msg) { const t = document.getElementById('toast'); t.textContent = msg; t.classList.add('show'); clearTimeout(ZD.toastTimer); ZD.toastTimer = setTimeout(() => t.classList.remove('show'), 3800); },

  // timers that belong to the current screen; cleared on every screen change
  timers: [],
  every(ms, fn) { fn(); ZD.timers.push(setInterval(fn, ms)); },
  clearTimers() { ZD.timers.forEach(clearInterval); ZD.timers = []; },

  // ---- map: Google Maps when admin saved a key (Maps JavaScript API), else free OpenStreetMap (Leaflet)
  map: null, kind: null, layers: null, items: [],
  PIN: {
    from: [16, '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"><circle cx="8" cy="8" r="6.5" fill="#111418" stroke="#fff" stroke-width="3"/></svg>'],
    to: [16, '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"><rect x="1.5" y="1.5" width="13" height="13" rx="3" fill="#C8102E" stroke="#fff" stroke-width="3"/></svg>'],
    car: [30, '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30"><circle cx="15" cy="15" r="13" fill="#111418" stroke="#fff" stroke-width="3"/><circle cx="15" cy="15" r="5" fill="#C8102E"/></svg>'],
    me: [18, '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"><circle cx="9" cy="9" r="7" fill="#2563EB" stroke="#fff" stroke-width="3"/></svg>'],
  },
  GSTYLE: [
    { featureType: 'poi.business', stylers: [{ visibility: 'off' }] },
    { featureType: 'poi', elementType: 'labels.icon', stylers: [{ visibility: 'off' }] },
    { featureType: 'transit', elementType: 'labels.icon', stylers: [{ visibility: 'off' }] },
  ],
  script(src) { return new Promise((ok, bad) => { const s = document.createElement('script'); s.src = src; s.async = true; s.onload = ok; s.onerror = bad; document.head.appendChild(s); }); },
  css(href) { const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = href; document.head.appendChild(l); },
  async initMap(maps) {
    if (ZD.map) return;
    if (maps && maps.provider === 'google' && maps.google_key) {
      try {
        await new Promise((ok, bad) => {
          window.zdMapsReady = ok;
          window.gm_authFailure = () => ZD.useOsm();   // wrong or blocked key: fall back without breaking the app
          ZD.script(`https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(maps.google_key)}&callback=zdMapsReady&v=weekly&language=en&region=PK`).catch(bad);
          setTimeout(() => bad(new Error('timeout')), 12000);
        });
        ZD.kind = 'google';
        ZD.map = new google.maps.Map(document.getElementById('map'), { center: { lat: 34.0086, lng: 71.5249 }, zoom: 14, disableDefaultUI: true, clickableIcons: false, gestureHandling: 'greedy', styles: ZD.GSTYLE });
        return;
      } catch (e) { /* fall back to OpenStreetMap */ }
    }
    await ZD.useOsm();
  },
  async useOsm() {
    const el = document.getElementById('map'); el.innerHTML = ''; ZD.map = null; ZD.items = [];
    try {
      if (!window.L) { ZD.css('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'); await ZD.script('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'); }
      ZD.kind = 'osm';
      ZD.map = L.map(el, { zoomControl: false }).setView([34.0086, 71.5249], 14);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap contributors' }).addTo(ZD.map);
      ZD.layers = L.layerGroup().addTo(ZD.map);
    } catch (e) { ZD.map = null; }
  },
  clearMap() {
    if (ZD.kind === 'google') { ZD.items.forEach(i => i.setMap(null)); ZD.items = []; }
    else if (ZD.layers) ZD.layers.clearLayers();
  },
  refreshMap() { if (ZD.map && ZD.kind === 'osm') setTimeout(() => ZD.map.invalidateSize(), 60); },
  pin(lat, lng, kind) {
    if (!ZD.map || lat == null) return null;
    const [size, svg] = ZD.PIN[kind];
    if (ZD.kind === 'google') {
      const m = new google.maps.Marker({ position: { lat: +lat, lng: +lng }, map: ZD.map, clickable: false, zIndex: kind === 'car' ? 10 : 5,
        icon: { url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg), scaledSize: new google.maps.Size(size, size), anchor: new google.maps.Point(size / 2, size / 2) } });
      ZD.items.push(m); return m;
    }
    return L.marker([lat, lng], { icon: L.divIcon({ html: svg, className: '', iconSize: [size, size], iconAnchor: [size / 2, size / 2] }), keyboard: false }).addTo(ZD.layers);
  },
  line(points) {
    if (!ZD.map || !points.length) return null;
    if (ZD.kind === 'google') { const l = new google.maps.Polyline({ path: points.map(p => ({ lat: p[0], lng: p[1] })), strokeColor: '#C8102E', strokeWeight: 5, strokeOpacity: .9, map: ZD.map }); ZD.items.push(l); return l; }
    return L.polyline(points, { color: '#C8102E', weight: 5, opacity: .9 }).addTo(ZD.layers);
  },
  fit(points) {
    if (!ZD.map || !points.length) return;
    if (ZD.kind === 'google') {
      if (points.length === 1) { ZD.map.setCenter({ lat: points[0][0], lng: points[0][1] }); ZD.map.setZoom(15); return; }
      const b = new google.maps.LatLngBounds(); points.forEach(p => b.extend({ lat: p[0], lng: p[1] })); ZD.map.fitBounds(b, 48); return;
    }
    ZD.map.fitBounds(L.latLngBounds(points), { padding: [40, 40], maxZoom: 16 });
  },
  center(lat, lng, z) {
    if (!ZD.map) return;
    if (ZD.kind === 'google') { ZD.map.setCenter({ lat: +lat, lng: +lng }); if (z) ZD.map.setZoom(z); return; }
    ZD.map.setView([lat, lng], z || ZD.map.getZoom());
  },

  /** Google/OSRM encoded polyline (precision 5) → [[lat, lng], …] */
  decode(str) {
    const pts = []; let i = 0, lat = 0, lng = 0;
    while (str && i < str.length) {
      for (const k of [0, 1]) {
        let b, shift = 0, res = 0;
        do { b = str.charCodeAt(i++) - 63; res |= (b & 0x1f) << shift; shift += 5; } while (b >= 0x20 && i < str.length);
        const d = (res & 1) ? ~(res >> 1) : (res >> 1);
        if (k === 0) lat += d; else lng += d;
      }
      pts.push([lat / 1e5, lng / 1e5]);
    }
    return pts.filter(p => p[0] > 20 && p[0] < 40 && p[1] > 55 && p[1] < 80);
  },

  here(timeout) {
    return new Promise(resolve => {
      if (!navigator.geolocation) return resolve(null);
      navigator.geolocation.getCurrentPosition(p => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }), () => resolve(null), { enableHighAccuracy: true, timeout: timeout || 8000, maximumAge: 20000 });
    });
  },

  icon: {
    back: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>',
    menu: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
    search: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>',
    pin: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>',
    locate: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M20 4L4 11l7 2 2 7z"/></svg>',
    star: (on) => `<svg width="38" height="38" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.8 5.8 6.2.9-4.5 4.4 1 6.3L12 17.4 6.5 20.4l1-6.3L3 9.7l6.2-.9z" fill="${on ? '#E5A52E' : '#D5DADF'}"/></svg>`,
    car: (fill) => `<svg viewBox="0 0 40 26" aria-hidden="true"><path d="M3 17c0-3 1-5 4-6l5-4h12l6 4c4 0 7 2 7 6z" fill="${fill}"/><circle cx="10" cy="21" r="3.6" fill="#111418"/><circle cx="30" cy="21" r="3.6" fill="#111418"/></svg>`,
    bike: (fill) => `<svg viewBox="0 0 40 26" aria-hidden="true"><path d="M8 17l6-8h7l3 4h6l5 4z" fill="${fill}"/><circle cx="10" cy="21" r="3.6" fill="#111418"/><circle cx="30" cy="21" r="3.6" fill="#111418"/></svg>`,
    rick: (fill) => `<svg viewBox="0 0 40 26" aria-hidden="true"><path d="M6 17V7h16l8 6v4z" fill="${fill}"/><circle cx="10" cy="21" r="3.6" fill="#111418"/><circle cx="30" cy="21" r="3.6" fill="#111418"/></svg>`,
    box: (fill) => `<svg viewBox="0 0 40 26" aria-hidden="true"><path d="M3 7h22v10H3zM25 10h6l5 4v3H25z" fill="${fill}"/><circle cx="9" cy="21" r="3.6" fill="#111418"/><circle cx="30" cy="21" r="3.6" fill="#111418"/></svg>`,
    bowl: (fill) => `<svg viewBox="0 0 40 26" aria-hidden="true"><path d="M6 9h28c0 6-6 10-14 10S6 15 6 9z" fill="${fill}"/><path d="M15 6h10" stroke="#111418" stroke-width="2" stroke-linecap="round"/></svg>`,
  },
  vehIcon(v) { return { bike: ZD.icon.bike('#C8102E'), rickshaw: ZD.icon.rick('#F2D16B'), economy: ZD.icon.car('#E3E6E9'), comfort: ZD.icon.car('#DCE8F5'), loader: ZD.icon.box('#9AC29C') }[v] || ZD.icon.car('#E3E6E9'); },
  googleNav(lat, lng) { return `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}&travelmode=driving`; },
};

