<?php
declare(strict_types=1);

namespace App;

/** Minimal cURL client. Only ever called with fixed provider hosts — never with user-supplied URLs. */
final class Http
{
    public const UA = 'Plat/1.0 (personal real estate research)';

    /**
     * @return array{status:int, body:string, headers:array<string,string>, error:?string, ms:int}
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $respHeaders = [];
        // Plain http is allowed for one case only: a local test copy talking to a fake provider on this machine.
        $proto = str_starts_with($url, 'http://127.0.0.1:') && str_starts_with((string) Config::get('base_url', ''), 'http://127.0.0.1:')
            ? CURLPROTO_HTTP : CURLPROTO_HTTPS;
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => $proto,
            CURLOPT_REDIR_PROTOCOLS => $proto,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $t = microtime(true);
        $out = curl_exec($ch);
        $ms = (int) round((microtime(true) - $t) * 1000);
        $err = $out === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $out === false ? '' : (string) $out, 'headers' => $respHeaders, 'error' => $err, 'ms' => $ms];
    }

    public static function get(string $url, array $headers = [], int $timeout = 20): array
    {
        return self::request('GET', $url, $headers + ['Accept' => 'application/json'], null, $timeout);
    }

    public static function postForm(string $url, array $fields, int $timeout = 20): array
    {
        return self::request('POST', $url, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields), $timeout);
    }

    /**
     * Streams a large text file line by line without holding it in memory.
     * $onLine returns false to stop early.
     * @return array{status:int, error:?string, bytes:int}
     */
    public static function streamLines(string $url, callable $onLine, int $timeout = 300): array
    {
        $buffer = '';
        $bytes = 0;
        $stop = false;
        $proto = CURLPROTO_HTTPS;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => $proto,
            CURLOPT_REDIR_PROTOCOLS => $proto,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$bytes, &$stop, $onLine): int {
                $bytes += strlen($chunk);
                $buffer .= $chunk;
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $nl), "\r");
                    $buffer = substr($buffer, $nl + 1);
                    if ($onLine($line) === false) {
                        $stop = true;
                        return 0; // aborts the transfer
                    }
                }
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $err = curl_errno($ch) && !$stop ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!$stop && $buffer !== '') {
            $onLine(rtrim($buffer, "\r"));
        }
        return ['status' => $status, 'error' => $err, 'bytes' => $bytes];
    }

    /**
     * Where a link from an e-mail leads (newsletter click-trackers wrap listing links), without
     * downloading any page: follows up to $hops redirects by hand, https only, and refuses hosts
     * that resolve to private or reserved addresses, pinning the checked address for the request.
     * $stop says when a URL is already the destination. Returns the last URL reached, or null.
     */
    public static function finalUrl(string $url, callable $stop, int $hops = 4): ?string
    {
        for ($i = 0; $i <= $hops; $i++) {
            if ($stop($url)) {
                return $url;
            }
            $host = (string) parse_url($url, PHP_URL_HOST);
            if (!preg_match('#^https://#i', $url) || $host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
                return null;
            }
            $ip = gethostbyname($host);
            if ($ip === $host || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 10,
                CURLOPT_RESOLVE => [$host . ':443:' . $ip], CURLOPT_USERAGENT => self::UA,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RANGE => '0-0', // the redirect is in the headers; skip the page
            ]);
            curl_exec($ch);
            $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            if ($next === '') {
                return $url;
            }
            $url = $next;
        }
        return $stop($url) ? $url : null;
    }
}
