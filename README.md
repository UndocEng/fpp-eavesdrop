# FPP Eavesdrop

A **show-owner developer tool** for Falcon Player (FPP). Runs on the master Pi and gives you full control: start/stop sequences and playlists, and hear synchronized show audio on your phone — all from one page.

> **Tested on FPP 9.4** (Raspberry Pi OS Bookworm). Should work on any FPP version that uses `/opt/fpp/www/` as its web root (FPP 6+).

> **This is NOT the visitor-facing listener.** For an open WiFi hotspot that lets your audience listen along, see [fpp-listener-sync](https://github.com/UndocEng/fpp-listener-sync) (runs on a remote). **Eavesdrop** runs on the master with direct API access.

---

## What This Does

1. Serves a control page where the show owner can start/stop playlists and sequences
2. Syncs show audio to the owner's phone using an adaptive PLL with WebSocket transport
3. Accessible from your show network — just navigate to `http://YOUR_FPP_IP/listen/`
4. Auto-detects when sequences start from any source (FPP web UI, scheduler, API) and begins playing

---

## Important: Must Be Installed on the Master

This must be installed on your **master** FPP (the one in **player mode**), not on a remote. It needs:

- Direct access to the music files in `/home/fpp/media/music/`
- The FPP API at `127.0.0.1` to read playback status and start/stop playlists
- Remotes don't have to store media locally — they only receive channel data from the master
- No seperate USB wifi adapter is needed

If your master controls the show, that's where this goes.

## What You Need

Before you start, make sure you have:

- A **Raspberry Pi** running **Falcon Player (FPP) 9.x in player (master) mode**
- Your FPP already has **sequences (.fseq files)** and matching **audio files (.mp3)** loaded
- A computer on the same network as your FPP to run the install commands

---

## Step-by-Step Install

### Step 1: Open a terminal to your FPP

You need to get a command line on your FPP. Pick one of these methods:

**Option A — SSH from your computer:**
- **Windows:** Open PowerShell or Command Prompt and type:
  ```
  ssh fpp@YOUR_FPP_IP
  ```
  Replace `YOUR_FPP_IP` with your FPP's IP address (for example `10.1.66.204`).
  When it asks for a password, type `falcon` and press Enter.

- **Mac/Linux:** Open Terminal and type the same command above.

**Option B — Use the FPP web interface:**
- Open your browser and go to `http://YOUR_FPP_IP/`
- Click on **Content Setup** in the menu
- Click **File Manager**
- (This method only works for uploading files — you'll still need SSH for the install command)

### Step 2: Download this project onto your FPP

Once you're logged in via SSH, type these commands one at a time (press Enter after each one):

```bash
cd /home/fpp
```

```bash
git clone https://github.com/UndocEng/fpp-eavesdrop.git
```

```bash
cd fpp-eavesdrop
```

### Step 3: Run the installer

```bash
sudo ./install.sh
```

You should see output like this:
```
=========================================
  FPP Eavesdrop - v3.2
=========================================

[install] Web root: /opt/fpp/www
[install] Deploying web files...
[install] Web files deployed
[install] Created /music symlink
[install] python3-websockets already installed
[install] ws-sync-server.py deployed
[install] ws-sync service installed and started
[install] Apache listener config deployed
[install] Apache restarted

[install] Running self-tests...
[test] ws-sync service: OK
[test] ws-sync port 8080: OK (HTTP 426 = WebSocket expected)

=========================================
  Install complete!
=========================================
  Page:  http://YOUR_FPP_IP/listen/
  Sync:  WebSocket (ws://YOUR_FPP_IP/ws)
=========================================
```

### Step 4: Test it

1. On your phone (connected to the same network as your FPP), open the browser
2. Go to `http://YOUR_FPP_IP/listen/`
3. You should see the Show Audio page with a Playback section at the top
4. Select a sequence from the dropdown and tap **Start** — your lights and audio should begin
5. Tap anywhere on the page to unlock audio (required by mobile browsers on first visit)

That's it! You're done.

---

## How to Use It

### Starting a show

1. Open the listen page at `http://YOUR_FPP_IP/listen/`
2. Pick a playlist or sequence from the dropdown
3. Tap **Start**
4. Audio will play through the phone speaker and lights will run on your display

### Auto-detection

The page automatically detects when a sequence is playing — even if started from FPP's web UI, the scheduler, or any other source. Just keep the page open and it will start syncing as soon as something plays.

### Stopping a show

Tap the **Stop** button.

### Debug info

Tap the **Debug** checkbox at the bottom of the sync card to see live diagnostics: transport type, clock offset, PLL state, error history, and playback rate. The **Client Log** checkbox shows a running log of sync events.

---

## Troubleshooting

### "The page loads but I hear no audio"

- On iPhone, check that the **ringer switch** (on the side of the phone) is not on silent
- Turn up the **volume** on the phone
- Tap anywhere on the page — mobile browsers require a user gesture before they'll play audio
- Check that you have `.mp3` files in `/home/fpp/media/music/` with the same name as your `.fseq` files (e.g. `Elvis.fseq` needs `Elvis.mp3`)

### "The sequence starts but the lights don't do anything"

This is an FPP output configuration issue, not a listener issue. Check:
- Go to your FPP web interface (`http://YOUR_FPP_IP/`)
- Click **Input/Output Setup** > **Channel Outputs**
- Make sure your output universes are **active** (checkbox enabled)
- Make sure the output IP addresses are correct (not your FPP's own IP)

### "Audio is out of sync"

- Enable the Debug checkbox and watch the PLL converge — it takes ~12-14 seconds after a track starts
- If error stays large, check that your Pi has the correct time (FPP usually handles this automatically)
- The **Avg Error (2s)** field should hover near 0 once locked — typical steady-state is 5-25ms

### "WebSocket not connecting"

- Check the ws-sync service: `sudo systemctl status ws-sync`
- View logs: `journalctl -u ws-sync -f`
- Test the port: `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/` — should return `426`
- The page will automatically fall back to HTTP polling if WebSocket is unavailable

---

## Updating

To get the latest version:

```bash
cd /home/fpp/fpp-eavesdrop
git pull
sudo ./install.sh
```

---

## Uninstall

To completely remove everything this project installed:

```bash
cd /home/fpp/fpp-eavesdrop
sudo ./uninstall.sh
```

This removes:
- All web files from `/opt/fpp/www/listen/`
- The `/music` symlink
- The `ws-sync` WebSocket service (stopped and disabled)
- The Apache WebSocket proxy configuration
- The config directory at `/home/fpp/listen-sync/`

After uninstalling, your FPP is exactly as it was before. You can then delete the project folder:

```bash
rm -rf /home/fpp/fpp-eavesdrop
```

---

## Files

| File | What it does |
|------|-------------|
| `www/listen/listen.html` | Main page — playback controls, audio sync, debug UI |
| `www/listen/index.html` | Redirects to listen.html |
| `www/listen/status.php` | Returns current FPP playback status as JSON |
| `www/listen/admin.php` | Handles start/stop commands |
| `www/listen/version.php` | Returns version info |
| `www/listen/logo.png` | Undocumented Engineer logo |
| `server/ws-sync-server.py` | Python WebSocket server — bridges FPP status to clients at 100ms |
| `config/ws-sync.service` | Systemd service for the WebSocket server |
| `config/apache-listener.conf` | Apache config — proxies `/ws` to the WebSocket server |
| `install.sh` | Installs everything |
| `uninstall.sh` | Removes everything (restores FPP to original state) |

---

## How Audio Sync Works

Eavesdrop uses an **adaptive Phase-Locked Loop (PLL)** to keep the phone's audio in sync with FPP's sequence playback. Instead of repeatedly jumping to the correct position (which causes audible pops), it smoothly adjusts the playback speed to converge on FPP's position and stay locked.

### Transport

The WebSocket server (`ws-sync-server.py`) polls FPP's API every 100ms and broadcasts state to all connected clients. The browser connects via WebSocket (proxied through Apache on port 80 at `/ws`), with automatic HTTP polling fallback if WebSocket is unavailable.

NTP-style clock offset estimation uses ping/pong round-trips through the WebSocket, with a median filter + EWMA for stable offset calculation.

### The PLL Algorithm

The sync engine runs through three phases:

**1. Start** (first message after a track begins)
- Preloads the audio file and waits for metadata
- Seeks to FPP's current position
- Starts playback, enters 1.5-second settle period

**2. Calibrate** (~800ms minimum, 6+ samples)
- Collects `{local_time, fpp_position}` pairs
- Computes a **least-squares linear regression** to find the rate ratio between FPP's clock and the phone's clock
- Clamps the base rate to +/-1% (rejects garbage calibration)

**3. Locked** (ongoing)
- Computes phase error: `fpp_position - audio.currentTime`
- Maintains a **2-second rolling average** (avg2s) as PLL input — prevents oscillation from instantaneous noise
- **Adaptive gain**: `Kp = 0.01 * (1 + 4 * min(|avg2s|/200, 1))` — gentle when close (0.01), aggressive when far (0.05)
- **Log-compressed correction**: `rate = baseRate + sign(avg2s) * Kp * log1p((|avg2s| - deadZone) / 100)`
- **Dead zone**: no correction when error < 5ms
- **Rate learning**: EMA (alpha=0.05) from 2-second observation windows, so corrections shrink as the true clock relationship is learned
- **Hard seek fallback**: if error exceeds 2 seconds, seeks directly (with 2-second cooldown)

### Result

After ~12-14 seconds (settle + calibration), the phone stays locked to FPP's position with 5-25ms steady-state error. The debug display shows live PLL state, error history, and playback rate.

---

## Technical Details (for developers)

- **WebSocket transport**: Python asyncio server polls FPP every 100ms, broadcasts `{state, base, pos_ms, mp3_url, server_ms}` to all clients
- **HTTP fallback**: 250ms polling of `status.php` when WebSocket is unavailable
- **Clock offset**: NTP-style estimation via WebSocket ping/pong, median filter + EWMA (alpha=0.3)
- **PLL calibration**: least-squares linear regression, 800ms minimum window, 6+ samples, base rate clamped to +/-1%
- **Locked correction**: `rate = baseRate + sign(avg2s) * Kp * log1p((|avg2s| - 5) / 100)`, Kp adaptive 0.01-0.05
- **Error averaging**: 2-second rolling window (avg2s) as PLL input, all-time average for diagnostics
- **Hard seek**: >2 seconds error, 2-second cooldown between seeks
- **Rate learning**: EMA alpha=0.05 from 2-second windows
- **Position data**: `milliseconds_elapsed` from FPP API (not `seconds_played`, which is whole-seconds only)
- **Server timestamp**: `server_ms` captured at midpoint of API call, used for clock offset calculation; `round()` not `intval()` to avoid 32-bit overflow on Pi 3B
- **Apache proxy**: `mod_proxy_wstunnel` proxies `/ws` on port 80 to Python server on port 8080
- **systemd service**: runs as `fpp` user with 64MB RAM / 25% CPU limits for Pi safety
- **Playback control**: `POST /api/command` with "Start Playlist" / "Stop Now" commands
- **Audio unlock**: browser autoplay policy requires a user gesture — first click/touch on the page silently plays and pauses to unlock the audio context
