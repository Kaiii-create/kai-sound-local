<?php

namespace Sound;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class WebSocketHandler implements MessageComponentInterface
{
    private \SplObjectStorage $clients;
    private DeviceManager $deviceManager;
    private InternalBridge $bridge;
    private array $devices = [];

    public function __construct(DeviceManager $deviceManager, InternalBridge $bridge)
    {
        $this->clients       = new \SplObjectStorage();
        $this->deviceManager = $deviceManager;
        $this->bridge        = $bridge;
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        echo "[WS] New connection: #{$conn->resourceId}\n";

        $conn->send(json_encode([
            'type'    => 'init',
            'devices' => $this->devices,
        ], JSON_UNESCAPED_UNICODE));
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode($msg, true);
        if (!$data) return;

        $type = $data['type'] ?? '';

        switch ($type) {
            case 'ping':
                $from->send(json_encode(['type' => 'pong']));
                break;

            case 'command':
                // 转发指令到 MQTT Bridge
                $this->bridge->sendToServer([
                    'type'      => 'command',
                    'device_id' => $data['device_id'] ?? '',
                    'cmd'       => $data['cmd'] ?? '',
                    'params'    => $data['params'] ?? [],
                ]);
                echo "[WS] Command from #{$from->resourceId}: {$msg}\n";
                break;

            default:
                $from->send(json_encode([
                    'type'    => 'error',
                    'message' => 'Unknown message type: ' . $type,
                ]));
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->clients->detach($conn);
        echo "[WS] Connection closed: #{$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "[WS] Error: {$e->getMessage()}\n";
        $conn->close();
    }

    public function updateDevices(array $devices): void
    {
        $this->devices = $devices;
        $this->broadcastToAll([
            'type'    => 'update',
            'devices' => $devices,
        ]);
    }

    private function broadcastToAll(array $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        foreach ($this->clients as $client) {
            $client->send($payload);
        }
    }

    public function getClients(): \SplObjectStorage
    {
        return $this->clients;
    }
}
