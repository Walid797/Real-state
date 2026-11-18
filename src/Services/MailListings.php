<?php
declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Http;
use App\Notify\Imap;
use App\Notify\MimeMessage;
use App\PropertyRepo;
use App\Settings;

/**
 * New homes from the listing e-mails the team already receives: saved-search alerts from
 * Zillow, Redfin, Homes.com, Realtor.com and others, and an agent's MLS auto-e-mails, sent
 * to the site's own mailbox. Each message is read once (read-only IMAP), the home links in
 * it are turned into addresses (LinkImport — never by opening the listing site), and the
 * price, beds, baths and size printed next to each link come along.
 *
 * Only mail from the listing sites below (or senders an admin adds) is read, and only when
 * the receiving server confirmed it really came from that domain.
 */
final class MailListings
{
    public const SENDERS = [
        'zillow.com' => 'Zillow', 'redfin.com' => 'Redfin', 'homes.com' => 'Homes.com', 'realtor.com' => 'Realtor.com',
        'trulia.com' => 'Trulia', 'movoto.com' => 'Movoto', 'compass.com' => 'Compass', 'brightmls.com' => 'Bright MLS',
        'mlsmatrix.com' => 'MLS (Matrix)', 'flexmls.com' => 'MLS (Flexmls)', 'showingtime.com' => 'ShowingTime',
        'remine.com' => 'Remine', 'auction.com' => 'Auction.com', 'xome.com' => 'Xome', 'hubzu.com' => 'Hubzu',
    ];

    /** Path shapes of a single home's page on each site (not search or area pages). */
    private const HOME_PAGE = '#(zillow\.com/homedetails/|redfin\.com/[A-Z]{2}/[^/]+/[^/]+/home/\d|homes\.com/property/|realtor\.com/realestateandhomes-detail/|trulia\.com/home/|movoto\.com/[^/]+/[^/]+/\d|compass\.com/listing/|auction\.com/details/|xome\.com/auctions/|hubzu\.com/property/)#i';

    private const PER_RUN = 25;
    private const PER_MESSAGE = 30;
    private const REDIRECTS_PER_MESSAGE = 40;

    public static function enabled(): bool
    {
        return Settings::get('mail_listings_on') === '1' && Settings::has('smtp_user') && Settings::has('smtp_pass');
    }

