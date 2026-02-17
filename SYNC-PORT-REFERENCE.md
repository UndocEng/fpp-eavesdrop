# Sync Algorithm Porting Reference: listener-sync → eavesdrop

## Purpose
This document captures everything needed to port the adaptive PLL sync algorithm
from `fpp-listener-sync` (v2.4.0) into `fpp-eavesdrop`. Written to be consumed
by a fresh Claude session working in the eavesdrop repo.

## Source Repo
- `c:\Users\TNash\Documents\GitHub\fpp-listener-sync`
- Branch: `READY_SET_GO_Timing` (sync algorithm) / `Rework_Captive_Redirect` (v2.4.0 latest)
- Key file: `www/listen/index.html` — all sync JS is inline (~1180 lines, heavily commented)

## Target Repo
- `c:\Users\TNash\Documents\GitHub\fpp-eavesdrop`
- Key file: `www/listen/listen.html` — all sync JS is inline (~580 lines)
- Keep: admin UI (start/stop sequences, WiFi SSID/password), version display
- Replace: entire sync algorithm JS + add debug UI

## Scope
- Port the **full adaptive PLL sync algorithm** including debug UI
- Port the **WebSocket server** (`ws-sync-server.py`) and Apache proxy config
- Keep HTTP polling as fallback (eavesdrop already has this)
- Keep eavesdrop's admin features (sequence start/stop, WiFi settings)
- Owner-only for now, but design for future branch serving both owner + public listeners
- Deployment target: master Pi (FPP on localhost)

---

## What Eavesdrop Has Now (v3.1) — To Be Replaced

### Sync Constants (listen.html lines 148-154)
```javascript
const POLL_MS      = 250;    // → will use WS 100ms + HTTP 250ms fallback
const CAL_COUNT    = 6;      // → will use CAL_MIN_MS=800 (time-based, not count-based)
const Kp           = 0.3;    // → will use adaptive Kp: BASE=0.01, SCALE=4
const MAX_ADJ      = 0.05;   // → same
const HARD_SEEK_S  = 2.0;    // → same
const RATE_EMA     = 0.05;   // → same
const SEEK_COOLDOWN_MS = 2000; // → same
```

### What's Missing in Eavesdrop
1. **No settle period** — corrections start immediately, noisy
2. **No avg2s** — uses instantaneous phaseErr (noisy, causes oscillation)
3. **No adaptive Kp** — fixed Kp=0.3 (too aggressive when error is small)
4. **No log-compressed correction** — linear `Kp * err` (overshoots)
5. **No dead zone** — corrects even sub-ms jitter
6. **No MIN_RATE_CHANGE threshold** — updates rate every tick (micro-stutters)
7. **No rate update interval** — adjusts every 250ms tick instead of 500-1000ms
8. **No WebSocket transport** — HTTP polling only
9. **No debug UI** — no checkboxes, no debug box, no client log
10. **No server-side logging** — no sync.log
11. **No play-ahead latency measurement**
12. **baseRate clamp ±5%** — should be ±1%

---

## Listener-Sync Algorithm Constants (the good ones)

```javascript
// Transport
const WS_RECONNECT_MS = 2000;
const WS_PING_INTERVAL_MS = 1000;
const HTTP_POLL_MS = 250;

// Clock offset
const RTT_HISTORY_SIZE = 5;
const OFFSET_HISTORY_SIZE = 8;
const MIN_OFFSET_SAMPLES = 3;

// PLL
const CAL_MIN_MS = 800;        // minimum calibration window
const Kp_BASE = 0.01;          // base proportional gain
const Kp_SCALE = 4;            // adaptive scaling (Kp grows with error)
const MAX_ADJ = 0.05;          // ±5% rate clamp
const DEAD_ZONE_MS = 5;        // no correction below 5ms
const LOG_SCALE_MS = 100;      // log compression denominator
const HARD_SEEK_S = 2.0;       // hard seek threshold
const RATE_EMA = 0.05;         // baseRate learning alpha
const MIN_RATE_CHANGE = 0.003; // only update rate if Δ > 0.3%
const SEEK_COOLDOWN_MS = 2000; // between hard seeks

// Settle
const SETTLE_MS = 1500;        // 1.5s settle after play()

// Reporting
const REPORT_INTERVAL_MS = 1000;
const LOG_MAX = 200;
```

---

## PLL State Machine

### Phases: idle → calibrating → locked

**IDLE**: Waiting. Entry: track change, stop, pause, hard seek.

**CALIBRATING**: Collect (localMs, fppMs) pairs for ≥800ms with ≥6 points.
- Compute least-squares linear regression slope → baseRate
- Clamp baseRate to [0.99, 1.01] (reject garbage)
- NO snap-seek at lock (MP3 keyframe error causes overshoot)

