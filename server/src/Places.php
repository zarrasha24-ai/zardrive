<?php
declare(strict_types=1);

namespace Zd;

/**
 * Address search on our own copy of OpenStreetMap (Pakistan), so the apps do not pay Google for place search.
 * Data comes from bin/import-places.php (built from the Geofabrik Pakistan download). © OpenStreetMap contributors, ODbL.
 */
final class Places
{
    /** Lower-case, Latin letters and digits only, single spaces: "Hayatabad Ph-3" → "hayatabad ph 3". */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    private const URDU = ['ب' => 'b', 'پ' => 'p', 'ت' => 't', 'ٹ' => 't', 'ث' => 's', 'ج' => 'j', 'چ' => 'c', 'خ' => 'k', 'د' => 'd', 'ڈ' => 'd',
        'ذ' => 's', 'ر' => 'r', 'ڑ' => 'r', 'ز' => 's', 'ژ' => 's', 'س' => 's', 'ش' => 's', 'ص' => 's', 'ض' => 's', 'ط' => 't', 'ظ' => 's',
        'غ' => 'g', 'ف' => 'f', 'ق' => 'k', 'ک' => 'k', 'ك' => 'k', 'گ' => 'g', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    /**
     * Sound skeleton of a word, the same for English and Urdu spellings: consonants only, similar sounds merged.
     * "Tehkal" and "تاکال" → "tkl"; "Gulbahar" and "گلبہار" → "glbr"; "Qissa Khwani" and "قصہ خوانی" → "ks kn".
     */
    public static function skel(string $s): string
    {
        $out = [];
        foreach (explode(' ', self::norm($s)) as $w) {
            if ($w === '') continue;
            if (preg_match('/^[a-z0-9]+$/', $w)) {
                $w = str_replace(['ch', 'ph', 'sh', 'kh', 'gh', 'th', 'dh', 'x'], ['c', 'f', 's', 'k', 'g', 't', 'd', 'ks'], $w);
                $w = strtr($w, ['q' => 'k', 'c' => 'k', 'z' => 's', 'v' => '']);
                $w = preg_replace('/[aeiouyhw]/', '', $w) ?? '';
            } else {
                $t = '';
                foreach (preg_split('//u', $w, -1, PREG_SPLIT_NO_EMPTY) as $ch) $t .= self::URDU[$ch] ?? (preg_match('/[a-z0-9]/', $ch) ? $ch : '');
                $w = str_replace('c', 'k', $t);
            }
            $w = preg_replace('/(.)\1+/', '$1', $w) ?? '';
            if ($w !== '') $out[] = $w;
        }
        return mb_substr(implode(' ', $out), 0, 190);
    }

    /** Text stored in places.search: name, area and city, so "saddar peshawar" finds Saddar Road in Peshawar. */
    public static function searchText(string $name, ?string $area, ?string $city): string
    {
        return mb_substr(self::norm($name . ' ' . ($area ?? '') . ' ' . ($city ?? '')), 0, 190, 'UTF-8');
    }

    /**
     * Up to 10 matches for what the passenger typed, best first.
     * Every word must appear; names that start with the first word, important places and places near the passenger come first.
     */
    public static function search(string $q, ?float $lat = null, ?float $lng = null): array
    {
        $q = self::norm($q);
        if (mb_strlen($q, 'UTF-8') < 2) return [];
        $words = array_slice(explode(' ', $q), 0, 5);
        $where = []; $p = [];
        foreach ($words as $w) { $where[] = '(search LIKE ? OR search LIKE ?)'; $p[] = $w . '%'; $p[] = '% ' . $w . '%'; }
        $sql = 'SELECT id, name, kind, lat, lng, area, city, weight, search FROM places WHERE ' . implode(' AND ', $where);

        $rows = [];
        if ($lat !== null && $lng !== null) {   // first look within ~50 km of the passenger
            $rows = Db::all($sql . ' AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? ORDER BY weight DESC LIMIT 300',
                array_merge($p, [$lat - 0.45, $lat + 0.45, $lng - 0.55, $lng + 0.55]));
        }
        if (count($rows) < 3) {
            $seen = array_column($rows, 'id');
            foreach (Db::all($sql . ' ORDER BY weight DESC LIMIT 300', $p) as $r) if (!in_array($r['id'], $seen)) $rows[] = $r;
        }

        // Sound-alike match for names written in Urdu script (most Peshawar areas in OpenStreetMap)
        if (count($rows) < 10 && preg_match('/[a-z]/', $q)) {
            $sk = array_values(array_filter(explode(' ', self::skel($q)), fn($w) => strlen($w) >= 2));
            if ($sk) {
                $w2 = []; $p2 = [];
                foreach ($sk as $w) { $w2[] = '(skel LIKE ? OR skel LIKE ?)'; $p2[] = $w . '%'; $p2[] = '% ' . $w . '%'; }
                $sql2 = 'SELECT id, name, kind, lat, lng, area, city, weight, search FROM places WHERE ' . implode(' AND ', $w2);
                $box = $lat !== null && $lng !== null ? ' AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?' : '';
                $seen = array_column($rows, 'id');
                foreach (Db::all($sql2 . $box . ' ORDER BY weight DESC LIMIT 200', $box ? array_merge($p2, [$lat - 0.45, $lat + 0.45, $lng - 0.55, $lng + 0.55]) : $p2) as $r) {
                    if (!in_array($r['id'], $seen)) { $r['weight'] -= 12; $rows[] = $r; }
                }
            }
        }

        $first = $words[0];
        foreach ($rows as &$r) {
            $name = self::norm($r['name']);
            $score = (int) $r['weight'];
            if ($name === $q) $score += 60;
            elseif (str_starts_with($name, $q)) $score += 40;
            elseif (str_starts_with($name, $first)) $score += 20;
            if ($lat !== null && $lng !== null) {
                $km = Maps::straightKm($lat, $lng, (float) $r['lat'], (float) $r['lng']);
                $r['km'] = round($km, 1);
                $score += $km < 3 ? 35 : ($km < 10 ? 25 : ($km < 30 ? 12 : ($km < 80 ? 0 : -25)));
            }
            $r['score'] = $score;
        }
        unset($r);
        usort($rows, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']));
        return array_map(fn($r) => self::out($r), array_slice($rows, 0, 10));
    }

    /** The nearest named place to a map pin (within ~300 m), for "pick-up: <name>" when the passenger drops a pin. */
    public static function reverse(float $lat, float $lng): ?array
    {
        $d = 0.003;
        $rows = Db::all('SELECT id, name, kind, lat, lng, area, city, weight FROM places WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? LIMIT 400',
            [$lat - $d, $lat + $d, $lng - $d * 1.2, $lng + $d * 1.2]);
        $best = null; $bestScore = INF;
        foreach ($rows as $r) {
            $km = Maps::straightKm($lat, $lng, (float) $r['lat'], (float) $r['lng']);
            if ($km > 0.3) continue;
            $score = $km * 1000 - ($r['kind'] === 'road' ? 0 : 40) + ($r['kind'] === 'area' || $r['kind'] === 'city' ? 120 : 0)
                + (preg_match('/[a-z]/i', $r['name']) ? 0 : 60);   // apps are in English: prefer an English name about as close
            if ($score < $bestScore) { $bestScore = $score; $best = $r + ['km' => round($km, 2)]; }
        }
        return $best ? self::out($best) : null;
    }

    private static function out(array $r): array
    {
        $label = trim(implode(', ', array_filter([$r['area'] ?? '', $r['city'] ?? ''])));
        return ['name' => $r['name'], 'kind' => $r['kind'], 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'label' => $label]
            + (isset($r['km']) ? ['km' => $r['km']] : []);
    }

    /** Replace all places with the rows of a .tsv or .tsv.gz file (header: name kind lat lng area city weight). Returns the count. */
    public static function import(string $file, ?callable $progress = null): int
    {
        $fh = str_ends_with($file, '.gz') ? gzopen($file, 'rb') : fopen($file, 'rb');
        if (!$fh) throw new ApiError('Cannot open ' . basename($file));
        $read = fn() => str_ends_with($file, '.gz') ? gzgets($fh) : fgets($fh);
        $head = rtrim((string) $read(), "\r\n");
        if ($head !== "name\tkind\tlat\tlng\tarea\tcity\tweight") throw new ApiError('Not a places file');
        $pdo = Db::pdo();
        $n = 0; $batch = [];
        $flush = function () use (&$batch, $pdo) {
            if (!$batch) return;
            $st = $pdo->prepare('INSERT INTO places (name, kind, lat, lng, area, city, weight, search, skel) VALUES ' . implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?,?,?)')));
            $st->execute(array_merge(...$batch));
            $batch = [];
        };
        Db::tx(function () use ($read, &$batch, $flush, &$n, $progress) {
            Db::run('DELETE FROM places');
            while (($line = $read()) !== false) {
                $c = explode("\t", rtrim($line, "\r\n"));
                if (count($c) !== 7 || $c[0] === '') continue;
                [$name, $kind, $lat, $lng, $area, $city, $w] = $c;
                $batch[] = [mb_substr($name, 0, 190, 'UTF-8'), $kind, (float) $lat, (float) $lng, $area ?: null, $city ?: null, (int) $w, self::searchText($name, $area, $city), self::skel($name . ' ' . $area . ' ' . $city)];
                if (count($batch) === 100) $flush();  // 100 rows × 9 values stays under old SQLite limit of 999
                if (++$n % 20000 === 0 && $progress) $progress($n);
            }
            $flush();
        });
        fclose($fh);
        Audit::log(null, 'places.import', $n . ' rows from ' . basename($file));
        return $n;
    }
}
