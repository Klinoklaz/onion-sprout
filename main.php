<?php

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

const MY_SERVER = 'http://localhost:2345';
const CURL_PROXY = [
    CURLOPT_PROXY => '127.0.0.1:7891',
    CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
    // CURLOPT_PROXYUSERPWD => 'username:password',
];

$worker = new Worker('http://0.0.0.0:2345');
$worker->count = 10;
// $worker::$logFile = __DIR__ . '/log/workerman.log';
$worker::$logFile = '/dev/null';

// request handler
$worker->onMessage = function (TcpConnection $connection, Request $request) {
    $method = strtoupper($request->method()); // todo

    $url = substr($request->uri(), 1);
    $urlInfo = parse_url($url);
    // resolve js import etc.
    if (empty($urlInfo['host']) && $ref = $request->header('referer')) {
        if (preg_match('~^[^:]+://[^/]+/([^:]+://[^/]+)/~', $ref, $rMatch)) {
            $url = $rMatch[1]
                . ($url && $url[0] === '/' ? '' : '/') . $url;
            $urlInfo = parse_url($url);
        }
    }
    if (empty($urlInfo['host'])) {
        $connection->send("Could not parse url: $url");
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
    // current server name
    $myServer = MY_SERVER;
    if ($reqHost = $request->host()) {
        $myServer = strstr($myServer, '://', true) . '://' . $reqHost;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_AUTOREFERER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => $reqHeader,
        CURLOPT_HEADER => true, // retrieve original response header
        CURLOPT_RETURNTRANSFER => true,
    ]);
    if (CURL_PROXY) {
        curl_setopt_array($ch, CURL_PROXY);
    }

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
                '/\b(src|href)\s*=\s*([\'"])\s*(.+?)\2/i',
                function($match) use ($origin, $myServer) {
                    $target = $match[3] ?? '';
                    if (substr($target, 0, 1) === '/') {
                        $target = $origin . $target;
                    }
                    return "$match[1]=$match[2]"
                        . $myServer . '/' . $target . $match[2];
                },
                $resBody);
        }

        if (isset($resHeader['Content-Length'])) {
            $resHeader['Content-Length'] = strlen($resBody);
        }
        if (isset($resHeader['Access-Control-Allow-Origin'])) {
            $resHeader['Access-Control-Allow-Origin'] = $myServer;
        }
        $data = new Response($hMatch[2] ?? 200, $resHeader, $resBody);
    }

    curl_close($ch);
    $connection->send($data);
};

Worker::runAll();