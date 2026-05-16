<?php

namespace Sound;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class MqttService
{
    private MqttClient $client;
    private DeviceManager $deviceManager;
    private InternalBridge $bridge;
    private array $config;
    private bool $running = true;

    public function __construct(array $config, DeviceManager $deviceManager, InternalBridge $bridge)
    {
        $this->config = $config;
        $this->deviceManager = $deviceManager;
        $this->bridge = $bridge;

        $mqtt = $config['mqtt'];
        $this->client = new MqttClient($mqtt['host'], $mqtt['port'], $mqtt['client_id'], MqttClient::MQTT_3_1_1);
    }

    public function connect(): void
    {
        $mqtt = $this->config['mqtt'];

        $settings = (new ConnectionSettings())
            ->setKeepAliveInterval(60);

        if (!empty($mqtt['username'])) {
            $settings->setUsername($mqtt['username']);
            if (!empty($mqtt['password'])) {
                $settings->setPassword($mqtt['password']);
            }
        }

        $this->client->connect($settings, true);
        echo "[MQTT] Connected to broker at {$mqtt['host']}:{$mqtt['port']}\n";

        $this->client->subscribe($this->config['topics']['device_status'], function (string $topic, string $message) {
            $deviceId = $this->extractDeviceId($topic);
            $data = json_decode($message, true) ?: [];
            $this->deviceManager->updateStatus($deviceId, $data);
            $this->broadcastDevices();
            echo "[MQTT] Status from {$deviceId}: {$message}\n";
        }, 1);

        $this->client->subscribe($this->config['topics']['device_heartbeat'], function (string $topic, string $message) {
            $deviceId = $this->extractDeviceId($topic);
            $data = json_decode($message, true) ?: [];
            $this->deviceManager->updateHeartbeat($deviceId, $data);
            $this->broadcastDevices();
            echo "[MQTT] Heartbeat from {$deviceId}\n";
        }, 0);

        echo "[MQTT] Subscribed to device status and heartbeat topics.\n";
    }

    public function run(): void
    {
        $lastOfflineCheck = time();

        while ($this->running) {
            try {
                $this->client->loopOnce(microtime(true), true, 100000);
            } catch (\Throwable $e) {
                echo "[MQTT] Connection error: {$e->getMessage()}\n";
                echo "[MQTT] Reconnecting in 3 seconds...\n";
                sleep(3);

                try {
                    $this->client->connect(null, true);
                    echo "[MQTT] Reconnected.\n";
                } catch (\Throwable $e2) {
                    echo "[MQTT] Reconnect failed: {$e2->getMessage()}\n";
                }

                $lastOfflineCheck = time();
                continue;
            }

            $messages = $this->bridge->readFromClients();
            foreach ($messages as $msg) {
                if (($msg['type'] ?? '') === 'command') {
                    $this->sendCommand($msg['device_id'] ?? '', [
                        'cmd' => $msg['cmd'] ?? '',
                        'params' => $msg['params'] ?? [],
                    ]);
                }
            }

            $this->bridge->acceptClients();

            $now = time();
            if ($now - $lastOfflineCheck >= 10) {
                $this->deviceManager->checkOffline($this->config['device']['heartbeat_timeout']);
                $this->broadcastDevices();
                $lastOfflineCheck = $now;
            }
        }
    }

    public function sendCommand(string $deviceId, array $command): void
    {
        if ($deviceId === '') {
            return;
        }

        $topic = str_replace('{device_id}', $deviceId, $this->config['topics']['device_command']);
        $cmd = $command['cmd'] ?? '';
        $params = $command['params'] ?? [];
        $payloadArr = array_merge(['cmd' => $cmd], $params);
        $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);

        $this->client->publish($topic, $payload, 1);
        echo "[MQTT] Command to {$deviceId} on {$topic}: {$payload}\n";
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function disconnect(): void
    {
        $this->client->disconnect();
    }

    private function broadcastDevices(): void
    {
        $this->bridge->broadcastToClients([
            'type' => 'update',
            'devices' => $this->deviceManager->getAllDevices(),
        ]);
    }

    private function extractDeviceId(string $topic): string
    {
        $parts = explode('/', $topic);
        return $parts[1] ?? 'unknown';
    }
}
