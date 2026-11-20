<?php
declare(strict_types=1);

namespace Zd;

/**
 * Distance, time and route from free OSRM (OpenStreetMap routing) – no Google Directions/Distance Matrix cost.
 * The phone apps still show Google Maps tiles (free on the mobile SDK) and draw the route line returned here.
 *
 * Server: settings `osrm_url` (default FOSSGIS public servers, profile in the path) – move to a self-hosted
 * OSRM for Pakistan when traffic grows. Results are cached per rounded point pair for 7 days.
 * If OSRM cannot be reached, a straight-line estimate × road factor is used and marked as such.
 */
final class Maps
{
    /** vehicle → OSRM profile */
    private const PROFILE = ['bike' => 'car', 'rickshaw' => 'car', 'economy' => 'car', 'comfort' => 'car', 'loader' => 'car'];

    public static function route(float $fromLat, float $fromLng, float $toLat, float $toLng, string $vehicle = 'economy'): array
    {
        foreach ([$fromLat, $toLat] as $la) if ($la < 23 || $la > 37.5) throw new ApiError('Pickup or drop is outside Pakistan', 422);
        foreach ([$fromLng, $toLng] as $ln) if ($ln < 60 || $ln > 78) throw new ApiError('Pickup or drop is outside Pakistan', 422);
        $profile = self::PROFILE[$vehicle] ?? 'car';
        $key = sprintf('%s:%.4f,%.4f:%.4f,%.4f', $profile, $fromLat, $fromLng, $toLat, $toLng);
        $hit = Db::one('SELECT * FROM route_cache WHERE k = ? AND created_at >= ?', [$key, date('Y-m-d H:i:s', time() - 7 * 86400)]);
        if ($hit) return ['km' => (float) $hit['km'], 'minutes' => (int) $hit['minutes'], 'geometry' => $hit['geometry'], 'source' => $hit['source'], 'cached' => true];

        $r = self::osrm($profile, $fromLat, $fromLng, $toLat, $toLng) ?? self::straight($fromLat, $fromLng, $toLat, $toLng);
        Db::run('DELETE FROM route_cache WHERE k = ?', [$key]);
        Db::insert('route_cache', ['k' => $key, 'km' => $r['km'], 'minutes' => $r['minutes'], 'geometry' => $r['geometry'], 'source' => $r['source'], 'created_at' => now()]);
        return $r + ['cached' => false];
    }

    private static function osrm(string $profile, float $fLat, float $fLng, float $tLat, float $tLng): ?array
    {
        $base = rtrim((string) Settings::get('osrm_url', 'https://routing.openstreetmap.de/routed-{profile}'), '/');
        $base = str_replace('{profile}', $profile, $base);
        $url = sprintf('%s/route/v1/driving/%.6f,%.6f;%.6f,%.6f?overview=simplified&geometries=polyline', $base, $fLng, $fLat, $tLng, $tLat);
        $body = self::http($url);
        if ($body === null) return null;
        $j = json_decode($body, true);
        if (($j['code'] ?? '') !== 'Ok' || empty($j['routes'][0])) return null;
        $rt = $j['routes'][0];
        return ['km' => r2((float) $rt['distance'] / 1000), 'minutes' => (int) ceil((float) $rt['duration'] / 60), 'geometry' => (string) ($rt['geometry'] ?? ''), 'source' => 'osrm'];
    }

    /** Great-circle distance in km (no road factor). */
    public static function straightKm(float $fLat, float $fLng, float $tLat, float $tLng): float
    {
        $rad = M_PI / 180;
        $a = sin(($tLat - $fLat) * $rad / 2) ** 2 + cos($fLat * $rad) * cos($tLat * $rad) * sin(($tLng - $fLng) * $rad / 2) ** 2;
        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Fallback: great-circle distance × 1.35 road factor at 22 km/h city speed. */
    public static function straight(float $fLat, float $fLng, float $tLat, float $tLng): array
    {
        $rad = M_PI / 180;
        $a = sin(($tLat - $fLat) * $rad / 2) ** 2 + cos($fLat * $rad) * cos($tLat * $rad) * sin(($tLng - $fLng) * $rad / 2) ** 2;
        $km = 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)) * 1.35;
        $km = max(0.5, r2($km));
        return ['km' => $km, 'minutes' => (int) ceil($km / 22 * 60), 'geometry' => '', 'source' => 'estimate'];
    }

    private static function http(string $url): ?string
    {
        $fn = zd_config()['http'] ?? null;
        if (is_callable($fn)) return $fn($url);
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'header' => "User-Agent: ZarDrive/1.0 (+https://zardrive.zarrasha.com)\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }
}
