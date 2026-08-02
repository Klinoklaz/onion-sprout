<?php

use Symfony\Component\Dotenv\Dotenv;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

(new Dotenv())->overload(__DIR__ . '/.env');

function env(string $name, $default = null) {
    return $_ENV[strtoupper($name)] ?? $default;
}

$worker = new Worker($workerAddr = env('LISTEN_ADDR'));
$worker->count = env('WORKER_POOL', 10);
$worker::$logFile = env('LOG_FILE');

$rewriteRules = json_decode(env('REWRITE_RULES'), true);
$workerHost = preg_replace('~^[^:]+://~', '', $workerAddr);
$rewriteRules[$workerHost] = $workerAddr;

$curlOpt = [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_AUTOREFERER => true,
    CURLOPT_TIMEOUT => env('REQUEST_TIMEOUT', 30),
    // avoid reassemble of http2 response
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HEADER => true, // retrieve original response header
    CURLOPT_RETURNTRANSFER => true,
];
if ($proxy = env('PROXY_ADDR')) {
    $curlOpt[CURLOPT_PROXY] = $proxy;
    $curlOpt[CURLOPT_PROXYTYPE] = env('PROXY_TYPE', CURLPROXY_SOCKS5_HOSTNAME);
    $curlOpt[CURLOPT_PROXYUSERPWD] = env('PROXY_AUTH');
}
$ch = curl_init();
curl_setopt_array($ch, $curlOpt);

// request handler
$worker->onMessage = function (TcpConnection $connection, Request $request) {
    global $rewriteRules;
    $prefix = $rewriteRules[$request->host()] ?? '';
    if (empty($prefix)) {
        $connection->close('Bad request');
        return;
    }

    $method = strtoupper($request->method()); // todo

    $url = substr($request->uri(), 1);
    $urlInfo = parse_url($url);
    // resolve js import etc.
    if (empty($urlInfo['host']) && $ref = $request->header('referer')) {
        if (preg_match('~^[^:]+://[^/]+/([^:]+://[^/]+)~', $ref, $rMatch)) {
            $url = $rMatch[1]
                . ($url && $url[0] === '/' ? '' : '/') . $url;
            $urlInfo = parse_url($url);
        }
    }
    if (empty($urlInfo['host'])) {
        $connection->close("Could not parse url: $url");
        return;
    }

    $origin = ($urlInfo['scheme'] ?? 'http') . '://'
        . $urlInfo['host']
        . (empty($urlInfo['port']) ? '' : ":$urlInfo[port]");

    $reqHeader = explode("\r\n", $request->rawHead());
    unset($reqHeader[0]); // request line
    foreach ($reqHeader as $i => &$h) {
        $key = strtolower(strstr($h, ':', true));
        switch ($key) {
            case 'host': // mitigate 400 error
                $h = 'host: ' . $urlInfo['host'];
                break;
            case 'origin':
                $h = 'origin: ' . $origin;
                break;
            case 'accept-encoding': // disable compression
                unset($reqHeader[$i]);
                break;
        }
    }

    global $ch;
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $reqHeader,
    ]);

    $data = curl_exec($ch);
    if ($data === false) {
        $data = curl_error($ch);
    } else {
        $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // deal with header accumulation caused by redirection
        $allHeaders = rtrim(substr($data, 0, $hSize));
        $lastSep = strrpos($allHeaders, "\r\n\r\n");
        preg_match(
            '/\bHTTP\/([0-9.]+) (\d+) .*?\r\n((?s).*)/',
            substr($allHeaders, $lastSep, $hSize),
            $hMatch);
        // format headers into assoc array
        $resHeader = [];
        if (!empty($hMatch[3])) {
            foreach (explode("\r\n", $hMatch[3]) as $h) {
                @[$k, $v] = explode(':', $h, 2);
                if (!$k || !$v) {
                    continue;
                }
                // Title-Case, in consistant with workerman internals
                $k = ucwords(strtolower($k), '-');
                $resHeader[$k] = trim($v);
            }
        }

        $resBody = substr($data, $hSize);
        // point every url to current server
        if (strpos($resHeader['Content-Type'] ?? '', 'text/html') !== false) {
            $resBody = preg_replace_callback(
                '/\b(src|href)\s*=\s*([\'"])\s*(?!data:)(.+?)\2/i',
                function($match) use ($origin, $prefix) {
                    $target = $match[3] ?? '';
                    if (substr($target, 0, 1) === '/') {
                        $target = $origin . $target;
                    }
                    return "$match[1]=$match[2]"
                        . $prefix . '/' . $target . $match[2];
                },
                $resBody);
        }

        unset(
            $resHeader['Content-Length'],
            $resHeader['Set-Cookie'],
            $resHeader['X-Frame-Options'], // allow embedding
            $resHeader['Content-Security-Policy']
        );
        $newOrigin = preg_replace('~(?<!/)/(?!/).*$~', '', $prefix);
        $resHeader['Access-Control-Allow-Origin'] = $newOrigin;
        // data already assembled by curl and doesn't contain end marker
        if (strtolower($resHeader['Transfer-Encoding'] ?? '') === 'chunked') {
            unset($resHeader['Transfer-Encoding']);
        }
        $data = new Response($hMatch[2] ?? 200, $resHeader, $resBody);
    }

    $connection->send($data);
};

Worker::runAll();