#include <WiFi.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <math.h>

#include <AudioFileSourceHTTPStream.h>
#include <AudioGeneratorMP3.h>
#include <AudioGeneratorWAV.h>
#include <AudioOutputI2S.h>

// ==================== Network ====================
const char* WIFI_SSID     = "YOUR_WIFI_SSID";
const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";

// The PHP service talks to ESP32 through MQTT.
const char* MQTT_BROKER = "192.168.1.100";
const int   MQTT_PORT   = 1883;
const char* DEVICE_ID   = "esp32_01";

// Optional TTS HTTP endpoint. If your PHP service only sends audio URLs,
// leave this as-is and send {"cmd":"play","url":"http://.../xxx.mp3"}.
const char* TTS_SERVER = "http://192.168.1.100:8080/tts.php";

// ==================== I2S Speaker (MAX98357A) ====================
#define I2S_DOUT 25
#define I2S_BCLK 27
#define I2S_LRC  26

// ==================== Timers ====================
const unsigned long MQTT_RECONNECT_INTERVAL_MS = 5000;
const unsigned long WIFI_CHECK_INTERVAL_MS     = 30000;
const unsigned long HEARTBEAT_INTERVAL_MS      = 10000;
const float PI_F = 3.14159265f;

// ==================== Audio ====================
AudioGeneratorMP3*         audioMP3  = nullptr;
AudioGeneratorWAV*         audioWAV  = nullptr;
AudioFileSourceHTTPStream* audioFile = nullptr;
AudioOutputI2S*            audioOut  = nullptr;

// ==================== State ====================
int    volume       = 80;
bool   audioPaused  = false;
String playState    = "idle";
String currentText  = "";
String currentUrl   = "";
String lastError    = "";

WiFiClient wifiClient;
PubSubClient mqtt(wifiClient);

String topicCmd       = "device/" + String(DEVICE_ID) + "/command";
String topicStatus    = "device/" + String(DEVICE_ID) + "/status";
String topicHeartbeat = "device/" + String(DEVICE_ID) + "/heartbeat";

unsigned long lastHeartbeat = 0;
unsigned long lastReconnect = 0;
unsigned long lastWifiCheck = 0;

void reportStatus();

// ==================== Audio Control ====================
void stopPlayback() {
  if (audioMP3) {
    audioMP3->stop();
    delete audioMP3;
    audioMP3 = nullptr;
  }

  if (audioWAV) {
    audioWAV->stop();
    delete audioWAV;
    audioWAV = nullptr;
  }

  if (audioFile) {
    delete audioFile;
    audioFile = nullptr;
  }

  if (audioOut) {
    delete audioOut;
    audioOut = nullptr;
  }

  audioPaused = false;
  playState   = "idle";
  currentUrl  = "";
}

bool playUrl(const char* url) {
  if (!url || strlen(url) == 0) {
    lastError = "empty_url";
    return false;
  }

  String urlStr = String(url);
  urlStr.toLowerCase();

  if (urlStr.startsWith("https://")) {
    Serial.printf("[Audio] Unsupported HTTPS URL: %s\n", url);
    Serial.println("[Audio] Use an http:// MP3/WAV URL, or proxy/convert it on the server.");
    lastError = "unsupported_https";
    return false;
  }

  if (urlStr.endsWith(".m4a") || urlStr.indexOf(".m4a?") >= 0) {
    Serial.printf("[Audio] Unsupported M4A URL: %s\n", url);
    Serial.println("[Audio] This sketch supports MP3 and WAV streams only.");
    lastError = "unsupported_m4a";
    return false;
  }

  stopPlayback();

  audioFile = new AudioFileSourceHTTPStream();
  if (!audioFile->open(url)) {
    Serial.printf("[Audio] Failed to open URL: %s\n", url);
    lastError = "open_url_failed";
    delete audioFile;
    audioFile = nullptr;
    return false;
  }

  audioOut = new AudioOutputI2S();
  audioOut->SetPinout(I2S_BCLK, I2S_LRC, I2S_DOUT);
  audioOut->SetGain((float)volume / 100.0f);

  bool started = false;
  if (urlStr.endsWith(".wav")) {
    audioWAV = new AudioGeneratorWAV();
    started = audioWAV->begin(audioFile, audioOut);
  } else {
    audioMP3 = new AudioGeneratorMP3();
    started = audioMP3->begin(audioFile, audioOut);
  }

  if (!started) {
    Serial.printf("[Audio] Decoder failed: %s\n", url);
    lastError = "decoder_failed";
    stopPlayback();
    return false;
  }

  audioPaused = false;
  playState   = "playing";
  currentUrl  = url;
  lastError   = "";
  Serial.printf("[Audio] Playing: %s\n", url);
  return true;
}

