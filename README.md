# FPP Eavesdrop

A **show-owner developer tool** for Falcon Player (FPP). Runs on the master and gives the show owner full control: start/stop sequences and playlists, monitor audio sync, and manage the WiFi access point — all from one password-protected page on your phone.

> **This is NOT the visitor-facing listener.** For an open WiFi hotspot that lets your audience listen along, see [fpp-listener-sync](https://github.com/UndocEng/fpp-listener-sync) (runs on a remote). **Eavesdrop** runs on the master with unfettered API access, which is why its WiFi is WPA2 password-protected.

---

## What This Does

1. Creates a **password-protected** WiFi hotspot (called **SHOW_AUDIO**) using a USB WiFi adapter
2. Serves a control page where the show owner can start/stop playlists and sequences
3. Syncs show audio to the owner's phone so you can hear what your audience hears
4. Lets you change the WiFi AP SSID and password from the page (under Options)
5. Accessible from both the hotspot and your main network — if you know the master's IP, just add `/listen/` to it (e.g. `http://10.1.66.204/listen/`). No hotspot required.

---

## Important: Must Be Installed on the Master

This must be installed on your **master** FPP (the one in **player mode**), not on a remote. It needs:

- Direct access to the music files in `/home/fpp/media/music/`
- The FPP API at `127.0.0.1` to read playback status and start/stop playlists
- Remotes don't store media locally — they only receive channel data from the master

If your master controls the show, that's where this goes.

## What You Need

Before you start, make sure you have:

- A **Raspberry Pi** running **Falcon Player (FPP) in player (master) mode** — any version that uses `/opt/fpp/www/` as its web root (FPP 6+)
- A **USB WiFi adapter** that supports AP (access point) mode — most cheap USB adapters work. It needs to show up as `wlan1` when you plug it in
- Your FPP already has **sequences (.fseq files)** and matching **audio files (.mp3)** loaded
- A computer on the same network as your FPP to run the install commands
- Basic ability to type commands into a terminal (we'll walk you through every step)

---

## Step-by-Step Install

### Step 1: Plug in the USB WiFi adapter

Plug your USB WiFi adapter into one of the Pi's USB ports. It doesn't matter which one.

### Step 2: Open a terminal to your FPP

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

### Step 3: Download this project onto your FPP

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

### Step 4: Run the installer

```bash
sudo ./install.sh
```

You should see output like this:
```
=========================================
  ChatGPT FPP Listener - v1.0
=========================================

[install] Web root: /opt/fpp/www
[install] Deploying web files...
[install] Web files deployed
[install] Created /music symlink
[install] Setting up listener config...
[install] Default WiFi password: Listen123
[install] Configuring sudoers...
[install] Sudoers configured
[install] listener-ap service installed

=========================================
  Install complete!
=========================================
  Page:     http://192.168.50.1/listen/
  WiFi:     SHOW_AUDIO (WPA2)
  Password: Listen123 (change via web UI)
=========================================
```

### Step 5: Start the WiFi hotspot

```bash
sudo systemctl start listener-ap
```

This turns on the WiFi hotspot. It will also start automatically every time the Pi boots.

### Step 6: Test it

1. On your phone, look for a WiFi network called **SHOW_AUDIO**
2. Connect to it using password **Listen123**
3. Open your phone's browser and go to `http://192.168.50.1/listen/`
4. You should see the Show Audio page with a Playback section at the top
5. Select a sequence from the dropdown and tap **Start** — your lights and audio should begin

That's it! You're done.

---

## How to Use It

### Starting a show

1. Open the listen page (either from the SHOW_AUDIO WiFi at `http://192.168.50.1/listen/` or from your main network at `http://YOUR_FPP_IP/listen/`)
2. Pick a playlist or sequence from the dropdown
3. Tap **Start**
4. Audio will play through the phone speaker and lights will run on your display

### Stopping a show

Tap the **Stop** button.

### Changing the WiFi password

1. On the listen page, scroll down and tap **Options**
2. Type a new password (must be 8-63 characters)
3. Tap **Change Password**
4. The WiFi hotspot will restart — everyone will need to reconnect with the new password

### Accessing from your main network

The control page works on both networks:
- **WiFi hotspot:** `http://192.168.50.1/listen/`
- **Main network:** `http://YOUR_FPP_IP/listen/`

This is a developer/owner tool — no login is required on your main network. The WPA2 password on the hotspot (wlan1) is what keeps unauthorized users out. For visitor-facing audio, use [fpp-listener-sync](https://github.com/UndocEng/fpp-listener-sync) on a remote with an open WiFi AP.

---

## Troubleshooting

### "I can't see the SHOW_AUDIO WiFi network"

- Make sure your USB WiFi adapter is plugged in
- Check if it's recognized: `ip link show wlan1` — you should see it listed
- Make sure the service is running: `sudo systemctl status listener-ap`
- Restart it: `sudo systemctl restart listener-ap`
- Check the log: `journalctl -u listener-ap -n 30`

### "The page loads but I hear no audio"

- On iPhone, check that the **ringer switch** (on the side of the phone) is not on silent
- Turn up the **volume** on the phone
- Tap anywhere on the page — mobile browsers require a tap before they'll play audio
- Check that you have `.mp3` files in `/home/fpp/media/music/` with the same name as your `.fseq` files (e.g. `Elvis.fseq` needs `Elvis.mp3`)

### "The sequence starts but the lights don't do anything"

This is an FPP output configuration issue, not a listener issue. Check:
- Go to your FPP web interface (`http://YOUR_FPP_IP/`)
- Click **Input/Output Setup** > **Channel Outputs**
- Make sure your output universes are **active** (checkbox enabled)
- Make sure the output IP addresses are correct (not your FPP's own IP)
- If you see a warning like "UDP Output set to send data to myself" — that means one of your outputs is pointed at the FPP itself. Change it to the correct controller IP

### "Audio is out of sync"

- Tap the **Resync** button on the listen page
- If it's consistently off, check that your Pi has the correct time (FPP usually handles this automatically)

### "I can't connect to the WiFi anymore after changing the password"

- The default password is `Listen123`
- If you forgot your new password, SSH into the FPP and check: `cat /home/fpp/listen-sync/hostapd-listener.conf | grep wpa_passphrase`
- To reset to default, edit the file: `sudo nano /home/fpp/listen-sync/hostapd-listener.conf`, change `wpa_passphrase=` to `Listen123`, save, then restart: `sudo systemctl restart listener-ap`

---

## Updating

To get the latest version:

```bash
cd /home/fpp/fpp-eavesdrop
git pull
sudo ./install.sh
```

Your WiFi password and settings will be preserved (the installer doesn't overwrite existing config).

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
- The `listener-ap` systemd service (stopped and disabled)
- The sudoers entry for the web UI
- The config directory at `/home/fpp/listen-sync/`
- Any running hostapd/dnsmasq processes started by the listener

After uninstalling, your FPP is exactly as it was before you installed this project. You can then delete the project folder:

```bash
rm -rf /home/fpp/fpp-eavesdrop
```

---

## Files

| File | What it does |
|------|-------------|
| `www/listen/listen.html` | The main page — playback controls, audio sync, options |
| `www/listen/index.html` | Redirects to listen.html |
| `www/listen/status.php` | Returns current FPP playback status as JSON (polled 4x/second) |
| `www/listen/admin.php` | Handles start/stop commands and WiFi password changes |
| `www/listen/version.php` | Returns the FPP version number |
| `www/listen/logo.png` | Undocumented Engineer logo |
| `server/listener-ap.sh` | Script that starts the WiFi hotspot (hostapd + dnsmasq) |
| `server/listener-ap.service` | Systemd service file for auto-starting the hotspot on boot |
| `install.sh` | Installs everything |
| `uninstall.sh` | Removes everything (restores FPP to original state) |

---

## How Audio Sync Works

Eavesdrop uses a **Phase-Locked Loop (PLL)** to keep the phone's audio in sync with FPP's sequence playback. Instead of repeatedly jumping to the correct position (which causes audible pops), it smoothly adjusts the playback speed to converge on FPP's position and stay locked.

### The Problem

FPP plays sequences on the Pi. The phone plays the matching audio file independently. Two different clocks on two different devices will always drift apart — even a 0.1% difference means 300ms of drift over a 5-minute song. The sync engine's job is to measure and correct this drift in real time.

### The Data Path

1. **FPP API** (`/api/fppd/status`) reports `milliseconds_elapsed` — the current playback position with millisecond precision
2. **status.php** on the Pi relays this to the browser as `pos_ms`, along with a `server_ms` timestamp captured at the midpoint of the API call
3. **The browser** polls status.php 4 times per second (every 250ms) and estimates the server/client clock offset using request round-trip timing. If both devices have NTP (internet access), this offset converges to near-zero automatically

### The PLL Algorithm

The sync engine runs through three phases:

**1. Anchor** (first poll after a track starts)
- Preloads the audio file and waits for metadata
- Seeks the audio element to FPP's current position
- Starts playback at normal speed (1.0x)
- Begins collecting timing samples

**2. Calibrate** (~1.5 seconds, 6 polls)
- Each poll records a pair: `{local_time, fpp_position}`
- After 6 samples, computes a **least-squares linear regression** to find the *rate ratio* — how fast FPP's clock advances relative to the phone's clock
- This ratio is typically very close to 1.0 (e.g., 1.0003) but the tiny difference matters over minutes of playback
- Sets `playbackRate` to match the measured ratio

**3. Locked** (ongoing, every 250ms)
- Computes the **phase error**: `fpp_position - audio.currentTime`
- Applies a **proportional correction**: `playbackRate = baseRate + 0.3 * phaseError`
- This gently speeds up or slows down playback to close any remaining gap
- The correction is clamped to +/-5% to stay imperceptible
- Every 2 seconds, the **base rate is updated** via exponential moving average from observed drift, so the system *learns* the true clock relationship and corrections shrink over time
- If error ever exceeds 2 seconds (e.g., user scrubbed on FPP), a hard seek is used as a fallback

### Result

After the initial 1.5-second calibration, the phone stays locked to FPP's position. The error display on the listen page shows the live phase error in milliseconds — it should hover near zero. The rate display shows the current `playbackRate`, which should be very close to 1.000x.

---

## Technical Details (for developers)

- Audio sync uses 250ms polling of FPP's `/api/fppd/status` endpoint
- Position data comes from `milliseconds_elapsed` (not `seconds_played`, which is whole-seconds only)
- Clock offset between phone and server is estimated using request round-trip midpoint timing, smoothed with a median filter + EMA
- PLL calibration uses least-squares linear regression over 6 samples to compute the FPP/local clock rate ratio
- Locked-phase correction: `playbackRate = baseRate + Kp * phaseError` (Kp=0.3, clamped to +/-5%)
- Base rate learned via EMA (alpha=0.05) from 2-second observation windows
- Hard seek fallback at >2 seconds of phase error, with 2-second cooldown
- Playback starts via `POST /api/command` with the "Start Playlist" command (works for both playlists and `.fseq` sequences)
- The WiFi AP runs hostapd on wlan1 with dnsmasq for DHCP and DNS (all queries resolve to the AP IP for captive portal behavior)
- Config is stored at `/home/fpp/listen-sync/hostapd-listener.conf` and persists across reboots
- The web UI uses sudo via `/etc/sudoers.d/listener-sync` to write the hostapd config and restart the AP service
