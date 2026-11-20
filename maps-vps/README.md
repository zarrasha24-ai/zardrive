# ZarDrive maps – Pakistan street data on our own server (live)

Test (now): zardrive.zarrasha.com uses the free public OSRM. Shared hosting cannot run OSRM itself.
Live (later): ZarDrive's own VPS keeps OpenStreetMap's Pakistan street data and runs OSRM locally –
no per-request cost, no limits, no dependence on a public service.

## Server
Ubuntu 22.04/24.04 · 4 GB RAM · 2 CPU · 40 GB disk. The ZarDrive server (PHP + MySQL) can run on the same VPS.

## Install
    sudo bash setup.sh
Downloads `pakistan-latest.osm.pbf` from Geofabrik, builds the car routing data with OSRM (MLD),
starts it as a service on 127.0.0.1:5000 (not open to the internet) and adds a weekly update.
Then in ZarDrive admin → Fare guide and commission → Maps and routing → OSRM server: `http://127.0.0.1:5000`

## Weekly update
`update.sh` (Sunday 3:30 AM): new download → new build in a new folder → switch → restart.
If the download or build fails, the current routes stay in use. Log: /srv/zardrive-maps/update.log

## Later (optional)
- Address search on the same data (Photon) instead of paid Google Places.
- Own map display (vector tiles from the same Pakistan data) if Google maps display is ever dropped.

Data © OpenStreetMap contributors, ODbL – show "© OpenStreetMap contributors" in the apps' about screen.
