<?php
declare(strict_types=1);

namespace App\Providers\County;

use App\Http;

/** Querying a public ArcGIS layer (POST, so long IN lists fit), with paging past the row cap. */
final class ArcGis
{
    /**
     * @param string $layerUrl full URL of the layer, ending in /MapServer/N or /FeatureServer/N
     * @return list<array> attribute rows, or whole features (attributes + geometry) when $features
     * @throws \RuntimeException when the server does not answer properly
     */
    public static function query(string $layerUrl, array $params, bool $features = false, bool $all = false, string $who = 'The county'): array
    {
        $out = [];
        $offset = 0;
        for ($page = 0; $page < 40; $page++) {
            $p = $params + ['f' => 'json', 'returnGeometry' => 'false', 'outFields' => '*'];
            if ($all) {
                $p['resultOffset'] = $offset;
                $p['resultRecordCount'] = $p['resultRecordCount'] ?? 1000;
            }
            $res = Http::postForm($layerUrl . '/query', $p, 25);
            $d = json_decode((string) $res['body'], true);
            if ($res['status'] !== 200 || !is_array($d) || isset($d['error'])) {
                throw new \RuntimeException($who . ' records did not answer (' . ($d['error']['message'] ?? ($res['error'] ?: 'HTTP ' . $res['status'])) . ').');
            }
            $rows = (array) ($d['features'] ?? []);
            foreach ($rows as $f) {
                $out[] = $features ? $f : ($f['attributes'] ?? []);
            }
            if (!$all || empty($d['exceededTransferLimit']) || !$rows) {
                break;
            }
            $offset += count($rows);
        }
        return $out;
    }

    /** A quoted SQL string literal for a where clause. */
    public static function str(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }

    /** "IN (...)" list of quoted strings. */
    public static function in(array $values): string
    {
        return '(' . implode(',', array_map([self::class, 'str'], array_map('strval', $values))) . ')';
    }
}