void setPaused(bool paused) {
  if (!audioOut || playState == "idle") {
    return;
  }

  audioPaused = paused;
  playState   = paused ? "paused" : "playing";
  audioOut->SetGain(paused ? 0.0f : (float)volume / 100.0f);
  Serial.printf("[Audio] %s\n", paused ? "Paused" : "Resumed");
}

void playTestTone(uint16_t freq = 1000, uint16_t durationMs = 1200) {
  stopPlayback();

  AudioOutputI2S testOut;
  testOut.SetPinout(I2S_BCLK, I2S_LRC, I2S_DOUT);
  testOut.SetRate(44100);
  testOut.SetChannels(2);
  testOut.SetGain((float)volume / 100.0f);

  if (!testOut.begin()) {
    Serial.println("[Audio] Test tone I2S begin failed");
    lastError = "tone_i2s_begin_failed";
    return;
  }

  const uint32_t sampleRate = 44100;
  const uint32_t totalSamples = (sampleRate * durationMs) / 1000;
  int16_t sample[2];

  Serial.println("[Audio] Playing test tone");
  for (uint32_t i = 0; i < totalSamples; i++) {
    int16_t v = (int16_t)(sin((2.0f * PI_F * freq * i) / sampleRate) * 12000);
    sample[0] = v;
    sample[1] = v;
    while (!testOut.ConsumeSample(sample)) {
      delay(1);
    }
  }

  testOut.flush();
  testOut.stop();
  playState = "idle";
  lastError = "";
  Serial.println("[Audio] Test tone done");
}

// ==================== WiFi ====================
void setupWiFi() {
  Serial.printf("[WiFi] Connecting to %s", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.persistent(false);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 40) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("\n[WiFi] Failed, restarting...");
    delay(2000);
    ESP.restart();
  }

  Serial.println("\n[WiFi] OK");
  Serial.printf("  IP  : %s\n", WiFi.localIP().toString().c_str());
  Serial.printf("  RSSI: %d dBm\n", WiFi.RSSI());
}

void checkWiFi(unsigned long now) {
  if (now - lastWifiCheck < WIFI_CHECK_INTERVAL_MS) {
    return;
  }
  lastWifiCheck = now;

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[WiFi] Lost, reconnecting...");
    WiFi.reconnect();
  }
}

// ==================== MQTT ====================
void publishOfflineStatus() {
  JsonDocument doc;
  doc["online"] = false;

  String payload;
  serializeJson(doc, payload);
  mqtt.publish(topicStatus.c_str(), payload.c_str(), true);
}

void connectMQTT() {
  if (WiFi.status() != WL_CONNECTED) {
    return;
  }

  Serial.printf("[MQTT] Connecting to %s:%d...\n", MQTT_BROKER, MQTT_PORT);

  JsonDocument willDoc;
  willDoc["online"] = false;
  String willPayload;
  serializeJson(willDoc, willPayload);

  bool ok = mqtt.connect(
    DEVICE_ID,
    topicStatus.c_str(),
    1,
    true,
    willPayload.c_str()
  );

  if (!ok) {
    Serial.printf("[MQTT] Failed, rc=%d\n", mqtt.state());
    lastReconnect = millis();
    return;
  }

  Serial.println("[MQTT] OK");
  mqtt.subscribe(topicCmd.c_str(), 1);
  Serial.printf("  Subscribed: %s\n", topicCmd.c_str());
  reportStatus();
}

void checkMQTT(unsigned long now) {
  if (WiFi.status() != WL_CONNECTED) {
    return;
  }

  if (!mqtt.connected()) {
    if (now - lastReconnect >= MQTT_RECONNECT_INTERVAL_MS) {
      lastReconnect = now;
      connectMQTT();
    }
    return;
  }

  mqtt.loop();
}

// ==================== Upload Status ====================
void reportStatus() {
  if (!mqtt.connected()) {
    return;
  }

  JsonDocument doc;
  doc["device_id"]    = DEVICE_ID;
  doc["online"]       = true;
  doc["ip"]           = WiFi.localIP().toString();
  doc["wifi_ssid"]    = WIFI_SSID;
  doc["wifi_rssi"]    = WiFi.RSSI();
  doc["volume"]       = volume;
  doc["play_state"]   = playState;
  doc["progress"]     = 0;
  doc["current_text"] = currentText;
  doc["current_url"]  = currentUrl;
  doc["last_error"]   = lastError;
  doc["uptime_ms"]    = millis();
  doc["free_heap"]    = ESP.getFreeHeap();

  String payload;
  serializeJson(doc, payload);
  mqtt.publish(topicStatus.c_str(), payload.c_str(), true);
  Serial.printf("[MQTT] Status -> %s\n", payload.c_str());
}

