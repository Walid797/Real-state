<?php
declare(strict_types=1);

namespace App\Notify;

use App\Db;
use App\Http;
use App\Settings;

/** Sends one message through any of: email, WhatsApp (CallMeBot), Telegram. */
final class Notifier
{
    public const CHANNELS = ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram'];

    /**
     * Where a message goes: a person's own email, Telegram chat (a group id works too) and WhatsApp.
     * Without a person, the first admin — the one who set the site up.
     * @return array{email:string, telegram:string, whatsapp_phone:string, whatsapp_key:string}
     */
    public static function recipient(?array $user = null): array
    {
        $user ??= Db::one("SELECT * FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1") ?? [];
        return [
            'email' => (string) (($user['email'] ?? '') ?: Settings::get('notify_email', '')),
            'telegram' => (string) (($user['telegram_chat'] ?? '') ?: Settings::get('telegram_chat', '')),
            'whatsapp_phone' => (string) ($user['whatsapp_phone'] ?? ''),
            'whatsapp_key' => (string) (Settings::unseal($user['callmebot_key'] ?? null) ?? ''),
        ];
    }

    /** @return array<string,bool> which channels can deliver to this recipient */
    public static function ready(?array $to = null): array
    {
        $to ??= self::recipient();
        return [
            'email' => ($to['email'] ?? '') !== '' && self::mailboxReady(),
            'whatsapp' => ($to['whatsapp_phone'] ?? '') !== '' && ($to['whatsapp_key'] ?? '') !== '',
            'telegram' => Settings::has('telegram_token') && ($to['telegram'] ?? '') !== '',
        ];
    }

    /** Email goes out only through a real mailbox: the server's bare mailer lands in spam and hurts the domain. */
    public static function mailboxReady(): bool
    {
        return (string) Settings::get('smtp_user', '') !== '' && Settings::has('smtp_pass');
    }

    /**
     * @param string[] $channels
     * @return array<string, array{ok:bool, detail:string}>
     */
    public static function send(string $subject, string $text, array $channels, ?string $html = null, ?array $to = null): array
    {
        $out = [];
        $to ??= self::recipient();
        $ready = self::ready($to);
        foreach (array_intersect(array_keys(self::CHANNELS), $channels) as $ch) {
            if (empty($ready[$ch])) {
                $out[$ch] = ['ok' => false, 'detail' => $ch === 'email' && !self::mailboxReady()
                    ? 'No mailbox set up — add one under Settings → Delivery.' : 'Not set up yet.'];
            } else {
                try {
                    $out[$ch] = match ($ch) {
                        'email' => self::email($to['email'], $subject, $text, $html),
                        'whatsapp' => self::whatsapp($to['whatsapp_phone'], $to['whatsapp_key'], $subject . "\n\n" . $text),
                        'telegram' => self::telegram($to['telegram'], $subject . "\n\n" . $text),
                    };
                } catch (\Throwable $e) {
                    $out[$ch] = ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 200)];
                }
            }
            Db::insert('notify_log', [
                'channel' => $ch, 'ok' => $out[$ch]['ok'] ? 1 : 0, 'subject' => mb_substr($subject, 0, 190),
                'detail' => mb_substr($out[$ch]['detail'], 0, 255), 'created_at' => now(),
            ]);
        }
        return $out;
    }

    private static function email(string $to, string $subject, string $text, ?string $html): array
    {
        $user = (string) Settings::get('smtp_user', '');
        $site = (string) Settings::get('site_name', 'Plat');
        $smtp = new Smtp(
            (string) Settings::get('smtp_host', 'smtp.hostinger.com'), Settings::int('smtp_port', 465),
            (string) Settings::get('smtp_secure', 'ssl'), $user, (string) Settings::get('smtp_pass')
        );
        $r = $smtp->send($user, $site . ' Alerts', $to, $subject, $text . Mail::textFooter(), Mail::layout($subject, $html ?? Mail::fromText($text)), [
            'Reply-To' => $user,
            'List-Unsubscribe' => '<' . abs_url('/alerts') . '>',
            'Auto-Submitted' => 'auto-generated',
        ]);
        return $r === true ? ['ok' => true, 'detail' => 'Sent to ' . $to] : ['ok' => false, 'detail' => (string) $r];
    }

    private static function whatsapp(string $phone, string $key, string $text): array
    {
        $phone = '+' . preg_replace('/\D/', '', $phone);
        $res = Http::get('https://api.callmebot.com/whatsapp.php?' . http_build_query([
            'phone' => $phone, 'text' => mb_substr($text, 0, 1400), 'apikey' => $key,
        ]), [], 25);
        $ok = $res['status'] === 200 && !preg_match('/error|invalid|not (found|allowed|activated)/i', $res['body']);
        return ['ok' => $ok, 'detail' => $ok ? 'Queued for ' . $phone : 'CallMeBot answered ' . $res['status'] . ': ' . mb_substr(trim(strip_tags($res['body'])), 0, 140)];
    }

    private static function telegram(string $chat, string $text): array
    {
        $res = Http::postForm('https://api.telegram.org/bot' . rawurlencode((string) Settings::get('telegram_token')) . '/sendMessage', [
            'chat_id' => $chat,
            'text' => mb_substr($text, 0, 3900),
            'disable_web_page_preview' => 'true',
        ], 20);
        $j = json_decode($res['body'], true);
        $ok = $res['status'] === 200 && !empty($j['ok']);
        return ['ok' => $ok, 'detail' => $ok ? 'Delivered' : 'Telegram answered ' . $res['status'] . ': ' . mb_substr((string) ($j['description'] ?? ''), 0, 140)];
    }
}
