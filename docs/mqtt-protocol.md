# MQTT 通信协议

## Broker

默认开发环境：

| 参数 | 默认值 |
| --- | --- |
| Host | `127.0.0.1` |
| Port | `1883` |
| Protocol | MQTT 3.1.1 |

ESP32 不能使用 `127.0.0.1` 连接电脑上的 Broker，应在固件中填写电脑或服务器的局域网 IP。

## Topic

### 设备状态

```text
device/{device_id}/status
```

方向：ESP32 -> Server

示例：

```json
{
  "device_id": "esp32_01",
  "online": true,
  "ip": "192.168.1.233",
  "wifi_ssid": "MyWiFi",
  "wifi_rssi": -55,
  "volume": 80,
  "play_state": "idle",
  "progress": 0,
  "current_text": "",
  "current_url": "",
  "last_error": "",
  "uptime_ms": 123456,
  "free_heap": 245760
}
```

### 设备心跳

```text
device/{device_id}/heartbeat
```

方向：ESP32 -> Server

示例：

```json
{
  "wifi_rssi": -55,
  "volume": 80,
  "play_state": "playing",
  "free_heap": 245760
}
```

### 控制命令

```text
device/{device_id}/command
```

方向：Server -> ESP32

## 命令

### 播放 URL

```json
{
  "cmd": "play",
  "url": "http://192.168.1.100:8000/1.mp3"
}
```

### 暂停

```json
{
  "cmd": "pause"
}
```

### 恢复

```json
{
  "cmd": "resume"
}
```

### 停止

```json
{
  "cmd": "stop"
}
```

### 音量

```json
{
  "cmd": "volume",
  "value": 80
}
```

### 测试音

```json
{
  "cmd": "tone",
  "freq": 1000,
  "duration": 1200
}
```

## 错误字段

ESP32 状态中的 `last_error` 可能包含：

| 值 | 说明 |
| --- | --- |
| `empty_url` | 播放 URL 为空 |
| `unsupported_https` | 当前固件不支持 HTTPS 音频 |
| `unsupported_m4a` | 当前固件不支持 M4A |
| `open_url_failed` | ESP32 无法打开音频 URL |
| `decoder_failed` | 解码器启动失败 |
| `tone_i2s_begin_failed` | 测试音 I2S 初始化失败 |
