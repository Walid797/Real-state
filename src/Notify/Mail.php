<?php
declare(strict_types=1);

namespace App\Notify;

use App\Settings;

/**
 * The look of every email: a plain, readable HTML version next to the text one.
 * No remote images, no tracking, no link shorteners — the things spam filters dislike.
 */
final class Mail
{
    /** Plain text → simple HTML: paragraphs kept, addresses turned into links. */
    public static function fromText(string $text): string
    {
        $out = '';
        foreach (preg_split("/\n{2,}/", trim($text)) ?: [] as $para) {
            $safe = nl2br(e($para), false);
            $safe = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:#0369a1">$1</a>', $safe) ?? $safe;
            $out .= '<p style="margin:0 0 14px">' . $safe . '</p>';
        }
        return $out;
    }

    public static function layout(string $title, string $body): string
    {
        $site = e((string) Settings::get('site_name', 'Plat'));
        $home = e(abs_url('/'));
        $manage = e(abs_url('/alerts'));
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . e($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f6f4ee">'
            . '<div style="max-width:600px;margin:0 auto;padding:24px 18px;font:15px/1.55 Arial,Helvetica,sans-serif;color:#18211f">'
            . '<p style="margin:0 0 18px;font:italic 600 22px Georgia,serif"><a href="' . $home . '" style="color:#18211f;text-decoration:none">' . $site . '</a></p>'
            . '<div style="background:#fffefb;border:1px solid #e0dcd0;border-radius:6px;padding:20px 22px">' . $body . '</div>'
            . '<p style="margin:16px 2px 0;font-size:12px;line-height:1.5;color:#66706b">You get this because you are on the ' . $site . ' team. '
            . '<a href="' . $manage . '" style="color:#66706b">Change or stop alerts</a>.</p>'
            . '</div></body></html>';
    }

    public static function textFooter(): string
    {
        $site = (string) Settings::get('site_name', 'Plat');
        return "\n\n--\nYou get this because you are on the $site team. Change or stop alerts: " . abs_url('/alerts');
    }
}
