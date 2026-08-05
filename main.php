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

$injectJs = file_get_contents('inject2.js');

// request handler
$worker->onMessage = function (TcpConnection $connection, Request $request) {
    global $rewriteRules;
    $prefix = $rewriteRules[$request->host()] ?? '';
    if (empty($prefix)) {
        $connection->close('Bad request');
        return;
    }

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

    $curlOptExt = [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $reqHeader,
    ];
    $method = strtoupper($request->method());
    if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
        $curlOptExt[CURLOPT_CUSTOMREQUEST] = $method;
        $curlOptExt[CURLOPT_POSTFIELDS] = $request->rawBody();
    } else if ($method === 'GET') {
        $curlOptExt[CURLOPT_HTTPGET] = true;
    } else if ($method === 'HEAD') {
        $curlOptExt[CURLOPT_NOBODY] = true;
    } else {
        $curlOptExt[CURLOPT_CUSTOMREQUEST] = $method;
    }
    global $ch;
    curl_setopt_array($ch, $curlOptExt);

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
        // alter js behavior — inject as early as possible in <head>
        if (strpos($resHeader['Content-Type'] ?? '', 'text/html') !== false) {
            global $injectJs;
            $js = str_replace(
                ['MY_SERVER', 'ORIGIN'],
                ["'$prefix'", "'$origin'"], $injectJs);
            $snippet = "\n<script>\n" . $js . "\n</script>\n";
            if (preg_match('/<head\b[^>]*>/i', $resBody, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1] + strlen($m[0][0]);
                $resBody = substr($resBody, 0, $pos) . $snippet . substr($resBody, $pos);
            } elseif (($tpos = stripos($resBody, '</title>')) !== false) {
                $resBody = substr($resBody, 0, $tpos + 8) . $snippet . substr($resBody, $tpos + 8);
            } elseif (preg_match('/<html\b[^>]*>/i', $resBody, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1] + strlen($m[0][0]);
                $resBody = substr($resBody, 0, $pos) . $snippet . substr($resBody, $pos);
            }
        }

        unset(
            $resHeader['Content-Length'],
            $resHeader['Set-Cookie'],
            $resHeader['X-Frame-Options'], // allow embedding
            $resHeader['Content-Security-Policy'],
            $resHeader['Content-Security-Policy-Report-Only'],
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

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => null,
        CURLOPT_NOBODY => false,
        CURLOPT_POSTFIELDS => null,
    ]);
};

Worker::runAll();