**LOCKED**: Continuous adaptive correction.
- Error input: `avg2s` (2-second rolling average), NOT instantaneous
- Adaptive Kp: `Kp = Kp_BASE * (1 + Kp_SCALE * min(|avg2s|/200, 1))`
- Log-compressed: `rate = baseRate + sign(avg2s) * Kp * log1p((|avg2s| - DEAD_ZONE) / LOG_SCALE)`
- Rate interval: 500ms (error>50ms) or 1000ms (error<50ms)
- MIN_RATE_CHANGE threshold: only update if Δ > 0.003
- Rate learning: EMA α=0.05 every 2+ seconds
- Hard seek if error > 2s (with cooldown), stay locked

---

## Settle Period (1.5s after play())

During settle:
1. No PLL corrections
2. Measure play() startup latency (EWMA α=0.5, clamp [0,500ms], persist to localStorage)
3. Collect calibration samples

---

## Clock Offset (NTP-style)

```
offset = serverTs - clientSendTs - (RTT / 2)
```
- Sliding window of 8 samples
- Median filter → EWMA (70% old, 30% new)
- Require 3+ samples before trusting
- WS: burst 5 pings at 200ms on connect, then 1/sec
- HTTP: use request/response timing

---

## Error Calculation

```javascript
const targetMs = pos_ms + elapsed;  // where FPP should be NOW
const localSec = audio.currentTime;
const errMs = (targetMs/1000 - localSec) * 1000;
// positive = phone behind, negative = phone ahead

// 2-second rolling average (THE PLL INPUT):
errHistory2s.push({t: nowMs, err: errMs});
while (errHistory2s.length > 0 && (nowMs - errHistory2s[0].t) > 2000) errHistory2s.shift();
const avg2s = errHistory2s.reduce((a,b) => a + b.err, 0) / errHistory2s.length;
```

---

## Adaptive Kp + Log-Compressed Correction

```javascript
const absAvg2s = Math.abs(lastAvg2s);
const Kp = Kp_BASE * (1 + Kp_SCALE * Math.min(absAvg2s / 200, 1));
// Kp ranges: 0.01 (calm) → 0.05 (aggressive)

const rateInterval = absAvg2s > 50 ? 500 : 1000;

if ((nowMs - pll.lastRateUpdateMs) >= rateInterval) {
  pll.lastRateUpdateMs = nowMs;
  let rate = pll.baseRate;
  if (absAvg2s > DEAD_ZONE_MS) {
    rate = pll.baseRate + Math.sign(lastAvg2s) * Kp * Math.log1p((absAvg2s - DEAD_ZONE_MS) / LOG_SCALE_MS);
  }
  rate = clamp(rate, 1 - MAX_ADJ, 1 + MAX_ADJ);
  if (Math.abs(rate - audio.playbackRate) > MIN_RATE_CHANGE) {
    audio.playbackRate = rate;
  }
}
```

---

## WebSocket Server (ws-sync-server.py)

Python3 asyncio + websockets library (apt version 10.4 on Pi).
- Polls `http://127.0.0.1/api/fppd/status` every 100ms
- Broadcasts state to all clients: `{state, base, pos_ms, mp3_url, server_ms}`
- Handles ping/pong for clock offset
- Handles report messages → writes to `/home/fpp/listen-sync/sync.log`
- Log auto-clears on TRACK event, rotates at 5MB
- Port 8080, Apache proxies `/ws` to it via mod_proxy_wstunnel
- websockets 10.4 (apt): handler signature is `(websocket, path)`, no `max_queue` param

### Apache proxy config:
```apache
<IfModule mod_proxy.c>
    ProxyPass /ws ws://127.0.0.1:8080/
    ProxyPassReverse /ws ws://127.0.0.1:8080/
</IfModule>
```

