# ZarDrive

Fair-offer rides, food delivery, courier, intercity and freight for Pakistan. Separate from Zarrasha.

| Folder | What it is |
|---|---|
| `server/` | API, admin, accounts, passenger web app (`/app`), driver web app (`/driver`) — plain PHP 8.2, SQLite or MySQL |
| `apps/` | Android apps (Flutter): ZarDrive and ZarDrive Driver, built on GitHub |
| `maps-vps/` | Live server: own OSRM routing on Pakistan OpenStreetMap data |

Test site: https://zardrive.zarrasha.com (code on the host in `/home/zarrasha/zardrive`).

**Android builds:** every push to `main` → Actions → "Build Android apps" → Artifacts: `ZarDrive-passenger-apk`, `ZarDrive-driver-apk`.
The test APKs are signed with a debug key; the Play Store upload key is kept by the owner, never in this repo.

**Never commit:** `server/config.local.php`, databases, keys or passwords.
