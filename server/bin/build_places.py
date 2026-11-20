"""Build ZarDrive's local place-search list from the Geofabrik Pakistan GeoPackage.

python3 -I build_places.py <pakistan.gpkg> <out.tsv.gz>

One row per searchable place: name, kind, lat, lng, area (neighbourhood/town it is in), city, weight.
Roads are merged per name and town, so "University Road, Peshawar" is one entry, not 200 segments.
"""
import gzip, math, sqlite3, struct, sys, unicodedata
from collections import defaultdict

src, out = sys.argv[1], sys.argv[2]
db = sqlite3.connect(src)


def bbox(blob):
    """Bounding box (minx, miny, maxx, maxy) of a GeoPackage geometry blob."""
    if not blob or blob[:2] != b"GP":
        return None
    flags = blob[3]
    le = "<" if flags & 1 else ">"
    env = (flags >> 1) & 7
    if env:
        minx, maxx, miny, maxy = struct.unpack_from(le + "4d", blob, 8)
        return minx, miny, maxx, maxy
    off = 8 + {0: 0, 1: 32, 2: 48, 3: 48, 4: 64}[env]
    xs, ys = [], []

    def geom(o):
        bo = "<" if blob[o] == 1 else ">"
        t = struct.unpack_from(bo + "I", blob, o + 1)[0] % 1000
        o += 5
        if t == 1:
            x, y = struct.unpack_from(bo + "2d", blob, o); xs.append(x); ys.append(y); return o + 16
        if t == 2:
            n = struct.unpack_from(bo + "I", blob, o)[0]; o += 4
            for i in range(n):
                x, y = struct.unpack_from(bo + "2d", blob, o + 16 * i); xs.append(x); ys.append(y)
            return o + 16 * n
        if t == 3:
            rings = struct.unpack_from(bo + "I", blob, o)[0]; o += 4
            for _ in range(rings):
                n = struct.unpack_from(bo + "I", blob, o)[0]; o += 4
                for i in range(n):
                    x, y = struct.unpack_from(bo + "2d", blob, o + 16 * i); xs.append(x); ys.append(y)
                o += 16 * n
            return o
        if t in (4, 5, 6, 7):
            n = struct.unpack_from(bo + "I", blob, o)[0]; o += 4
            for _ in range(n):
                o = geom(o)
            return o
        raise ValueError(t)

    geom(off)
    return (min(xs), min(ys), max(xs), max(ys)) if xs else None


def centre(blob):
    b = bbox(blob)
    return None if b is None else ((b[1] + b[3]) / 2, (b[0] + b[2]) / 2)  # lat, lng


def clean(s):
    s = unicodedata.normalize("NFC", (s or "").strip())
    return " ".join(s.replace("\t", " ").replace("\n", " ").split())


# 1. settlements, used to tag every other row with its area and city
PLACE_W = {"national_capital": 100, "city": 90, "town": 70, "suburb": 65, "neighbourhood": 60, "locality": 50, "village": 40, "hamlet": 25}
places = []
for name, fclass, pop, g in db.execute("SELECT name, fclass, population, geom FROM gis_osm_places_free WHERE name <> ''"):
    if fclass not in PLACE_W:
        continue
    c = centre(g)
    if c:
        places.append((clean(name), fclass, int(pop or 0), c[0], c[1]))
for name, fclass, pop, g in db.execute("SELECT name, fclass, population, geom FROM gis_osm_places_a_free WHERE name <> ''"):
    if fclass in PLACE_W and (c := centre(g)):
        places.append((clean(name), fclass, int(pop or 0), c[0], c[1]))

