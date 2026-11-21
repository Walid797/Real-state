<?php
declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Env;
use App\Http;

/**
 * Listing text in the site's language. English listing descriptions are translated once per
 * home and language and kept with the home, so each one costs a single translation.
 *
 * Provider: DeepL when DEEPL_API_KEY is in .env (free plan: 500,000 characters a month), otherwise
 * MyMemory's free public API (no key; about 5,000 characters a day, or 50,000 with an e-mail set
 * as TRANSLATE_EMAIL in .env). Machine translation: good enough to read, not to quote.
 */
final class Translate
{
    /** Site language → provider language code. */
    private const LANGS = ['fa' => 'fa'];

    /** The stored translation of a home's description, or null. */
    public static function stored(array $p, string $lang): ?string
    {
        $d = is_array($p['details'] ?? null) ? $p['details'] : (json_decode((string) ($p['details'] ?? ''), true) ?: []);
        $t = $d['description_' . $lang] ?? null;
        return is_array($t) && ($t['source_hash'] ?? '') === sha1((string) $p['description']) ? (string) $t['text'] : null;
    }

    /**
     * Translates and stores a home's description.
     * @return array{ok:bool, text?:string, error?:string, provider?:string}
     */
    public static function description(array $p, string $lang): array
    {
        if (!isset(self::LANGS[$lang]) || trim((string) $p['description']) === '') {
            return ['ok' => false, 'error' => t('Nothing to translate.')];
        }
        $have = self::stored($p, $lang);
        if ($have !== null) {
            return ['ok' => true, 'text' => $have];
        }
        $r = self::text((string) $p['description'], self::LANGS[$lang]);
        if (!$r['ok']) {
            return $r;
        }
        self::store((int) $p['id'], $lang, (string) $r['text'], (string) $r['provider'], (string) $p['description']);
        return $r;
    }

    /** Keeps a translation with the home (read, change, write: portable across MySQL and MariaDB). */
    public static function store(int $id, string $lang, string $text, string $provider, string $source): void
    {
        $d = json_decode((string) Db::val('SELECT details FROM properties WHERE id = ?', [$id]), true);
        $d = is_array($d) ? $d : [];
        $d['description_' . $lang] = ['text' => $text, 'provider' => $provider, 'source_hash' => sha1($source), 'at' => now()];
        Db::run('UPDATE properties SET details = ? WHERE id = ?', [json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $id]);
    }

    /** @return array{ok:bool, text?:string, provider?:string, error?:string} */
    public static function text(string $text, string $to, string $from = 'en'): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return ['ok' => true, 'text' => '', 'provider' => 'none'];
        }
        $deepl = Env::get('DEEPL_API_KEY');
        return $deepl ? self::deepl($text, $to, $deepl) : self::mymemory($text, $to, $from);
    }

    private static function deepl(string $text, string $to, string $key): array
    {
        // Free-plan keys end in ":fx" and use the api-free host.
        $host = str_ends_with($key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
        $res = Http::request('POST', $host . '/v2/translate', ['Authorization' => 'DeepL-Auth-Key ' . $key, 'Content-Type' => 'application/json'],
            (string) json_encode(['text' => [$text], 'target_lang' => strtoupper($to)]), 30);
        $d = json_decode((string) $res['body'], true);
        $out = $d['translations'][0]['text'] ?? null;
        return $res['status'] === 200 && is_string($out)
            ? ['ok' => true, 'text' => $out, 'provider' => 'DeepL']
            : ['ok' => false, 'error' => t('The translation service did not answer (%s).', (string) ($res['status'] ?: $res['error']))];
    }

    /** MyMemory takes up to 500 bytes per request, so the text goes sentence by sentence in pieces. */
    private static function mymemory(string $text, string $to, string $from): array
    {
        $out = [];
        foreach (self::pieces($text, 450) as $piece) {
            $q = ['q' => $piece, 'langpair' => $from . '|' . $to];
            if ($email = Env::get('TRANSLATE_EMAIL')) {
                $q['de'] = $email;
            }
            $res = Http::get('https://api.mymemory.translated.net/get?' . http_build_query($q), [], 20);
            $d = json_decode((string) $res['body'], true);
            $t = $d['responseData']['translatedText'] ?? null;
            $status = (int) ($d['responseStatus'] ?? $res['status']);
            if ($res['status'] !== 200 || $status !== 200 || !is_string($t)) {
                $why = $status === 429 || stripos((string) ($d['responseDetails'] ?? ''), 'LIMIT') !== false
                    ? t('The free translation allowance for today is used up; it comes back tomorrow.')
                    : t('The translation service did not answer (%s).', (string) ($res['status'] ?: $res['error']));
                return ['ok' => false, 'error' => $why];
            }
            $out[] = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return ['ok' => true, 'text' => implode(' ', $out), 'provider' => 'MyMemory'];
    }

    /** Splits at sentence ends (then spaces) into pieces of at most $max bytes. @return string[] */
    public static function pieces(string $text, int $max): array
    {
        $sentences = array_filter(preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [$text], static fn ($x) => trim($x) !== '');
        $pieces = [];
        $cur = '';
        foreach ($sentences as $s) {
            while (strlen($s) > $max) { // a very long sentence: cut at the last space that fits
                $cut = strrpos(substr($s, 0, $max), ' ') ?: $max;
                $pieces[] = trim(substr($s, 0, $cut));
                $s = trim(substr($s, $cut));
            }
            if ($cur !== '' && strlen($cur) + 1 + strlen($s) > $max) {
                $pieces[] = $cur;
                $cur = '';
            }
            $cur = $cur === '' ? $s : $cur . ' ' . $s;
        }
        if ($cur !== '') {
            $pieces[] = $cur;
        }
        return array_values(array_filter($pieces, 'strlen'));
    }
}
