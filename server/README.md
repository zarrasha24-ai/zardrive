# ZarDrive server (stage 1)

Plain PHP 8.2 + MySQL. No outside packages. Passenger and driver apps talk to `/api/...`; staff use `/admin`.

## Install on a test subdomain (e.g. zardrive.zarrasha.com)
1. cPanel → Domains → create `zardrive.zarrasha.com`, document root `zardrive/server/public`.
2. Upload this folder to `/home/zarrasha/zardrive/server/`.
3. cPanel → MySQL Databases → new database `zardrive` and user; give the user all privileges on it.
4. Copy `config.example.php` to `config.local.php` and fill in the database name, user and password.
5. Run once: `php bin/install.php` (cPanel Terminal or a one-off cron job).
6. Open `https://zardrive.zarrasha.com/admin` → it asks you to create the first admin.
7. Cron every 10 minutes: `php /home/zarrasha/zardrive/server/bin/cron.php`.

Moving to the real domain later = point the new domain at the same `public` folder (or copy the folder and database to the new server).

## Test
`php tests/flow.php` – 45 checks on a throw-away database (signup, top-ups, offers, rides, food split, passes, launch offer, refund, books balance).

## Money rules (all editable in Admin → Fare guide and commission)
- Rides, courier, intercity, freight: 6% commission per trip from the driver wallet; 0% on a pass or in a new driver's first 30 days.
- Food: delivery charge split rider 80 / ZarDrive 20 (editable, no agent share). Restaurant commission switch off.
- Passes: day pass per vehicle (car Rs 250); week pass = 6 × day price, earned one day at a time.
- Top-ups are money held for the driver (account 2100), not income.

## Maps (low cost)
- Distance, time, fare estimate and route line: free OSRM on OpenStreetMap (`/api/route`), default server
  `https://routing.openstreetmap.de/routed-{profile}` (Admin → Fare guide → Maps and routing). Cached 7 days per route.
  If OSRM is down, a straight-line × 1.35 estimate is used and marked `source: estimate`.
- When the phone sends map points, the server works out the distance itself (the phone's km is ignored).
- Phone apps: Google Maps SDK only to display the map (free on Android/iOS); no paid Directions/Distance Matrix calls.
- Later, for heavy traffic: self-host OSRM with the Pakistan extract on a small server and change the address in admin.

## Address search on our own map data (no Google Places cost)
`storage/pakistan-places.tsv.gz` holds 139,539 named places in Pakistan (areas, roads, shops, hospitals, mosques, bus stops …),
built from the Geofabrik Pakistan download of 19 Nov 2026. Load it once after install:

    php bin/import-places.php storage/pakistan-places.tsv.gz

Apps then call `GET /api/places/search?q=tehkal&lat=..&lng=..` (typing) and `GET /api/places/reverse?lat=..&lng=..` (map pin).
English typing also finds names stored in Urdu script (Tehkal ↔ تاکال) by sound-alike matching.
To refresh: download `pakistan-latest-free.gpkg.zip` from Geofabrik, unzip, then
`python3 bin/build_places.py pakistan.gpkg storage/pakistan-places.tsv.gz` and import again.
Data © OpenStreetMap contributors (ODbL) — keep that line on the apps' about screen.
