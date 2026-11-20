#!/usr/bin/env bash
# ZarDrive maps on the live server – Pakistan street data stored locally, routing by OSRM.
# Run once as root on a fresh Ubuntu 22.04/24.04 VPS (4 GB RAM, 2 CPU, 40 GB disk):
#   sudo bash setup.sh
# Afterwards set Admin → Fare guide → Maps and routing → OSRM server to:  http://127.0.0.1:5000
set -euo pipefail

DIR=/srv/zardrive-maps
PBF_URL=https://download.geofabrik.de/asia/pakistan-latest.osm.pbf
IMAGE=ghcr.io/project-osrm/osrm-backend:v5.27.1

echo "== 1/5 Docker"
if ! command -v docker >/dev/null; then
  apt-get update -y
  apt-get install -y docker.io curl
  systemctl enable --now docker
fi

echo "== 2/5 Folders"
mkdir -p "$DIR/builds"
cp "$(dirname "$0")/update.sh" "$DIR/update.sh"
chmod 750 "$DIR/update.sh"

echo "== 3/5 First build (download Pakistan + build routes, ~20–40 min)"
"$DIR/update.sh"

echo "== 4/5 Routing service (only reachable from this server: 127.0.0.1:5000)"
cat > /etc/systemd/system/zardrive-osrm.service <<UNIT
[Unit]
Description=ZarDrive OSRM routing (Pakistan)
After=docker.service
Requires=docker.service

[Service]
Restart=always
RestartSec=5
ExecStartPre=-/usr/bin/docker rm -f zardrive-osrm
ExecStart=/usr/bin/docker run --name zardrive-osrm --rm -p 127.0.0.1:5000:5000 -v $DIR/current:/data:ro $IMAGE osrm-routed --algorithm mld --max-table-size 200 /data/pakistan.osrm
ExecStop=/usr/bin/docker stop zardrive-osrm

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now zardrive-osrm

echo "== 5/5 Weekly update (Sunday 3:30 AM, Asia/Karachi server time)"
echo "30 3 * * 0 root $DIR/update.sh >> $DIR/update.log 2>&1 && systemctl restart zardrive-osrm" > /etc/cron.d/zardrive-maps

sleep 5
echo "== Test: Board Bazar → Saddar, Peshawar"
curl -s "http://127.0.0.1:5000/route/v1/driving/71.4850,34.0010;71.5450,34.0090?overview=false" | head -c 300; echo
echo "Done. Set the OSRM server in ZarDrive admin to http://127.0.0.1:5000"
