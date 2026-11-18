<?php
declare(strict_types=1);

namespace JobCenter\Http;

/**
 * HTTP/1.1 transport over stream_socket_client. Does not require ext-curl or allow_url_fopen.
 * No automatic retries; redirects are not followed.
 */
final class StreamTransport implements Transport
{
    public function request(string $method, string $url, array $headers, string $body, float $timeoutSeconds): TransportResponse
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new TransportException('invalid request URL');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new TransportException('unsupported URL scheme');
        }
        $host = $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        $tcpHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $remote = ($scheme === 'https' ? 'ssl://' : 'tcp://') . $tcpHost . ':' . $port;
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $ctx,
        );
        if ($fp === false) {
            throw new TransportException('connection failed to ' . $tcpHost . ':' . $port);
        }

        try {
            stream_set_timeout($fp, self::timeoutSeconds($timeoutSeconds), self::timeoutMicroseconds($timeoutSeconds));
            $headerMap = [];
            foreach ($headers as $name => $value) {
                $headerMap[self::normalizeHeaderName((string) $name)] = $value;
            }
            $hostHeader = $host;
            if (!(($port === 80 && $scheme === 'http') || ($port === 443 && $scheme === 'https'))) {
                $hostHeader = $tcpHost . ':' . $port;
            }
            if (!isset($headerMap['Host'])) {
                $headerMap['Host'] = $hostHeader;
            }
            if (!isset($headerMap['Connection'])) {
                $headerMap['Connection'] = 'close';
            }
            if (!isset($headerMap['Accept'])) {
                $headerMap['Accept'] = 'application/json';
            }
            if ($body !== '' && !isset($headerMap['Content-Length'])) {
                $headerMap['Content-Length'] = (string) strlen($body);
            }

            $req = strtoupper($method) . ' ' . $path . " HTTP/1.1\r\n";
            foreach ($headerMap as $name => $value) {
                if (str_contains($name, "\r") || str_contains($name, "\n") || str_contains((string) $value, "\r") || str_contains((string) $value, "\n")) {
                    throw new TransportException('invalid header');
                }
                $req .= $name . ': ' . $value . "\r\n";
            }
            $req .= "\r\n" . $body;
            if (self::fwriteAll($fp, $req, $timeoutSeconds) !== strlen($req)) {
                throw new TransportException('failed to write HTTP request');
            }

            return self::readResponse($fp, $timeoutSeconds);
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param resource $fp
     */
    private static function readResponse($fp, float $timeoutSeconds): TransportResponse
    {
        $rawHeaders = '';
        while (true) {
            $line = self::readLine($fp, $timeoutSeconds);
            $rawHeaders .= $line;
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
        }
        $headerLines = preg_split("/\r\n|\n/", rtrim($rawHeaders, "\r\n")) ?: [];
        if ($headerLines === [] || $headerLines[0] === '') {
            throw new TransportException('empty HTTP response');
        }
        if (!preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $headerLines[0], $m)) {
            throw new TransportException('invalid HTTP status line');
        }
        $status = (int) $m[1];
        $headers = [];
        for ($i = 1; $i < count($headerLines); $i++) {
            $line = $headerLines[$i];
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            if ($name === '') {
                continue;
            }
            if (isset($headers[$name])) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }
        }

        $body = '';
        if (isset($headers['transfer-encoding']) && str_contains(strtolower($headers['transfer-encoding']), 'chunked')) {
            $body = self::readChunked($fp, $timeoutSeconds);
        } elseif (isset($headers['content-length']) && is_numeric($headers['content-length'])) {
            $len = (int) $headers['content-length'];
            if ($len < 0) {
                throw new TransportException('invalid Content-Length');
            }
            $body = self::readExact($fp, $len, $timeoutSeconds);
        } else {
            $body = self::readUntilEof($fp, $timeoutSeconds);
        }
        return new TransportResponse($status, $body, $headers);
    }

    /**
     * @param resource $fp
     */
    private static function readChunked($fp, float $timeoutSeconds): string
    {
        $body = '';
        while (true) {
            $line = trim(self::readLine($fp, $timeoutSeconds));
            $semi = strpos($line, ';');
            if ($semi !== false) {
                $line = substr($line, 0, $semi);
            }
            if ($line === '' || !ctype_xdigit($line)) {
                throw new TransportException('invalid chunk size');
            }
            $size = hexdec($line);
            if ($size === 0) {
                // trailing headers, if any
                while (true) {
                    $trail = self::readLine($fp, $timeoutSeconds);
                    if ($trail === "\r\n" || $trail === "\n" || $trail === '') {
                        break;
                    }
                }
                break;
            }
            $body .= self::readExact($fp, $size, $timeoutSeconds);
            $crlf = self::readExact($fp, 2, $timeoutSeconds);
            if ($crlf !== "\r\n") {
                throw new TransportException('invalid chunk terminator');
            }
        }
        return $body;
    }

    /**
     * @param resource $fp
     */
    private static function readLine($fp, float $timeoutSeconds): string
    {
        $line = '';
        while (!str_ends_with($line, "\n")) {
            $ch = self::readExact($fp, 1, $timeoutSeconds);
            $line .= $ch;
            if (strlen($line) > 65536) {
                throw new TransportException('HTTP header line too long');
            }
        }
        return $line;
    }

    /**
     * @param resource $fp
     */
    private static function readExact($fp, int $n, float $timeoutSeconds): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            if (feof($fp)) {
                throw new TransportException('unexpected end of HTTP response');
            }
            $chunk = fread($fp, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    throw new TransportException('HTTP read timed out');
                }
                if ($chunk === false || feof($fp)) {
                    throw new TransportException('unexpected end of HTTP response');
                }
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /**
     * @param resource $fp
     */
    private static function readUntilEof($fp, float $timeoutSeconds): string
    {
        $body = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === false) {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    throw new TransportException('HTTP read timed out');
                }
                throw new TransportException('HTTP read failed');
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    throw new TransportException('HTTP read timed out');
                }
                break;
            }
            $body .= $chunk;
        }
        return $body;
    }

    /**
     * @param resource $fp
     */
    private static function fwriteAll($fp, string $data, float $timeoutSeconds): int
    {
        $written = 0;
        $len = strlen($data);
        while ($written < $len) {
            $n = fwrite($fp, substr($data, $written));
            if ($n === false || $n === 0) {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    throw new TransportException('HTTP write timed out');
                }
                break;
            }
            $written += $n;
        }
        return $written;
    }

    private static function timeoutSeconds(float $timeout): int
    {
        return max(0, (int) floor($timeout));
    }

    private static function timeoutMicroseconds(float $timeout): int
    {
        $frac = $timeout - floor($timeout);
        return (int) round($frac * 1_000_000);
    }

    private static function normalizeHeaderName(string $name): string
    {
        $parts = explode('-', strtolower($name));
        $parts = array_map(static fn(string $p): string => $p === '' ? $p : ucfirst($p), $parts);
        return implode('-', $parts);
    }
}
