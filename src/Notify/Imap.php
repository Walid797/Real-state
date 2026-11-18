<?php
declare(strict_types=1);

namespace App\Notify;

/**
 * A small read-only IMAP client (RFC 3501 subset) over TLS — enough to read the messages that
 * arrived in the team's own mailbox since the last check. It never changes or deletes mail. No PHP imap extension needed.
 */
final class Imap
{
    /** @var resource */
    private $sock;
    private int $tag = 0;

    public function __construct(string $host, int $port, string $user, string $pass, int $timeout = 20)
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $s = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) {
            throw new \RuntimeException('Could not reach the mail server ' . $host . ':' . $port . ' (' . $errstr . ').');
        }
        stream_set_timeout($s, $timeout);
        $this->sock = $s;
        $greet = $this->line();
        if (!str_starts_with($greet, '* OK')) {
            throw new \RuntimeException('The mail server did not greet as expected.');
        }
        $r = $this->cmd('LOGIN ' . self::quote($user) . ' ' . self::quote($pass));
        if (!$r['ok']) {
            throw new \RuntimeException('The mail server refused the mailbox name or password.');
        }
    }

    public function __destruct()
    {
        if (is_resource($this->sock)) {
            @fwrite($this->sock, 'Z LOGOUT' . "\r\n");
            @fclose($this->sock);
        }
    }

    /**
     * Opens a folder (read-only) and lists the messages that arrived after $afterUid.
     * @return array{validity:int, uids:int[]} UIDs oldest first; validity changes if the server renumbered the folder
     */
    public function newSince(int $afterUid, string $folder = 'INBOX'): array
    {
        $r = $this->cmd('EXAMINE ' . self::quote($folder));
        if (!$r['ok']) {
            throw new \RuntimeException('Could not open the ' . $folder . ' folder.');
        }
        $validity = 0;
        foreach ($r['lines'] as $l) {
            if (preg_match('/UIDVALIDITY (\d+)/', $l, $m)) {
                $validity = (int) $m[1];
            }
        }
        $r = $this->cmd('UID SEARCH UID ' . ($afterUid + 1) . ':*');
        $uids = [];
        foreach ($r['lines'] as $l) {
            if (str_starts_with($l, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($l, 8))) ?: [] as $n) {
                    if (ctype_digit($n) && (int) $n > $afterUid) {
                        $uids[] = (int) $n; // "n:*" also returns the last message when nothing is newer
                    }
                }
            }
        }
        sort($uids);
        return ['validity' => $validity, 'uids' => $uids];
    }

    /** The raw message (first $max bytes), without marking it read. */
    public function fetch(int $uid, int $max = 600000): ?string
    {
        $r = $this->cmd('UID FETCH ' . $uid . ' (BODY.PEEK[]<0.' . $max . '>)');
        return $r['ok'] ? $r['literal'] : null;
    }

    /** @return array{ok:bool, lines:string[], literal:?string} */
    private function cmd(string $command): array
    {
        $tag = 'A' . (++$this->tag);
        fwrite($this->sock, $tag . ' ' . $command . "\r\n");
        $lines = [];
        $literal = null;
        while (true) {
            $l = $this->line();
            if (preg_match('/\{(\d+)\}$/', $l, $m)) {
                $want = (int) $m[1];
                $buf = '';
                while (strlen($buf) < $want) {
                    $chunk = fread($this->sock, min(65536, $want - strlen($buf)));
                    if ($chunk === false || $chunk === '') {
                        throw new \RuntimeException('The mail server stopped answering.');
                    }
                    $buf .= $chunk;
                }
                $literal ??= $buf;
                $lines[] = $l;
                continue;
            }
            if (str_starts_with($l, $tag . ' ')) {
                return ['ok' => str_starts_with($l, $tag . ' OK'), 'lines' => $lines, 'literal' => $literal];
            }
            $lines[] = $l;
        }
    }

    private function line(): string
    {
        $l = fgets($this->sock, 65536);
        if ($l === false) {
            throw new \RuntimeException('The mail server stopped answering.');
        }
        return rtrim($l, "\r\n");
    }

    private static function quote(string $s): string
    {
        return '"' . addcslashes($s, "\"\\") . '"';
    }
}
