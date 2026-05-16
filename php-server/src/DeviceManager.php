<?php

namespace Sound;

class DeviceManager
{
    /** @var array<string, array> */
    private array $devices = [];

    /** @var callable|null */
    private $onUpdate = null;

    public function setOnUpdate(callable $callback): void
    {
        $this->onUpdate = $callback;
    }

    public function updateStatus(string $deviceId, array $data): void
    {
        $data['device_id']   = $deviceId;
        $data['online']      = true;
        $data['last_update'] = time();
        $this->devices[$deviceId] = array_merge($this->devices[$deviceId] ?? [], $data);
        $this->notify();
    }

    public function updateHeartbeat(string $deviceId, array $data = []): void
    {
        $this->devices[$deviceId]['device_id']   = $deviceId;
        $this->devices[$deviceId]['online']      = true;
        $this->devices[$deviceId]['last_heartbeat'] = time();
        if ($data) {
            $this->devices[$deviceId] = array_merge($this->devices[$deviceId], $data);
        }
        $this->notify();
    }

    public function checkOffline(int $timeoutSeconds): void
    {
        $now = time();
        foreach ($this->devices as $deviceId => &$device) {
            $lastHb = $device['last_heartbeat'] ?? 0;
            $wasOnline = $device['online'] ?? false;
            $device['online'] = ($now - $lastHb) < $timeoutSeconds;
            if ($wasOnline && !$device['online']) {
                $this->notify();
            }
        }
    }

    public function getDevice(string $deviceId): ?array
    {
        return $this->devices[$deviceId] ?? null;
    }

    public function getAllDevices(): array
    {
        return array_values($this->devices);
    }

    private function notify(): void
    {
        if ($this->onUpdate) {
            call_user_func($this->onUpdate, $this->getAllDevices());
        }
    }
}
