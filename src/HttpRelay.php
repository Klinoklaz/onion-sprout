<?php

namespace App;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

class HttpRelay extends Worker
{
    private const INIT_CURLOPT = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_AUTOREFERER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ACCEPT_ENCODING => '',
        CURLOPT_HEADER => true, // retrieve original response header
        // avoid reassemble of http2 response
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ];

    private const LOG_DEBUG = 0;
    private const LOG_INFO = 1;
    private const LOG_WARNING = 2;
    private const LOG_ERROR = 3;
    private const PROBE_INTERVAL = 2;
    private const INJECT_JS = __DIR__ . '/../inject.js';

    private int $logLevel;
    private string $log;
    private string $injectJs;
    private \CurlHandle $ch;
    private int $curlTimeout;
    private array $curlOpt = self::INIT_CURLOPT;
    private array $prefixRules;

    public function __construct(callable $config)
    {
        parent::__construct($config('LISTEN_ADDR'));
        $this->count = $config('WORKER_POOL', 10);
        $this->log = $config('LOG_FILE', 'php://stderr');
        $this->onMessage = [$this, 'onMessage'];
        $this->onWorkerStart = [$this, 'onWorkerStart'];

        $this->curlOpt = self::INIT_CURLOPT;
        if ($proxy = $config('PROXY_ADDR')) {
            $this->curlOpt[CURLOPT_PROXY] = $proxy;
            $this->curlOpt[CURLOPT_PROXYTYPE]
                = $config('PROXY_TYPE', CURLPROXY_SOCKS5_HOSTNAME);
            $this->curlOpt[CURLOPT_PROXYUSERPWD] = $config('PROXY_AUTH');
        }
        $this->curlTimeout = $config('REQUEST_TIMEOUT', 30);
        $this->curlOpt[CURLOPT_TIMEOUT] = $this->curlTimeout;

        $this->logLevel = $config('LOG_LEVEL', self::LOG_ERROR);
        $this->injectJs = file_get_contents(self::INJECT_JS);

        // ['hostname' => 'prefix']
        $this->prefixRules = json_decode($config('PREFIX_RULES'), true) ?: [];
        [, $workerHost] = $this->extractOriginAndHost($this->socketName);
        $this->prefixRules[$workerHost] = $this->socketName;
    }

    /**
     * Will run after pcntl_fork()
     *
     * @return void
     */
    public function onWorkerStart()
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, $this->curlOpt);
    }

    public function onMessage(TcpConnection $conn, Request $request)
    {
        $prefix = $this->prefixRules[$request->host()] ?? '';
        // unrecognized domain
        if (empty($prefix)) {
            $conn->close(new Response(400, [], 'Bad request'));
            return;
        }

        $targetUrl = $reqUri = substr($request->uri(), 1);
        @[$tOrigin, $tHost] = $this->extractOriginAndHost($targetUrl);
        // js import, css include etc., target only has path
        if (!$tHost) {
            $referer = $request->header('referer') ?? '';
            @[$tOrigin, $tHost] = $this->extract2ndOriginAndHost($referer);
            $targetUrl = $prefix . '/' . $tOrigin . '/' . $targetUrl;
        }
        if (!$tHost) {
            $conn->close(new Response(
                400, [], "Unable to parse url: $reqUri"));
            return;
        }
        // target is current server / url incomplete
        if (!empty($this->prefixRules[$tHost]) || !empty($referer)) {
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
                // auto decompression enabled
                case 'accept-encoding':
                    break;
                case 'referer':
                    $pLen = strlen($prefix);
                    if (substr($v, 0, $pLen) === $prefix) {
                        $v = substr($v, $pLen + 1);
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
        $conn->lastProbe = time();
        $cancel = fn(): int => $this->probeClient($conn);
        $curlOpt[CURLOPT_NOPROGRESS] = false;
        if (defined('CURLOPT_XFERINFOFUNCTION')) {
            $curlOpt[CURLOPT_XFERINFOFUNCTION] = $cancel;
        } else {
            // progress function isn't reliable with slow transmission
            $curlOpt[CURLOPT_LOW_SPEED_LIMIT] = 1;
            $curlOpt[CURLOPT_LOW_SPEED_TIME] = $this->curlTimeout / 2;
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
        $conn->close(new Response(502, [],
            'Network error, unable to get response from ' . $targetUrl));
        $this->internalLog($error . " ($targetUrl)");
    }

    /**
     * Detect client disconnect by sending empty tcp payload
     *
     * @param TcpConnection $conn
     * @return integer 1 => disconnected, 0 => alive
     */
    private function probeClient(TcpConnection $conn): int
    {
        if (in_array($conn->getStatus(), [
            $conn::STATUS_CLOSING,
            $conn::STATUS_CLOSED,
            $conn::STATUS_ENDING,
        ])) {
            return 1;
        }
        $now = time();
        if ($now - $conn->lastProbe < self::PROBE_INTERVAL) {
            return 0;
        }
        $conn->lastProbe = $now;
        $socket = $conn->getSocket();
        try {
            $res = @fwrite($socket, '');
        } catch (\Throwable $t) {
            $this->internalLog('Probing failed: '
                . $conn->getRemoteAddress() . ' ' . $t->getMessage());
        }
        return !isset($res) || $res === false || feof($socket) ? 1 : 0;
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
        $te = $headers['Transfer-Encoding'] ?? '';
        // data already assembled by curl
        // and doesn't contain end marker
        if (strcasecmp($te, 'chunked') === 0) {
            unset($headers['Transfer-Encoding']);
        }

        $statusCode = $hMatch[2] ?? 200;
        $body = substr($res, $hSize);
        $contentType = $headers['Content-Type'] ?? '';
        // alter js behavior
        if (preg_match('/(?:java|ecma)script/i', $contentType)) {
            $host = parse_url($urlPrefix, PHP_URL_HOST);
            $body = preg_replace([
                '/(["\'`])\s*(https?:\/\/.*?)\1/i',
                // hack known anti-embedding
                '/(\W)window\s*!=\s*top(\W)/',
                // hack domain guard
                // 127.0.0.1|localhost
                '/(["\'`])(?:MTI3LjAuMC4x|bG9jYWxob3N0)\1/',
            ], [
                '$1' . $urlPrefix . '/$2$1',
                '$1false$2',
                '$1' . base64_encode($host) . '$1',
            ], $body);
        } elseif (str_contains($contentType, 'text/html')) {
            $jsPattern = [
                '/https?:\/\/\w+/i', '/(\W)window\s*!=\s*top(\W)/'
            ];
            $jsReplace = ["$urlPrefix/$0", '$1false$2'];
            $body = preg_replace_callback_array([
                '/<script.*?>.*?<\/script>/is' =>
                    fn(array $m): string =>
                        preg_replace($jsPattern, $jsReplace, $m[0]),
                // any links inside a tag
                '/(<\w+\s+[^>]*?)(https?:\/\/.+?)>/i' =>
                    fn(array $m): string => "$m[1]$urlPrefix/$m[2]>",
            ], $body);

            // prevent conflict with wayback machine rewriter
            $urlPrefix = "'" . base64_encode($urlPrefix) . "'";
            $tOrigin = "'" . base64_encode($tOrigin) . "'";
            // inject
            $js = str_replace(
                ['MY_SERVER', 'ORIGIN'],
                [$urlPrefix, $tOrigin], $this->injectJs);
            $js = "\n<script>\n" . $js . "\n</script>\n";
            // can't use preg_replace here since
            // `$js` contains back reference strings
            if (preg_match('/<(?:head|body)(?:\s+[^>]+)?>/i',
                $body, $m, PREG_OFFSET_CAPTURE))
            {
                $injectPos = $m[0][1] + strlen($m[0][0]);
                $body = substr_replace($body, $js, $injectPos, 0);
            }
        }

        $encoding = $headers['Content-Encoding'] ?? '';
        if (strcasecmp($encoding, 'gzip') === 0
            && $gzBody = gzencode($body, 9)) {
            $body = $gzBody;
        } elseif (isset($gzBody)) {
            unset($headers['Content-Encoding']);
        }
        return new Response($statusCode, $headers, $body);
    }

    /**
     * Alternative to Worker::log()
     *
     * @param string $msg
     * @param int $level
     * @return void
     */
    private function internalLog(string $msg, int $level = self::LOG_ERROR)
    {
        if ($level < $this->logLevel) {
            return;
        }
        $l = ['DEBUG', 'INFO', 'WARNING', 'ERROR'][$level] ?? 'UNKNOWN';
        $entry = sprintf("[%s][%s] %s\n", date('Y-m-d H:i:s'), $l, $msg);
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