<?php
declare(strict_types=1);

namespace App\Notify;

/**
 * Small SMTP client (implicit TLS on 465, or STARTTLS on 587) with AUTH LOGIN.
 * Enough to send a plain-text + HTML message through a Hostinger mailbox
 * without pulling in a mail library.
 */
final class Smtp
{
    /** @var resource|null */
    private $sock = null;
    private string $log = '';

    public function __construct(
        private string $host,
        private int $port,
        private string $secure, // ssl | tls | none
        private string $user,
        private string $pass,
    ) {
    }

    /** @return true|string true, or a short reason it failed */
    /** @param array<string,string> $extra additional headers (Reply-To, List-Unsubscribe …) */
    public function send(string $from, string $fromName, string $to, string $subject, string $text, ?string $html = null, array $extra = []): bool|string
    {
        foreach ([$from, $to] as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                return 'Invalid email address: ' . $addr;
            }
        }
        try {
            $this->connect();
            $this->cmd('MAIL FROM:<' . $from . '>', 250);
            $this->cmd('RCPT TO:<' . $to . '>', 250);
            $this->cmd('DATA', 354);
            $this->write(self::dotStuff($this->message($from, $fromName, $to, $subject, $text, $html, $extra)) . "\r\n.");
            $this->expect(250);
            $this->write('QUIT');
            return true;
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        } finally {
            if (is_resource($this->sock)) {
                fclose($this->sock);
            }
            $this->sock = null;
        }
    }

    private function connect(): void
    {
        $target = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $sock = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new \RuntimeException('Could not connect to ' . $this->host . ':' . $this->port . ' (' . ($errstr ?: 'error ' . $errno) . ')');
        }
        $this->sock = $sock;
        stream_set_timeout($sock, 20);
        $this->expect(220);
        $ehlo = 'EHLO ' . (preg_replace('/[^a-z0-9.-]/i', '', (string) parse_url((string) \App\Config::get('base_url', ''), PHP_URL_HOST)) ?: 'localhost');
        $this->cmd($ehlo, 250);
        if ($this->secure === 'tls') {
            $this->cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new \RuntimeException('The mail server refused to start TLS.');
            }
            $this->cmd($ehlo, 250);
        }
        if ($this->user !== '') {
            $this->cmd('AUTH LOGIN', 334);
            $this->cmd(base64_encode($this->user), 334);
            try {
                $this->cmd(base64_encode($this->pass), 235);
            } catch (\RuntimeException) {
                throw new \RuntimeException('The mail server rejected the mailbox name or password.');
            }
        }
    }

    /** A line that starts with a dot would end the message early (RFC 5321 §4.5.2). */
    private static function dotStuff(string $data): string
    {
        return preg_replace('/^\./m', '..', $data) ?? $data;
    }

    public function message(string $from, string $fromName, string $to, string $subject, string $text, ?string $html, array $extra = []): string
    {
        $clean = static fn (string $s) => trim(preg_replace('/[\r\n]+/', ' ', $s) ?? '');
        $enc = static fn (string $s) => preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
        $host = substr(strrchr($from, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . $enc($clean($fromName)) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $enc($clean($subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
            'MIME-Version: 1.0',
        ];
        foreach ($extra as $name => $value) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9-]{1,40}$/', (string) $name) && $value !== '') {
                $headers[] = $name . ': ' . $clean((string) $value);
            }
        }
        $part = static fn (string $type, string $body) => "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
        if ($html === null) {
            return implode("\r\n", $headers) . "\r\n" . $part('text/plain', $text);
        }
        $b = 'b' . bin2hex(random_bytes(10));
        return implode("\r\n", $headers) . "\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n"
            . "--$b\r\n" . $part('text/plain', $text) . "\r\n--$b\r\n" . $part('text/html', $html) . "\r\n--$b--";
    }

    private function write(string $line): void
    {
        if (@fwrite($this->sock, $line . "\r\n") === false) {
            throw new \RuntimeException('Lost the connection to the mail server.');
        }
    }

    private function cmd(string $line, int $code): string
    {
        $this->write($line);
        return $this->expect($code);
    }

    private function expect(int $code): string
    {
        $resp = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $this->log .= $resp;
        if ((int) substr($resp, 0, 3) !== $code) {
            throw new \RuntimeException('Mail server said: ' . trim(mb_substr($resp, 0, 160)) ?: 'no reply');
        }
        return $resp;
    }
}
