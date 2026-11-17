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

    /** @return array<string,bool> which channels are set up */
    public static function ready(): array
    {
        return [
            'email' => Settings::has('notify_email'),
            'whatsapp' => Settings::has('callmebot_phone') && Settings::has('callmebot_key'),
            'telegram' => Settings::has('telegram_token') && Settings::has('telegram_chat'),
        ];
    }

    /**
     * @param string[] $channels
     * @return array<string, array{ok:bool, detail:string}>
     */
    public static function send(string $subject, string $text, array $channels, ?string $html = null): array
    {
        $out = [];
        $ready = self::ready();
        foreach (array_intersect(array_keys(self::CHANNELS), $channels) as $ch) {
            if (empty($ready[$ch])) {
                $out[$ch] = ['ok' => false, 'detail' => 'Not set up yet.'];
            } else {
                try {
                    $out[$ch] = match ($ch) {
                        'email' => self::email($subject, $text, $html),
                        'whatsapp' => self::whatsapp($subject . "\n\n" . $text),
                        'telegram' => self::telegram($subject . "\n\n" . $text),
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

    private static function email(string $subject, string $text, ?string $html): array
    {
        $to = (string) Settings::get('notify_email');
        $user = (string) Settings::get('smtp_user', '');
        $name = (string) Settings::get('site_name', 'Plat');
        if ($user !== '' && Settings::has('smtp_pass')) {
            $smtp = new Smtp(
                (string) Settings::get('smtp_host'), Settings::int('smtp_port', 465),
                (string) Settings::get('smtp_secure', 'ssl'), $user, (string) Settings::get('smtp_pass')
            );
            $r = $smtp->send($user, $name, $to, $subject, $text, $html);
            return $r === true ? ['ok' => true, 'detail' => 'Sent to ' . $to] : ['ok' => false, 'detail' => (string) $r];
        }
        // No mailbox configured: fall back to the server's own mailer. Works, but Gmail often files it under spam.
        $host = parse_url((string) \App\Config::get('base_url', ''), PHP_URL_HOST) ?: 'localhost';
        $from = 'alerts@' . preg_replace('/^www\./', '', (string) $host);
        $headers = "From: $name <$from>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers, '-f' . $from);
        return $ok ? ['ok' => true, 'detail' => 'Handed to the server mailer for ' . $to] : ['ok' => false, 'detail' => 'The server mailer refused the message. Add a mailbox under Settings.'];
    }

    private static function whatsapp(string $text): array
    {
        $phone = '+' . preg_replace('/\D/', '', (string) Settings::get('callmebot_phone'));
        $res = Http::get('https://api.callmebot.com/whatsapp.php?' . http_build_query([
            'phone' => $phone, 'text' => mb_substr($text, 0, 1400), 'apikey' => (string) Settings::get('callmebot_key'),
        ]), [], 25);
        $ok = $res['status'] === 200 && !preg_match('/error|invalid|not (found|allowed|activated)/i', $res['body']);
        return ['ok' => $ok, 'detail' => $ok ? 'Queued for ' . $phone : 'CallMeBot answered ' . $res['status'] . ': ' . mb_substr(trim(strip_tags($res['body'])), 0, 140)];
    }

    private static function telegram(string $text): array
    {
        $res = Http::postForm('https://api.telegram.org/bot' . rawurlencode((string) Settings::get('telegram_token')) . '/sendMessage', [
            'chat_id' => (string) Settings::get('telegram_chat'),
            'text' => mb_substr($text, 0, 3900),
            'disable_web_page_preview' => 'true',
        ], 20);
        $j = json_decode($res['body'], true);
        $ok = $res['status'] === 200 && !empty($j['ok']);
        return ['ok' => $ok, 'detail' => $ok ? 'Delivered' : 'Telegram answered ' . $res['status'] . ': ' . mb_substr((string) ($j['description'] ?? ''), 0, 140)];
    }
}