### systemd service for ws-sync-server.py:
```ini
[Unit]
Description=FPP Listener Sync WebSocket Server
After=network.target

[Service]
Type=simple
User=fpp
ExecStart=/usr/bin/python3 /home/fpp/fpp-eavesdrop/ws-sync-server.py
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

---

## Debug UI

### Three checkboxes (all default OFF — overhead matters):
- **Debug** — shows debug box with: Transport, RTT, Clock Offset, Sequence, State, Target, Local, Error, PLL State, Rate, Avg Error (2s), Avg Error (all), Effective Rate, Buffered, Last Correction, Play Latency
- **Client Log** — on-screen scrollable monospace log (200 lines max), with Copy/Clear/Resume buttons. Auto-freezes on song end so user can review.
- **Server Log** — sends reports to server via WS → `/home/fpp/listen-sync/sync.log`

### Status indicators:
- "No show is currently broadcasting" (idle)
- "Now Playing: trackname" (playing)
- Enable Audio button (required for mobile autoplay policy)

---

## Server-Side Log Format

```
YYYY-MM-DD HH:MM:SS.mmm [client_ip] EVENT fpp=X target=Y local=Z err=Nms avg2s=Nms rate=R eff=E offset=Oms
```
Events: TRACK, INITIAL_SEEK, START, SYNC (throttled 1/sec), CORRECTION, STOP (once per transition)

---

## Key Differences: Eavesdrop vs Listener-Sync

| Aspect | Listener-Sync | Eavesdrop | Port Decision |
|--------|--------------|-----------|---------------|
| WiFi | Open (no password) | WPA2 protected | Keep eavesdrop's WPA2 |
| Admin UI | None | Start/stop sequences, WiFi config | Keep eavesdrop's admin |
| Network isolation | nftables, captive portal, .htaccess | Basic dnsmasq | Keep eavesdrop's simple approach |
| Deployment | Remote Pi | Master Pi | Keep eavesdrop's master approach |
| Audio source | `/music/` symlink | `/music/` symlink | Same |
| status.php | Has it | Has it (nearly identical) | Update to match listener-sync's |
| WebSocket | ws-sync-server.py + systemd | None | Add from listener-sync |
| Sync algorithm | Adaptive PLL (proven) | Basic PLL (oscillates) | Replace with listener-sync's |
| Debug UI | Full (3 checkboxes) | None | Add from listener-sync |
| Enable button | "Enable Audio" | Auto-unlock on tap | Use listener-sync's explicit button |

---

## Critical Lessons (carry forward — these were learned the hard way)

1. **Use avg2s as PLL error, not instantaneous** — prevents oscillation. Instantaneous is ±80ms noisy.
2. **No snap-seek at PLL lock** — MP3 keyframe granularity (~500ms) causes 250-300ms overshoot. Let PLL converge.
3. **baseRate clamp ±1%** — wide clamp (±5%) allows garbage slopes from noisy settle data
4. **MIN_RATE_CHANGE threshold (0.003)** — prevents micro-stutters from constant rate API calls
5. **Settle period (1.5s)** — audio element needs time to stabilize after seek + play
6. **Log compression (log1p)** — strong initial correction that tapers, prevents overshoot
7. **Adaptive Kp** — aggressive when far (Kp=0.05), gentle when close (Kp=0.01)
8. **FPP uses `milliseconds_elapsed`** not `seconds_played` — seconds_played is whole-second only
9. **PHP `round()` not `intval()`** — intval overflows at 2^31 on Pi 3B 32-bit PHP
10. **Gate all logging behind checkboxes** — JS execution overhead from debug UI updates affects sync
11. **STOP flooding** — server broadcasts stop every 100ms. Guard with `wasPlaying` transition flag.
12. **Burst pings on WS connect** — 5 pings at 200ms intervals for fast initial clock calibration
13. **websockets 10.4 (apt)** — handler needs `(websocket, path)` signature, no `max_queue` param
14. **Reentrance guard on sync()** — WS and HTTP can deliver concurrently, only process latest message

---

## Files to Create/Modify in Eavesdrop

### Modify:
- `www/listen/listen.html` — Replace sync JS, add debug UI HTML, keep admin UI (start/stop, WiFi settings)
- `www/listen/status.php` — Ensure format matches (already close, check `milliseconds_elapsed` and `round()`)
- `install.sh` — Add ws-sync-server.py deployment, systemd service, Apache proxy config, `a2enmod proxy proxy_wstunnel`

### Add:
- `ws-sync-server.py` — Copy from listener-sync, adjust paths (`/home/fpp/fpp-eavesdrop/` vs `/home/fpp/fpp-listener-sync/`)
- `server/ws-sync-server.service` — systemd unit for the WS server
- `config/apache-listener.conf` — Apache proxy config for `/ws`

### Keep as-is:
- `www/listen/admin.php` — Sequence control + WiFi management (works fine)
- `www/listen/version.php` — Version display (works fine)
- `www/music.php` — Audio streaming with range requests (works fine)
- `server/listener-ap.sh` — WiFi AP setup (works fine)
- `server/listener-ap.service` — WiFi AP systemd service (works fine)
- `config/listener.conf` — Environment overrides (works fine)

### Update:
- `uninstall.sh` — Also remove WS server service + config

---

## Reference: Full Source Files

To read the complete implementation, open these files in the listener-sync repo:
- **Sync algorithm + debug UI**: `c:\Users\TNash\Documents\GitHub\fpp-listener-sync\www\listen\index.html` (1188 lines)
- **WebSocket server**: `c:\Users\TNash\Documents\GitHub\fpp-listener-sync\ws-sync-server.py`
- **HTTP fallback**: `c:\Users\TNash\Documents\GitHub\fpp-listener-sync\www\listen\status.php`
- **Apache config**: `c:\Users\TNash\Documents\GitHub\fpp-listener-sync\config\apache-listener.conf`
- **Install script**: `c:\Users\TNash\Documents\GitHub\fpp-listener-sync\install.sh`

## Convergence Performance (proven results)
- **12-14 seconds** to converge from ~500ms initial MP3 seek error
- **5-25ms steady-state** avg2s error (imperceptible)
- **No overshoot**, no oscillation, no drift over long songs
- Tested on Samsung S24 Ultra, S25 Ultra, S21 (Android 15/16) and Apple devices
