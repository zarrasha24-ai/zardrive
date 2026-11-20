#!/usr/bin/env bash
# Download the latest Pakistan street data and rebuild the routes into a new folder,
# then switch "current" to it. The running service keeps the old routes until it is restarted.
# Keeps the 2 newest builds; older ones are removed only after a successful new build.
set -euo pipefail

DIR=/srv/zardrive-maps
PBF_URL=https://download.geofabrik.de/asia/pakistan-latest.osm.pbf
IMAGE=ghcr.io/project-osrm/osrm-backend:v5.27.1
NEW="$DIR/builds/$(date +%Y%m%d-%H%M)"

mkdir -p "$NEW"
echo "$(date -Is) download"
curl -fsSL --retry 3 -o "$NEW/pakistan.osm.pbf" "$PBF_URL"
[ "$(stat -c %s "$NEW/pakistan.osm.pbf")" -gt 50000000 ] || { echo "download too small, keeping current routes"; rm -rf "$NEW"; exit 1; }

echo "$(date -Is) build"
docker run --rm -v "$NEW:/data" "$IMAGE" osrm-extract -p /opt/car.lua /data/pakistan.osm.pbf
docker run --rm -v "$NEW:/data" "$IMAGE" osrm-partition /data/pakistan.osrm
docker run --rm -v "$NEW:/data" "$IMAGE" osrm-customize /data/pakistan.osrm
[ -f "$NEW/pakistan.osrm.mldgr" ] || { echo "build failed, keeping current routes"; exit 1; }
rm -f "$NEW/pakistan.osm.pbf"

ln -sfn "$NEW" "$DIR/current"
echo "$(date -Is) switched to $NEW"

# keep the 2 newest builds
ls -1dt "$DIR"/builds/*/ | tail -n +3 | xargs -r rm -rf
