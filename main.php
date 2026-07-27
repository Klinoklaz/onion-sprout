<?php

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

const HOSTNAME = 'http://0.0.0.0:2345';

$worker = new Worker(HOSTNAME);
$worker->count = 10;
// $worker::$logFile = __DIR__ . '/log/workerman.log';
$worker::$logFile = '/dev/null';

// request handler
$worker->onMessage = function (TcpConnection $connection, Request $request) {
    $method = strtoupper($request->method()); // todo

    $url = substr($request->uri(), 1);
    $urlInfo = parse_url($url);
    if (empty($urlInfo['host'])) {
        $connection->send("Could not parse url: $url");
        return;
    }
    $origin = ($urlInfo['scheme'] ?? 'http') . '://'
        . $urlInfo['host']
        . (empty($urlInfo['port']) ? '' : ":$urlInfo[port]");

    $header = explode("\r\n", $request->rawHead());
    unset($header[0]); // request line
    foreach ($header as $i => &$h) {
        // mitigate cors error
        if (strtolower(substr($h, 0, 5)) === 'host:') {
            $h = 'host: ' . $urlInfo['host'];
        }
        // disable compression
        if (strtolower(substr($h, 0, 16)) === 'accept-encoding:') {
            unset($header[$i]);
        }
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($ch, CURLOPT_HEADER, true); // original response header
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

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
                function($match) use ($origin) {
                    $target = $match[3] ?? '';
                    if (substr($target, 0, 1) === '/') {
                        $target = $origin . $target;
                    }
                    return "$match[1]=$match[2]"
                        . HOSTNAME . '/' . $target . $match[2];
                },
                $resBody);
        }

        if (isset($resHeader['Content-Length'])) {
            $resHeader['Content-Length'] = strlen($resBody);
        }
        $data = new Response($hMatch[2] ?? 200, $resHeader, $resBody);
        // server doesn't support http/2
        // $data->withProtocolVersion($hMatch[1] ?? '1.1');
    }

    curl_close($ch);
    $connection->send($data);
};

Worker::runAll();