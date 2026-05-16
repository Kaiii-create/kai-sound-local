<?php

require __DIR__ . '/vendor/autoload.php';

use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\Socket\Server as ReactSocketServer;
use Sound\DeviceManager;
use Sound\InternalBridge;
use Sound\WebSocketHandler;

$config = require __DIR__ . '/config.php';

$wsHost = $config['websocket']['host'];
$wsPort = $config['websocket']['port'];
$bridgeHost = '127.0.0.1';
$bridgePort = $config['internal_bridge']['port'];

echo "=================================\n";
echo "  ESP32 Sound Box Server\n";
echo "  WebSocket: ws://{$wsHost}:{$wsPort}\n";
echo "=================================\n";

$phpBinary = PHP_BINARY;
$mqttScript = __DIR__ . DIRECTORY_SEPARATOR . 'mqtt_bridge.php';

$descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

echo "[Main] Starting MQTT Bridge process...\n";
$bridgeProcess = proc_open("\"{$phpBinary}\" \"{$mqttScript}\"", $descriptorspec, $pipes);
if (!is_resource($bridgeProcess)) {
    die("[Main] Failed to start MQTT Bridge process\n");
}

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

echo "[Main] Waiting for MQTT Bridge to start...\n";
sleep(1);

$bridge = new InternalBridge();
$retryCount = 0;
while (!$bridge->connectClient($bridgeHost, $bridgePort)) {
    $retryCount++;
    if ($retryCount > 10) {
        proc_terminate($bridgeProcess);
        die("[Main] Failed to connect to MQTT Bridge after 10 retries.\n");
    }
    echo "[Main] Retrying connection to MQTT Bridge... ({$retryCount}/10)\n";
    sleep(1);
}

$deviceManager = new DeviceManager();
$wsHandler = new WebSocketHandler($deviceManager, $bridge);
$loop = React\EventLoop\Loop::get();
$socket = new ReactSocketServer($wsHost . ':' . $wsPort, $loop);

$server = new IoServer(
    new HttpServer(new WsServer($wsHandler)),
    $socket,
    $loop
);

echo "[Main] WebSocket server started.\n";

$loop->addPeriodicTimer(0.2, function () use ($bridge, $wsHandler, &$pipes, &$bridgeProcess, $phpBinary, $mqttScript, $descriptorspec, $bridgeHost, $bridgePort) {
    $out = fread($pipes[1], 4096);
    if ($out !== false && $out !== '') {
        echo $out;
    }

    $err = fread($pipes[2], 4096);
    if ($err !== false && $err !== '') {
        echo $err;
    }

    $status = proc_get_status($bridgeProcess);
    if (!$status['running'] && $status['exitcode'] !== -1) {
        echo "[Main] MQTT Bridge process exited with code {$status['exitcode']}. Restarting...\n";
        proc_close($bridgeProcess);
        $bridgeProcess = proc_open("\"{$phpBinary}\" \"{$mqttScript}\"", $descriptorspec, $pipes);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        sleep(1);
        $bridge->connectClient($bridgeHost, $bridgePort);
        return;
    }

    while ($msg = $bridge->readFromServer()) {
        if (($msg['type'] ?? '') === 'update') {
            $wsHandler->updateDevices($msg['devices'] ?? []);
        }
    }
});

$cleanup = function () use (&$bridgeProcess, $bridge) {
    echo "\n[Main] Shutting down...\n";
    $bridge->closeClient();
    if (is_resource($bridgeProcess)) {
        proc_terminate($bridgeProcess);
        proc_close($bridgeProcess);
    }
};

register_shutdown_function($cleanup);

if (PHP_OS_FAMILY !== 'Windows') {
    $loop->addSignal(SIGINT, $cleanup);
    $loop->addSignal(SIGTERM, $cleanup);
}

$loop->run();
