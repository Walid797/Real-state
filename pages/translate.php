<?php
declare(strict_types=1);

use App\Locale;
use App\PropertyRepo;
use App\Services\Translate;

/*
 * POST /api/translate/{id} — the property page asks for this when the site is in a language other
 * than the listing's (English) and no translation is stored yet. Translated once, kept with the home.
 */

$p = PropertyRepo::find((int) $params['id']);
if (!$p || trim((string) $p['description']) === '') {
    json_out(['ok' => false, 'error' => t('Nothing to translate.')], 404);
}
$lang = Locale::language();
session_write_close();
@set_time_limit(60);
$r = Translate::description($p, $lang);
if (!$r['ok']) {
    $r['error'] ??= t('Couldn’t translate this.');
}
json_out($r);
