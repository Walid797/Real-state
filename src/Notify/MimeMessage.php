<?php
declare(strict_types=1);

namespace App\Notify;

/**
 * Reads the parts of a raw e-mail that matter here: sender, subject, authentication
 * results, and the decoded text/html and text/plain bodies (UTF-8).
 */
final class MimeMessage
{
    public string $from = '';
    public string $fromDomain = '';
    public string $subject = '';
    public string $auth = '';
    public string $html = '';
    public string $text = '';

    public static function parse(string $raw): self
    {
        $m = new self();
        [$head, $body] = self::split($raw);
        $h = self::headers($head);
        $m->subject = self::decodeWords($h['subject'] ?? '');
        $m->from = self::decodeWords($h['from'] ?? '');
        if (preg_match('/<([^>]+@[^>]+)>/', $m->from, $x) || preg_match('/([^\s<>"]+@[^\s<>"]+)/', $m->from, $x)) {
            $m->fromDomain = strtolower(trim(substr($x[1], strrpos($x[1], '@') + 1), '> '));
        }
        $m->auth = strtolower($h['authentication-results'] ?? '');
        $m->walk($h, $body, 0);
        return $m;
    }

    /** Did the receiving server confirm the sender's domain (DMARC, or DKIM signed by that domain)? null = no verdict recorded. */
    public function verified(): ?bool
    {
        if ($this->auth === '') {
            return null;
        }
        if (str_contains($this->auth, 'dmarc=pass')) {
            return true;
        }
        if ($this->fromDomain !== '' && preg_match_all('/dkim=pass[^;]*header\.(?:d|i)=@?([a-z0-9.-]+)/', $this->auth, $mm)) {
            foreach ($mm[1] as $d) {
                if ($d === $this->fromDomain || str_ends_with($this->fromDomain, '.' . $d) || str_ends_with($d, '.' . $this->fromDomain)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function walk(array $h, string $body, int $depth): void
    {
        if ($depth > 6) {
            return;
        }
        $type = strtolower(trim(explode(';', $h['content-type'] ?? 'text/plain')[0]));
        if (str_starts_with($type, 'multipart/')) {
            if (!preg_match('/boundary="?([^";]+)"?/i', $h['content-type'] ?? '', $b)) {
                return;
            }
            $parts = explode('--' . $b[1], $body);
            array_shift($parts); // preamble
            foreach ($parts as $part) {
                if (str_starts_with($part, '--')) {
                    break; // closing boundary
                }
                [$ph, $pb] = self::split(ltrim($part, "\r\n"));
                $this->walk(self::headers($ph), $pb, $depth + 1);
            }
            return;
        }
        if (str_starts_with(strtolower($h['content-disposition'] ?? ''), 'attachment')) {
            return;
        }
        if ($type !== 'text/html' && $type !== 'text/plain') {
            return;
        }
        $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
        $data = match ($enc) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
        $charset = preg_match('/charset="?([\w.-]+)/i', $h['content-type'] ?? '', $c) ? strtoupper($c[1]) : 'UTF-8';
        if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            $conv = @mb_convert_encoding($data, 'UTF-8', $charset);
            $data = is_string($conv) ? $conv : $data;
        }
        if ($type === 'text/html') {
            $this->html .= $data;
        } else {
            $this->text .= $data;
        }
    }

    /** @return array{0:string, 1:string} */
    private static function split(string $raw): array
    {
        $p = preg_split('/\r?\n\r?\n/', $raw, 2) ?: [$raw];
        return [$p[0], $p[1] ?? ''];
    }

    /** @return array<string,string> lower-case name => unfolded value (first occurrence) */
    private static function headers(string $head): array
    {
        $out = [];
        $head = preg_replace('/\r?\n[ \t]+/', ' ', $head) ?? $head;
        foreach (preg_split('/\r?\n/', $head) ?: [] as $line) {
            $i = strpos($line, ':');
            if ($i === false) {
                continue;
            }
            $k = strtolower(trim(substr($line, 0, $i)));
            $out[$k] ??= trim(substr($line, $i + 1));
        }
        return $out;
    }

    private static function decodeWords(string $s): string
    {
        $d = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return is_string($d) ? $d : $s;
    }
}
