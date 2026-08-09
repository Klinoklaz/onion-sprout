<?php

namespace App;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

class HttpRelay extends Worker {
    private const array INIT_CURLOPT = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_AUTOREFERER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true, // retrieve original response header
        // avoid reassemble of http2 response
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ];
    private const string INJECT_JS = __DIR__ . '/../inject.js';

    private bool $debug;
    private string $injectJs;
    private string $log;
    private \CurlHandle $ch;
    private array $prefixRules;

    public function __construct(callable $config)
    {
        parent::__construct($workerAddr = $config('LISTEN_ADDR'));
        $this->count = $config('WORKER_POOL', 10);
        $this->log = $config('LOG_FILE', 'php://stderr');
        $this->onMessage = [$this, 'onMessage'];

        $curlOpt = self::INIT_CURLOPT;
        if ($proxy = $config('PROXY_ADDR')) {
            $curlOpt[CURLOPT_PROXY] = $proxy;
            $curlOpt[CURLOPT_PROXYTYPE]
                = $config('PROXY_TYPE', CURLPROXY_SOCKS5_HOSTNAME);
            $curlOpt[CURLOPT_PROXYUSERPWD] = $config('PROXY_AUTH');
        }
        $curlOpt[CURLOPT_TIMEOUT] = $config('REQUEST_TIMEOUT', 30);
        $this->ch = curl_init();
        curl_setopt_array($this->ch, $curlOpt);

        $this->debug = !empty($config('DEBUG'));
        $this->injectJs = file_get_contents(self::INJECT_JS);

        // ['hostname' => 'prefix']
        $this->prefixRules = json_decode($config('PREFIX_RULES'), true) ?: [];
        $workerHost = preg_replace('~^[^:]+://~', '', $workerAddr);
        $this->prefixRules[$workerHost] = $workerAddr;
    }

    public function onMessage(TcpConnection $conn, Request $request)
    {
        $prefix = $this->prefixRules[$request->host()] ?? '';
        // unrecognized domain
        if (empty($prefix)) {
            $conn->close(new Response(400, [], 'Bad request'));
            return;
        }

        $targetUrl = substr($request->uri(), 1);
        @[$tOrigin, $tHost] = $this->extractOriginAndHost($targetUrl);
        // js import, css include etc., target only has path
        if (!$tHost) {
            @[$tOrigin, $tHost] = $this->extract2ndOriginAndHost(
                $request->header('referer') ?? '');
            $targetUrl = $tOrigin . '/' . $targetUrl;
        }
        if (!$tHost) {
            $conn->close(new Response(400, [],
                "Unable to parse url: $targetUrl"));
            return;
        }
        // target is current server
        if (!empty($this->prefixRules[$tHost])) {
            $conn->send(new Response(308, ['Location' => $targetUrl]));
            return;
        }
        $curlOpt = [CURLOPT_URL => $targetUrl];

        $reqHeader = [];
        foreach ($request->header() as $k => $v) {
            switch ($k) {
                case 'origin':
                    $reqHeader[] = 'Origin: ' . $tOrigin;
                    break;
                case 'host':
                    $reqHeader[] = 'Host: ' . $tHost;
                    break;
                case 'accept-encoding':
                    break; // disable compression
                case 'referer':
                    $preLen = strlen($prefix) + 1;
                    if (substr($v, 0, $preLen) === $prefix) {
                        $v = substr($v, $preLen);
                    }
                    $reqHeader[] = 'Referer: ' . $v;
                    break;
                default:
                    $reqHeader[] = $k . ': ' . $v;
            }
        }
        $curlOpt[CURLOPT_HTTPHEADER] = $reqHeader;
        $curlOpt[CURLOPT_NOBODY] = false;
        $curlOpt[CURLOPT_CUSTOMREQUEST] = null;
        $curlOpt[CURLOPT_POSTFIELDS] = null;

        $method = strtoupper($request->method());
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $curlOpt[CURLOPT_CUSTOMREQUEST] = $method;
            $curlOpt[CURLOPT_POSTFIELDS] = $request->rawBody();
        } elseif ($method === 'GET') {
            $curlOpt[CURLOPT_HTTPGET] = true;
        } elseif ($method === 'HEAD') {
            $curlOpt[CURLOPT_NOBODY] = true;
        } else {
            $curlOpt[CURLOPT_CUSTOMREQUEST] = $method;
        }

        // setup cancel function
        static $shouldCancel = [
            $conn::STATUS_CLOSING,
            $conn::STATUS_CLOSED,
            $conn::STATUS_ENDING,
        ];
        $cancel = fn(): int
            => in_array($conn->getStatus(), $shouldCancel) ? 1 : 0;
        $curlOpt[CURLOPT_NOPROGRESS] = false;
        if (defined('CURLOPT_XFERINFOFUNCTION')) {
            $curlOpt[CURLOPT_XFERINFOFUNCTION] = $cancel;
        } else {
            $curlOpt[CURLOPT_PROGRESSFUNCTION] = $cancel;
        }

        // do request
        curl_setopt_array($this->ch, $curlOpt);
        $res = curl_exec($this->ch);
        if ($res !== false) {
            $conn->send($this->modifyResponse($res, $prefix, $tOrigin));
            return;
        }

        $error = curl_error($this->ch);
        $res = $this->debug ? $error
            : 'Network error, unable to get response from ' . $targetUrl;
        $conn->close(new Response(502, [], $res));
        $this->logError($error . " ($targetUrl)");
    }

    private function modifyResponse(
        string $res, string $urlPrefix, string $tOrigin): Response
    {
        $hSize = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
        // deal with header accumulation caused by redirection
        $rawHeader = rtrim(substr($res, 0, $hSize));
        $lastEndLinePos = strrpos($rawHeader, "\r\n\r\n");
        preg_match('/\bHTTP\/([0-9.]+) (\d+) .*?\r\n((?s).*)/',
            substr($rawHeader, $lastEndLinePos, $hSize), $hMatch);
        // format headers into assoc array
        $headers = [];
        if (!empty($hMatch[3])) {
            foreach (explode("\r\n", $hMatch[3]) as $h) {
                @[$k, $v] = explode(':', $h, 2);
                if (!$k || !$v) {
                    continue;
                }
                // Title-Case, in consistant with workerman internals
                $k = ucwords(strtolower($k), '-');
                $headers[$k] = trim($v);
            }
        }

        $headers['Access-Control-Allow-Origin'] = $urlPrefix;
        unset(
            $headers['Content-Length'],
            $headers['Set-Cookie'],
            $headers['X-Frame-Options'], // allow embedding
            $headers['Content-Security-Policy'],
            $headers['Content-Security-Policy-Report-Only'],
        );
        // data already assembled by curl and doesn't contain end marker
        if (strtolower($headers['Transfer-Encoding'] ?? '') === 'chunked') {
            unset($headers['Transfer-Encoding']);
        }

        $statusCode = $hMatch[2] ?? 200;
        $body = substr($res, $hSize);
        // alter js behavior
        if (strpos($headers['Content-Type'] ?? '', 'text/html') !== false) {
            $js = str_replace(
                ['MY_SERVER', 'ORIGIN'],
                ["'$urlPrefix'", "'$tOrigin'"], $this->injectJs);
            $js = "\n<script>\n" . $js . "\n</script>\n";
            if (preg_match('/<(?:head|body)(?:\s+[^>]+)?>/i',
                $body, $m, PREG_OFFSET_CAPTURE))
            {
                $injectPos = $m[0][1] + strlen($m[0][0]);
                $body = substr($body, 0, $injectPos) . $js . substr($body, $injectPos);
            }
        }
        return new Response($statusCode, $headers, $body);
    }

    private function logError(string $msg)
    {
        $entry = sprintf("[%s][ERROR] %s\n", date('Y-m-d H:i:s'), $msg);
        file_put_contents($this->log, $entry, FILE_APPEND);
    }

    private function extractOriginAndHost(string $url): array
    {
        return preg_match('~^https?://([^/]+)~i', ltrim($url), $match)
            ? $match : [];
    }

    private function extract2ndOriginAndHost(string $url): array
    {
        return preg_match('~^https?://[^/]+/(https?://([^/]+))~i', ltrim($url), $match)
            ? [$match[1], $match[2]] : [];
    }
}