void sendHeartbeat() {
  if (!mqtt.connected()) {
    return;
  }

  JsonDocument doc;
  doc["wifi_rssi"]  = WiFi.RSSI();
  doc["volume"]     = volume;
  doc["play_state"] = playState;
  doc["free_heap"]  = ESP.getFreeHeap();

  String payload;
  serializeJson(doc, payload);
  mqtt.publish(topicHeartbeat.c_str(), payload.c_str());
}

// ==================== Helpers ====================
String urlEncode(const char* src) {
  String encoded = "";
  char hex[4];

  for (size_t i = 0; i < strlen(src); i++) {
    unsigned char c = (unsigned char)src[i];
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') {
      encoded += (char)c;
    } else if (c == ' ') {
      encoded += '+';
    } else {
      snprintf(hex, sizeof(hex), "%%%02X", c);
      encoded += hex;
    }
  }

  return encoded;
}

JsonObject commandParams(JsonDocument& doc) {
  if (doc["params"].is<JsonObject>()) {
    return doc["params"].as<JsonObject>();
  }
  return doc.as<JsonObject>();
}

// ==================== Command Handling ====================
void executeCommand(const char* cmd, JsonObject params) {
  Serial.printf("[Cmd] %s\n", cmd);

  if (strcmp(cmd, "play") == 0) {
    const char* text = params["text"] | "";
    const char* url  = params["url"]  | "";

    if (strlen(url) > 0) {
      currentText = params["text"] | "";
      playUrl(url);
    } else if (strlen(text) > 0) {
      String ttsUrl = String(TTS_SERVER) + "?text=" + urlEncode(text);
      currentText = text;
      playUrl(ttsUrl.c_str());
    } else if (audioPaused) {
      setPaused(false);
    }
  } else if (strcmp(cmd, "pause") == 0) {
    setPaused(true);
  } else if (strcmp(cmd, "resume") == 0) {
    setPaused(false);
  } else if (strcmp(cmd, "stop") == 0) {
    stopPlayback();
    currentText = "";
    Serial.println("[Cmd] Stopped");
  } else if (strcmp(cmd, "volume") == 0) {
    int val = params["value"] | volume;
    volume = constrain(val, 0, 100);
    if (audioOut && !audioPaused) {
      audioOut->SetGain((float)volume / 100.0f);
    }
    Serial.printf("[Cmd] Volume: %d\n", volume);
  } else if (strcmp(cmd, "tone") == 0) {
    int freq = params["freq"] | 1000;
    int duration = params["duration"] | 1200;
    playTestTone((uint16_t)freq, (uint16_t)duration);
  } else if (strcmp(cmd, "status") == 0) {
    // Explicit status refresh.
  } else {
    Serial.printf("[Cmd] Unknown: %s\n", cmd);
  }

  reportStatus();
}

void mqttCallback(char* topic, byte* payload, unsigned int length) {
  Serial.printf("[MQTT] %s -> %u bytes\n", topic, length);

  JsonDocument doc;
  DeserializationError err = deserializeJson(doc, payload, length);
  if (err) {
    Serial.printf("[MQTT] JSON error: %s\n", err.c_str());
    return;
  }

  const char* cmd = doc["cmd"] | "";
  if (strlen(cmd) == 0) {
    Serial.println("[MQTT] Missing cmd");
    return;
  }

  JsonObject params = commandParams(doc);
  executeCommand(cmd, params);
}

// ==================== Arduino ====================
void setup() {
  Serial.begin(115200);
  delay(200);

  Serial.println();
  Serial.println("============================");
  Serial.println("  ESP32 Sound Box");
  Serial.printf("  Device: %s\n", DEVICE_ID);
  Serial.printf("  I2S: DOUT=%d LRC=%d BCLK=%d\n", I2S_DOUT, I2S_LRC, I2S_BCLK);
  Serial.println("============================");

  setupWiFi();

  mqtt.setServer(MQTT_BROKER, MQTT_PORT);
  mqtt.setCallback(mqttCallback);
  mqtt.setKeepAlive(60);
  mqtt.setSocketTimeout(15);
  mqtt.setBufferSize(1024);

  connectMQTT();

  unsigned long now = millis();
  lastHeartbeat = now;
  lastWifiCheck = now;
}

void loop() {
  unsigned long now = millis();

  if (audioMP3 && audioMP3->isRunning()) {
    if (!audioMP3->loop()) {
      Serial.println("[Audio] MP3 done");
      stopPlayback();
      reportStatus();
    }
  }

  if (audioWAV && audioWAV->isRunning()) {
    if (!audioWAV->loop()) {
      Serial.println("[Audio] WAV done");
      stopPlayback();
      reportStatus();
    }
  }

  checkWiFi(now);
  checkMQTT(now);

  if (now - lastHeartbeat >= HEARTBEAT_INTERVAL_MS) {
    sendHeartbeat();
    lastHeartbeat = now;
  }
}