CELL = 0.1  # ~11 km grid for nearest-place lookups
grid = defaultdict(list)
for p in places:
    grid[(int(p[3] // CELL), int(p[4] // CELL))].append(p)


def km(a1, o1, a2, o2):
    x = math.radians(o2 - o1) * math.cos(math.radians((a1 + a2) / 2)); y = math.radians(a2 - a1)
    return 6371 * math.hypot(x, y)


def nearest(lat, lng, kinds, limit_km):
    gi, gj = int(lat // CELL), int(lng // CELL)
    r = max(1, math.ceil(limit_km / 11))
    best, bd = None, limit_km
    for i in range(gi - r, gi + r + 1):
        for j in range(gj - r, gj + r + 1):
            for p in grid.get((i, j), ()):
                if p[1] in kinds:
                    d = km(lat, lng, p[3], p[4])
                    if d < bd:
                        best, bd = p, d
    return best[0] if best else ""


CITY = {"national_capital", "city", "town"}
AREA = {"suburb", "neighbourhood", "locality", "village", "hamlet"}


def tags(lat, lng):
    city = nearest(lat, lng, CITY, 25)
    area = nearest(lat, lng, AREA, 3)
    return area, city


rows = []


def add(name, kind, lat, lng, weight):
    name = clean(name)
    if not name or len(name) > 120 or not (23.5 <= lat <= 37.2 and 60.8 <= lng <= 77.9):
        return
    area, city = tags(lat, lng)
    if area == name:
        area = ""
    rows.append((name, kind, round(lat, 6), round(lng, 6), area, city if city != name else "", weight))


for name, fclass, pop, lat, lng in places:
    add(name, "area" if fclass not in CITY else "city", lat, lng, PLACE_W[fclass] + min(10, int(math.log10(pop + 1) * 2)))

# 2. shops, food, hospitals, schools, banks … (points and building outlines)
BIG = {"hospital": 55, "university": 55, "college": 45, "mall": 55, "marketplace": 50, "stadium": 50, "hotel": 45, "attraction": 45,
       "park": 40, "restaurant": 40, "fast_food": 38, "bank": 35, "school": 35, "police": 35, "supermarket": 38, "cafe": 35}
for t in ("gis_osm_pois_free", "gis_osm_pois_a_free"):
    for name, fclass, g in db.execute(f"SELECT name, fclass, geom FROM {t} WHERE name <> ''"):
        if (c := centre(g)):
            add(name, fclass, c[0], c[1], BIG.get(fclass, 30))
for t in ("gis_osm_pofw_free", "gis_osm_pofw_a_free"):
    for name, fclass, g in db.execute(f"SELECT name, fclass, geom FROM {t} WHERE name <> ''"):
        if (c := centre(g)):
            add(name, "mosque" if fclass.startswith("muslim") else "worship", c[0], c[1], 32)
TRANSPORT = {"bus_station": 50, "railway_station": 50, "airport": 70, "bus_stop": 30, "taxi": 30, "railway_halt": 30}
for t in ("gis_osm_transport_free", "gis_osm_transport_a_free"):
    for name, fclass, g in db.execute(f"SELECT name, fclass, geom FROM {t} WHERE name <> ''"):
        if fclass in TRANSPORT and (c := centre(g)):
            add(name, fclass, c[0], c[1], TRANSPORT[fclass])
LAND = {"residential": ("area", 50), "commercial": ("market", 45), "retail": ("market", 45), "industrial": ("industrial", 35),
        "park": ("park", 40), "military": ("area", 30), "cemetery": ("graveyard", 30), "recreation_ground": ("park", 35)}
for name, fclass, g in db.execute("SELECT name, fclass, geom FROM gis_osm_landuse_a_free WHERE name <> ''"):
    if fclass in LAND and (c := centre(g)):
        add(name, LAND[fclass][0], c[0], c[1], LAND[fclass][1])
for name, g in db.execute("SELECT name, geom FROM gis_osm_buildings_a_free WHERE name <> ''"):
    if (c := centre(g)):
        add(name, "building", c[0], c[1], 33)

# 3. named roads, merged per name and city
ROAD_W = {"motorway": 45, "trunk": 45, "primary": 44, "secondary": 40, "tertiary": 36, "residential": 30, "unclassified": 28, "living_street": 28, "service": 22}
roads = defaultdict(lambda: [0.0, 0.0, 0, 0, None])
for name, fclass, g in db.execute("SELECT name, fclass, geom FROM gis_osm_roads_free WHERE name <> ''"):
    if fclass not in ROAD_W or not (b := bbox(g)):
        continue
    lat, lng = (b[1] + b[3]) / 2, (b[0] + b[2]) / 2
    key = (clean(name).lower(), nearest(lat, lng, CITY, 25), int(lat // 0.2), int(lng // 0.2))
    r = roads[key]
    r[0] += lat; r[1] += lng; r[2] += 1; r[3] = max(r[3], ROAD_W[fclass]); r[4] = r[4] or clean(name)
for (_, _, _, _), (slat, slng, n, w, name) in roads.items():
    add(name, "road", slat / n, slng / n, w + min(6, n // 10))

# de-duplicate: same name and kind within ~150 m
seen, final = set(), []
for r in sorted(rows, key=lambda r: -r[6]):
    k = (r[0].lower(), r[1], round(r[2], 3), round(r[3], 3))
    if k not in seen:
        seen.add(k); final.append(r)

with gzip.open(out, "wt", encoding="utf-8") as f:
    f.write("name\tkind\tlat\tlng\tarea\tcity\tweight\n")
    for r in final:
        f.write("\t".join(str(x) for x in r) + "\n")

kinds = defaultdict(int)
for r in final:
    kinds[r[1]] += 1
print(len(final), "places;", ", ".join(f"{k} {v}" for k, v in sorted(kinds.items(), key=lambda x: -x[1])[:14]))