    /** Sender domain → site name, including the extra senders set under Settings. @return array<string,string> */
    public static function senders(): array
    {
        $out = self::SENDERS;
        foreach (preg_split('/[\s,;]+/', strtolower((string) Settings::get('mail_senders_extra', ''))) ?: [] as $d) {
            $d = trim($d, " .@\t");
            if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d)) {
                $out[$d] ??= $d;
            }
        }
        return $out;
    }

    public static function senderName(string $domain): ?string
    {
        foreach (self::senders() as $d => $name) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) {
                return $name;
            }
        }
        return null;
    }

    /** @return string[] one line per thing that happened */
    public static function run(float $budgetSeconds = 90): array
    {
        if (!self::enabled()) {
            return [];
        }
        $t = microtime(true);
        try {
            $imap = new Imap((string) Settings::get('imap_host', 'imap.hostinger.com'), Settings::int('imap_port', 993), (string) Settings::get('smtp_user'), (string) Settings::get('smtp_pass'));
            $last = Settings::int('mail_last_uid', 0);
            $box = $imap->newSince($last);
            if ((string) $box['validity'] !== (string) Settings::get('mail_uid_validity', '')) {
                // First run, or the server renumbered the folder: start with the most recent messages only.
                Settings::set('mail_uid_validity', (string) $box['validity']);
                if ($last > 0 || $box['uids']) {
                    $box = $imap->newSince(0);
                    $box['uids'] = array_slice($box['uids'], -10);
                }
            }
        } catch (\RuntimeException $e) {
            Settings::set('mail_last_error', $e->getMessage());
            return ['Listing e-mails: ' . $e->getMessage()];
        }
        Settings::set('mail_last_error', null);
        Settings::set('mail_checked_at', now());

        $log = [];
        $added = 0;
        foreach (array_slice($box['uids'], 0, self::PER_RUN) as $uid) {
            if (microtime(true) - $t > $budgetSeconds) {
                break;
            }
            $raw = $imap->fetch($uid);
            Settings::set('mail_last_uid', (string) $uid);
            if ($raw === null) {
                continue;
            }
            $r = self::handle(MimeMessage::parse($raw));
            if ($r !== null) {
                Db::insert('mail_log', [
                    'uid' => $uid, 'sender' => mb_substr($r['sender'], 0, 120), 'subject' => mb_substr($r['subject'], 0, 250),
                    'found' => $r['found'], 'added' => $r['added'], 'note' => mb_substr($r['note'], 0, 250), 'created_at' => now(),
                ]);
                $added += $r['added'];
            }
        }
        $n = count($box['uids']);
        if ($n) {
            $log[] = 'Listing e-mails: ' . plural(min($n, self::PER_RUN), 'message') . ' read, ' . plural($added, 'new home') . ' added.';
        }
        return $log;
    }

    /**
     * One message. Null when it is not from a listing sender (left alone, not logged).
     * @return array{sender:string, subject:string, found:int, added:int, note:string}|null
     */
    public static function handle(MimeMessage $m): ?array
    {
        $site = self::senderName($m->fromDomain);
        if ($site === null) {
            return null;
        }
        $base = ['sender' => $m->fromDomain, 'subject' => $m->subject, 'found' => 0, 'added' => 0];
        if ($m->verified() === false) {
            return $base + ['note' => 'Skipped: the mail server could not confirm it came from ' . $m->fromDomain . '.'];
        }
        $listings = self::listings($m->html !== '' ? $m->html : nl2br(htmlspecialchars($m->text)));
        $added = $known = $failed = 0;
        foreach (array_slice($listings, 0, self::PER_MESSAGE) as $l) {
            $r = self::import($l, $site);
            match ($r) {
                'added' => $added++,
                'known' => $known++,
                default => $failed++,
            };
        }
        $note = $listings
            ? plural($added, 'new home') . ($known ? ', ' . $known . ' already on file' : '') . ($failed ? ', ' . $failed . ' without a readable address' : '')
            : 'No home links in this message.';
        return ['sender' => $m->fromDomain, 'subject' => $m->subject, 'found' => count($listings), 'added' => $added, 'note' => $note];
    }

    /**
     * The homes in an e-mail body: each home-page link with the facts printed around it.
     * @return list<array{url:string, price:?int, beds:?float, baths:?float, sqft:?int, status:string}>
     */
    public static function listings(string $html): array
    {
        // Mark every link in place, then flatten the message to text so each link has the words near it.
        $urls = [];
        $marked = preg_replace_callback('/<a\s[^>]*?href\s*=\s*(["\'])(.*?)\1[^>]*>/is', static function ($m) use (&$urls) {
            $urls[] = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return ' [[L' . (count($urls) - 1) . ']] ';
        }, $html) ?? $html;
        $marked = preg_replace('#<(style|script|head)\b.*?</\1>#is', ' ', $marked) ?? $marked;
        $marked = preg_replace('#<(br|/p|/div|/td|/tr|/li|/h\d)\b[^>]*>#i', "\n", $marked) ?? $marked;
        $text = html_entity_decode(strip_tags($marked), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;

        // Resolve each link to a home page (unwrapping click-trackers), in order of appearance.
        $resolved = [];
        $redirects = 0;
        $isHome = static fn (string $u) => (bool) preg_match(self::HOME_PAGE, $u);
        foreach ($urls as $i => $u) {
            if (!preg_match('#^https?://#i', $u) || preg_match('/unsubscribe|preferences|privacy|terms|settings|optout|opt-out|mailto:/i', $u)) {
                continue;
            }
            if ($isHome($u)) {
                $resolved[$i] = $u;
            } elseif ($redirects < self::REDIRECTS_PER_MESSAGE && str_starts_with(strtolower($u), 'https://')) {
                $redirects++;
                $final = Http::finalUrl($u, $isHome);
                if ($final !== null && $isHome($final)) {
                    $resolved[$i] = $final;
                }
            }
        }

        // Group consecutive links to the same home; its facts are the words up to the next home's link.
        $homes = [];
        $order = [];
        foreach ($resolved as $i => $u) {
            $key = self::canonical($u);
            if (!isset($homes[$key])) {
                $homes[$key] = ['url' => $key, 'from' => $i];
                $order[] = $key;
            }
        }
        $out = [];
        foreach ($order as $n => $key) {
            $from = strpos($text, '[[L' . $homes[$key]['from'] . ']]');
            $nextKey = $order[$n + 1] ?? null;
            $to = $nextKey !== null ? strpos($text, '[[L' . $homes[$nextKey]['from'] . ']]') : false;
            $window = $from === false ? '' : substr($text, $from, ($to !== false && $to > $from ? $to : $from + 1500) - $from);
            $window = preg_replace('/\[\[L\d+\]\]/', ' ', $window) ?? $window;
            $out[] = ['url' => $key] + self::facts($window);
        }
        return $out;
    }

    /** Price, beds, baths, size and sale status from the words next to one home's link. */
    public static function facts(string $w): array
    {
        $price = null;
        if (preg_match_all('/\$\s?(\d{1,3}(?:,\d{3})+|\d{5,9})(?!\s*\/\s*mo)(\.\d+)?\s*([kKmM])?\b/', $w, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $v = (float) str_replace(',', '', $m[1]);
                $v *= match (strtolower($m[3] ?? '')) { 'k' => 1000, 'm' => 1000000, default => 1 };
                if ($v >= 50000 && $v <= 50000000) {
                    $price = (int) $v;
                    break;
                }
            }
        }
        if ($price === null && preg_match('/\$\s?(\d+(?:\.\d+)?)\s*([kKmM])\b/', $w, $m)) {
            $v = (float) $m[1] * (strtolower($m[2]) === 'm' ? 1000000 : 1000);
            $price = $v >= 50000 ? (int) $v : null;
        }
        $num = static fn (string $re) => preg_match($re, $w, $m) ? (float) str_replace(',', '', $m[1]) : null;
        $beds = $num('/(\d{1,2}(?:\.\d)?)\s*(?:bds?|beds?|bedrooms?|br)\b/i');
        $baths = $num('/(\d{1,2}(?:\.\d{1,2})?)\s*(?:ba|baths?|bathrooms?)\b/i');
        $sqft = $num('/(\d{1,2},?\d{3}|\d{3})\s*(?:sq\.?\s*ft\.?|sqft|square\s+feet)/i');
        $status = match (true) {
            (bool) preg_match('/\b(sold|closed)\b(?!\s+(?:in|within)\b)/i', $w) && !preg_match('/\b(for sale|new listing|just listed|price (?:cut|drop|reduced))\b/i', $w) => 'sold',
            (bool) preg_match('/\b(pending|under contract|contingent)\b/i', $w) => 'pending',
            default => 'for_sale',
        };
        return [
            'price' => $price, 'beds' => $beds !== null && $beds < 30 ? $beds : null, 'baths' => $baths !== null && $baths < 30 ? $baths : null,
            'sqft' => $sqft !== null && $sqft >= 200 && $sqft < 50000 ? (int) $sqft : null, 'status' => $status,
        ];
    }

    /** @return 'added'|'known'|'failed' */
    private static function import(array $l, string $site): string
    {
        if ($l['status'] === 'sold') {
            return 'failed'; // a sold notice: the price is not an asking price, and the sale shows up in county records
        }
        $r = LinkImport::resolve($l['url']);
        if (!$r['ok']) {
            return 'failed';
        }
        $res = PropertyRepo::upsert(array_filter([
            'address' => $r['address'], 'city' => $r['city'], 'state' => $r['state'], 'zip' => $r['zip'],
            'lat' => $r['lat'], 'lng' => $r['lng'], 'county' => $r['county'],
            'status' => $l['price'] ? $l['status'] : null, 'list_price' => $l['price'], 'listed_date' => $l['price'] ? today() : null,
            'beds' => $l['beds'], 'baths' => $l['baths'], 'sqft' => $l['sqft'],
            'source_url' => $l['url'], 'source_site' => $r['site'], 'data_status' => $l['price'] ? 'limited' : 'link_only',
        ], static fn ($v) => $v !== null), 'email');
        if ($res['created']) {
            Activity::log('added', $res['id'], 'From a ' . $site . ' e-mail');
        }
        $p = PropertyRepo::find($res['id']);
        if ($p && CountyRecords::wanted($p)) {
            CountyRecords::enrich($p);
        }
        Score::refresh($res['id']);
        return $res['created'] ? 'added' : 'known';
    }

    /** The home page without tracking parameters, so the same home is recognised across e-mails. */
    private static function canonical(string $u): string
    {
        $parts = parse_url($u);
        return 'https://' . strtolower((string) ($parts['host'] ?? '')) . rtrim((string) ($parts['path'] ?? ''), '/') . '/';
    }
}
