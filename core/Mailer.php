<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Envoi d'e-mails : SMTP intégré (STARTTLS / SSL, authentification) si configuré,
 * sinon mail() de l'hébergement. Aucune extension à installer.
 */
final class Mailer
{
    public static function toAdmins(string $subject, string $text, ?string $replyTo = null): bool
    {
        $ok = true;
        foreach (Db::col("SELECT email FROM users WHERE role = 'admin' AND status = 'active'") as $email) {
            $ok = self::send((string) $email, $subject, $text, $replyTo) && $ok;
        }
        return $ok;
    }

    public static function send(string $to, string $subject, string $text, ?string $replyTo = null): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $from = (string) (Settings::get('mail.from') ?: 'wordless@' . (parse_url(Config::baseUrl(), PHP_URL_HOST) ?: 'localhost'));
        $name = (string) Settings::get('site.name', 'Wordless');
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $headers = [
            'From' => self::encode($name) . ' <' . $from . '>',
            'Reply-To' => $replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : $from,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Mailer' => 'Wordless',
        ];
        $log = WL_DATA . '/logs/mail.log';
        if (getenv('WL_MAIL_LOG') === '1') {
            @file_put_contents($log, "--- " . gmdate('c') . " To: $to\nSubject: $subject\n\n$text\n", FILE_APPEND);
            return true;
        }
        $smtp = (array) Settings::get('mail.smtp', []);
        try {
            if (!empty($smtp['host'])) {
                return self::smtp($smtp, $from, $to, $subject, $text, $headers);
            }
            $h = '';
            foreach ($headers as $k => $v) {
                $h .= $k . ': ' . $v . "\r\n";
            }
            return @mail($to, self::encode($subject), $text, $h);
        } catch (\Throwable $e) {
            @file_put_contents($log, gmdate('c') . ' ERREUR ' . $e->getMessage() . "\n", FILE_APPEND);
            return false;
        }
    }

    private static function encode(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function smtp(array $cfg, string $from, string $to, string $subject, string $text, array $headers): bool
    {
        $host = (string) $cfg['host'];
        $port = (int) ($cfg['port'] ?? 587);
        $secure = (string) ($cfg['secure'] ?? ($port === 465 ? 'ssl' : 'tls'));
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
        if (!$fp) {
            throw new \RuntimeException("SMTP : connexion impossible ($errstr)");
        }
        stream_set_timeout($fp, 20);
        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (string $c, array $ok) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            $r = $read();
            if (!in_array((int) substr($r, 0, 3), $ok, true)) {
                throw new \RuntimeException('SMTP : ' . trim($r));
            }
            return $r;
        };
        $read();
        $ehlo = parse_url(Config::baseUrl(), PHP_URL_HOST) ?: 'localhost';
        $cmd('EHLO ' . $ehlo, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new \RuntimeException('SMTP : échec TLS');
            }
            $cmd('EHLO ' . $ehlo, [250]);
        }
        if (!empty($cfg['user'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string) $cfg['user']), [334]);
            $cmd(base64_encode((string) (Crypto::decrypt((string) ($cfg['pass'] ?? '')) ?? '')), [235]);
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $msg = 'To: <' . $to . ">\r\nSubject: " . self::encode($subject) . "\r\nDate: " . date(DATE_RFC2822) . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . '@' . $ehlo . ">\r\n";
        foreach ($headers as $k => $v) {
            $msg .= $k . ': ' . $v . "\r\n";
        }
        $body = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r", "\n"], "\r\n", $text)) ?? $text;
        $cmd($msg . "\r\n" . $body . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        fclose($fp);
        return true;
    }
}
