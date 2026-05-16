<?php

namespace Sound;

class InternalBridge
{
    private $serverSocket = null;
    private $clientSocket = null;
    private array $clients = [];
    private array $callbacks = [];

    // ==================== TCP Server 模式（MQTT Bridge 进程使用） ====================

    public function startServer(string $host, int $port): void
    {
        $this->serverSocket = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if (!$this->serverSocket) {
            throw new \RuntimeException("InternalBridge server failed: {$errstr} ({$errno})");
        }
        stream_set_blocking($this->serverSocket, false);
        echo "[Bridge] Internal TCP server listening on {$host}:{$port}\n";
    }

    public function acceptClients(): void
    {
        if (!$this->serverSocket) return;

        $conn = @stream_socket_accept($this->serverSocket, 0);
        if ($conn) {
            stream_set_blocking($conn, false);
            $this->clients[] = $conn;
            echo "[Bridge] New internal client connected\n";
        }
    }

    public function readFromClients(): array
    {
        $messages = [];
        foreach ($this->clients as $i => $client) {
            $data = fread($client, 8192);
            if ($data === false || $data === '') {
                if (feof($client)) {
                    fclose($client);
                    unset($this->clients[$i]);
                    echo "[Bridge] Internal client disconnected\n";
                }
                continue;
            }
            $decoded = json_decode($data, true);
            if ($decoded) {
                $messages[] = $decoded;
            }
        }
        return $messages;
    }

    public function broadcastToClients(array $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        foreach ($this->clients as $i => $client) {
            $written = @fwrite($client, $payload);
            if ($written === false) {
                fclose($client);
                unset($this->clients[$i]);
            }
        }
    }

    public function closeServer(): void
    {
        foreach ($this->clients as $client) {
            fclose($client);
        }
        if ($this->serverSocket) {
            fclose($this->serverSocket);
        }
    }

    // ==================== TCP Client 模式（WebSocket 进程使用） ====================

    public function connectClient(string $host, int $port): bool
    {
        $this->clientSocket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 2);
        if (!$this->clientSocket) {
            echo "[Bridge] Failed to connect to MQTT bridge: {$errstr} ({$errno})\n";
            return false;
        }
        stream_set_blocking($this->clientSocket, false);
        echo "[Bridge] Connected to MQTT bridge at {$host}:{$port}\n";
        return true;
    }

    public function sendToServer(array $data): void
    {
        if (!$this->clientSocket) return;
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        @fwrite($this->clientSocket, $payload);
    }

    public function readFromServer(): ?array
    {
        if (!$this->clientSocket) return null;
        $data = fread($this->clientSocket, 8192);
        if ($data === false || $data === '') {
            if (feof($this->clientSocket)) {
                fclose($this->clientSocket);
                $this->clientSocket = null;
                echo "[Bridge] Lost connection to MQTT bridge\n";
            }
            return null;
        }
        return json_decode(trim($data), true);
    }

    public function isClientConnected(): bool
    {
        return $this->clientSocket !== null;
    }

    public function closeClient(): void
    {
        if ($this->clientSocket) {
            fclose($this->clientSocket);
            $this->clientSocket = null;
        }
    }
}
