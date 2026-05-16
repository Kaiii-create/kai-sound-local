<?php

require __DIR__ . '/vendor/autoload.php';

use Sound\DeviceManager;
use Sound\InternalBridge;
use Sound\MqttService;

$config = require __DIR__ . '/config.php';
$bridgePort = $config['internal_bridge']['port'];

echo "=== MQTT Bridge Process ===\n";

$deviceManager = new DeviceManager();
$bridge = new InternalBridge();
$bridge->startServer('127.0.0.1', $bridgePort);

$mqttService = new MqttService($config, $deviceManager, $bridge);
$connected = false;

while (!$connected) {
    try {
        $mqttService->connect();
        $connected = true;
    } catch (\Throwable $e) {
        echo "[MQTT] Failed to connect to broker: {$e->getMessage()}\n";
        echo "[MQTT] Retrying in 5 seconds...\n";

        $retryUntil = time() + 5;
        while (time() < $retryUntil) {
            $bridge->acceptClients();
            usleep(100000);
        }
    }
}

echo "[Bridge] Running...\n";

$mqttService->run();
$mqttService->disconnect();
$bridge->closeServer();
