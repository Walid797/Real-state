<?php
declare(strict_types=1);

namespace App\Providers;

use App\Config;
use App\Http;

/** Cesium ion: checks that a token can open Google's Photorealistic 3D Tiles (ion asset 2275207). */
final class CesiumIon
{
    public const GOOGLE_3D_ASSET = 2275207;

    /** @return array{ok:bool, why:string, unreachable?:bool} */
    public static function check(string $token): array
    {
        // Tokens limited to "Allowed URLs" are checked against the Origin, so say where we are.
        $origin = rtrim((string) Config::get('base_url', ''), '/');
        $res = Http::get('https://api.cesium.com/v1/assets/' . self::GOOGLE_3D_ASSET . '/endpoint', [
            'Authorization' => 'Bearer ' . $token,
            'Origin' => $origin,
            'Referer' => $origin . '/',
        ], 15);
        $json = json_decode($res['body'], true);
        $msg = is_array($json) ? (string) ($json['message'] ?? '') : '';
        return match (true) {
            $res['status'] === 200 && is_array($json) && (!empty($json['url']) || !empty($json['options'])) => ['ok' => true, 'why' => 'Google 3D tiles are available.'],
            $res['status'] === 0 || $res['status'] >= 500 => ['ok' => false, 'unreachable' => true, 'why' => 'Could not reach Cesium to check the token' . ($res['error'] ? ' (' . $res['error'] . ')' : '') . '.'],
            $res['status'] === 401 => ['ok' => false, 'why' => 'Cesium did not accept the token. Copy it again, whole.' . ($msg ? " ($msg)" : '')],
            $res['status'] === 403 => ['ok' => false, 'why' => 'The token can’t open Google’s 3D tiles. Add “Google Photorealistic 3D Tiles” to your assets in ion, and make sure this site’s address is in the token’s Allowed URLs.' . ($msg ? " ($msg)" : '')],
            $res['status'] === 404 => ['ok' => false, 'why' => 'Google Photorealistic 3D Tiles is not in your ion assets yet. Add it from the Asset Depot.'],
            default => ['ok' => false, 'why' => 'Cesium answered ' . $res['status'] . ($msg ? ": $msg" : '.')],
        };
    }
}
