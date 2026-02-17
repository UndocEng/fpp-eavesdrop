#!/usr/bin/env python3
"""
FPP WebSocket Sync Beacon
=========================

This is the server-side component of the FPP Eavesdrop sync system. It runs as
a systemd service (ws-sync.service) on the Raspberry Pi and does three things:

1. POLLS THE FPP API every 100ms to get the current playback state (playing/
   stopped/paused, which track, position in milliseconds, etc.).

2. BROADCASTS that state to all connected WebSocket clients (phones). This is
   how phones know where FPP is in the current song. The WebSocket is proxied
   through Apache on port 80 (path /ws) -> this server on port 8080.

3. HANDLES CLIENT MESSAGES:
   - "ping": Echoes back with server timestamp for NTP-style clock offset
     estimation. The client measures RTT and computes the offset between
     its clock and the Pi's clock.
   - "report": Client sync telemetry (error, rate, etc.) logged to a file
     for debugging. Only sent when the user enables "Server Log" checkbox.

Architecture:
  Client phone <-> Apache (port 80, /ws proxy) <-> This server (port 8080)
  This server -> FPP API (http://127.0.0.1/api/fppd/status)

The server is stateless except for caching the last FPP state (so new clients
get immediate state on connect) and the set of connected clients.

Dependencies:
  - Python 3 with asyncio
  - websockets package (installed via apt: python3-websockets)
  - FPP running on the same Pi (provides the HTTP API)
"""

import asyncio
import json
import time
import logging
import urllib.request
import urllib.parse
from pathlib import Path
from datetime import datetime

try:
    import websockets
except ImportError:
    print("ERROR: 'websockets' package not found.")
    print("Install with: pip3 install websockets")
    raise SystemExit(1)

# --- Configuration ---
FPP_API_URL = "http://127.0.0.1/api/fppd/status"  # FPP's local REST API
WS_HOST = "0.0.0.0"           # Listen on all interfaces
WS_PORT = 8080                 # Apache proxies /ws on port 80 to here
POLL_INTERVAL_MS = 100         # Poll FPP API every 100ms -- fast enough for smooth sync
MUSIC_DIR = Path("/home/fpp/media/music")  # Where FPP stores music files
AUDIO_FORMATS = ["mp3", "m4a", "mp4", "aac", "ogg", "wav"]  # Supported audio formats
SYNC_LOG_PATH = Path("/home/fpp/listen-sync/sync.log")  # Client sync report log
SYNC_LOG_MAX_BYTES = 5 * 1024 * 1024  # 5 MB max before rotation

logger = logging.getLogger("ws-sync")

# --- Shared State ---
clients = set()        # All currently connected WebSocket clients
current_state = {}     # Last known FPP state (broadcast to new clients on connect)


def write_sync_log(client_ip, data):
    """Append a client sync report to the sync log file.

    Called when a client sends a 'report' message (only when "Server Log"
    checkbox is enabled on the client). Each line captures a snapshot of
    the client's sync state at that moment.

    Log format per line:
      timestamp [client_ip] EVENT  fpp=X target=Y local=Z err=Nms avg2s=Nms rate=R eff=E offset=Oms

    Where:
      fpp    = raw position reported by FPP API (ms)
      target = computed target position after clock offset + elapsed (ms)
      local  = audio.currentTime on the phone (ms)
      err    = target - local (positive = phone is behind)
      avg2s  = 2-second rolling average error (the PLL's actual input)
      rate   = current playbackRate setting
      eff    = measured effective rate (actual audio progression / wall time)
      offset = estimated clock offset (ms)

    Auto-clears the log on TRACK event (new song starts = fresh log file).
    Rotates to .log.old if the file exceeds 5MB.
    """
    try:
        event = data.get("event", "?")

        # Auto-clear log on new track -- each song gets a fresh log file
        # so you don't have to dig through hours of old data
        if event == "TRACK":
            if SYNC_LOG_PATH.exists():
                SYNC_LOG_PATH.unlink()
            track = data.get("track", "")
            now = datetime.now()
            ts = now.strftime("%Y-%m-%d %H:%M:%S.") + f"{now.microsecond // 1000:03d}"
            with open(SYNC_LOG_PATH, "a") as f:
                f.write(f"--- NEW TRACK: {track} @ {ts} [{client_ip}] ---\n")
            return

        # Simple log rotation: if file > 5MB, rename to .log.old and start fresh
        if SYNC_LOG_PATH.exists() and SYNC_LOG_PATH.stat().st_size > SYNC_LOG_MAX_BYTES:
            old = SYNC_LOG_PATH.with_suffix(".log.old")
            if old.exists():
                old.unlink()
            SYNC_LOG_PATH.rename(old)

        now = datetime.now()
        ts = now.strftime("%Y-%m-%d %H:%M:%S.") + f"{now.microsecond // 1000:03d}"
        fpp = data.get("fpp", 0)
        target = data.get("target", 0)
        local = data.get("local", 0)
        err = data.get("err", 0)
        rate = data.get("rate", 1.0)
        eff = data.get("eff", 0)
        offset = data.get("offset", 0)
        avg2s = data.get("avg2s", 0)
        track = data.get("track", "")
        line = f"{ts} [{client_ip}] {event:12s} fpp={fpp:>7d} target={target:>7d} local={local:>7d} err={err:>5d}ms avg2s={avg2s:>5d}ms rate={rate:.4f} eff={eff:.3f} offset={offset:>4d}ms\n"

        with open(SYNC_LOG_PATH, "a") as f:
            f.write(line)
    except Exception as e:
        logger.debug(f"Sync log write error: {e}")


def basename_noext(path):
    """Extract filename without extension.
    e.g., 'MyShow.fseq' -> 'MyShow', used to match audio files to sequences."""
    if not path:
        return ""
    return Path(path).stem


# Cache: maps base name -> URL path (or empty string if no audio file found).
# Avoids repeated filesystem lookups for the same track.
_audio_cache = {}

def find_audio_file(base):
    """Find matching audio file for a sequence base name.

    FPP plays sequences (.fseq files) which may have a matching audio file
    with the same base name. e.g., sequence "MyShow.fseq" -> "MyShow.mp3".
    We check for all supported formats in priority order.

    Returns a URL path like "/music/MyShow.mp3" (served by Apache via symlink)
    or empty string if no matching audio file exists.
    """
    if not base:
        return ""
    if base in _audio_cache:
        return _audio_cache[base]
    for ext in AUDIO_FORMATS:
        if (MUSIC_DIR / f"{base}.{ext}").exists():
            url = f"/music/{urllib.parse.quote(base)}.{ext}"
            _audio_cache[base] = url
            return url
    _audio_cache[base] = ""
    return ""


def fetch_fpp_status():
    """Synchronous HTTP call to FPP's local REST API.

    Runs in a thread (via asyncio.to_thread) because urllib is blocking.
    Returns the parsed JSON response, or None on any error (timeout, connection
    refused, malformed JSON). The caller handles None by keeping the last state.
    """
    try:
        req = urllib.request.Request(FPP_API_URL)
        with urllib.request.urlopen(req, timeout=1.0) as resp:
            return json.loads(resp.read())
    except Exception:
        return None


def parse_fpp_state(src, server_ms):
    """Convert raw FPP API response into the broadcast message format.

    The FPP API returns a complex JSON object. We extract just what clients need:
      - state: "play", "pause", or "stop"
      - base: sequence filename without extension (used to find matching audio)
      - pos_ms: current playback position in milliseconds
        IMPORTANT: Uses 'milliseconds_elapsed' (true ms precision), NOT
        'seconds_played' (which is whole-second only on FPP).
      - mp3_url: URL path to the audio file (e.g., "/music/MyShow.mp3")
      - server_ms: server timestamp (Unix ms) -- used by clients for clock offset

    Returns None if the source data is None (API call failed).
    """
    if src is None:
        return None

    # FPP reports status as both a string and an integer. Prefer string, fall back to int.
    status_name = str(src.get("status_name", "")).lower()
    status_int = int(src.get("status", -1))

    if status_name in ("playing", "play"):
        state = "play"
    elif status_name in ("paused", "pause"):
        state = "pause"
    elif status_name in ("idle", "stopped", "stop"):
        state = "stop"
    elif status_int == 1:
        state = "play"
    elif status_int == 2:
        state = "pause"
    else:
        state = "stop"

    seq = str(src.get("current_sequence", ""))
    base = basename_noext(seq)
    pos_ms = int(src.get("milliseconds_elapsed", 0))
    mp3_url = find_audio_file(base)

    return {
        "state": state,
        "base": base,
        "pos_ms": pos_ms,
        "mp3_url": mp3_url,
        "server_ms": server_ms
    }


async def broadcast(message):
    """Send a JSON message to all connected WebSocket clients.

    Phones that have disconnected (but we haven't gotten the close event yet)
    will throw ConnectionClosed -- we catch that and remove them from the set.
    This runs every 100ms (on each FPP poll tick), so dead client cleanup
    happens quickly.
    """
    if not clients:
        return
    dead = set()
    for ws in clients:
        try:
            await ws.send(message)
        except websockets.ConnectionClosed:
            dead.add(ws)
        except Exception:
            dead.add(ws)
    clients.difference_update(dead)


async def fpp_poll_loop():
    """Main loop: poll FPP API every 100ms, broadcast state to all clients.

    Timing note: server_ms is the midpoint of the API call (average of before
    and after timestamps). This is the best estimate of when pos_ms was valid.
    Clients use server_ms to compute elapsed time since the snapshot.

    On API failure (None return), we keep the last known state and just update
    the timestamp. This prevents brief API hiccups from showing "stopped" to
    all clients.
    """
    global current_state
    while True:
        t_before = time.time()
        # Run the blocking HTTP call in a thread to avoid stalling the event loop
        src = await asyncio.to_thread(fetch_fpp_status)
        t_after = time.time()
        # Midpoint timestamp: best estimate of when FPP reported its position
        server_ms = int(((t_before + t_after) / 2) * 1000)

        new_state = parse_fpp_state(src, server_ms)
        if new_state is not None:
            current_state = new_state
        elif current_state:
            # API hiccup: keep last known state, just update the timestamp
            # so clients don't compute huge elapsed times
            current_state["server_ms"] = server_ms
        if current_state:
            await broadcast(json.dumps(current_state))

        # Sleep for remainder of the 100ms interval (minus time already spent)
        elapsed = time.time() - t_before
        sleep_s = max(0.01, (POLL_INTERVAL_MS / 1000.0) - elapsed)
        await asyncio.sleep(sleep_s)


async def handle_client(websocket, path=None):
    """Handle a single WebSocket client connection.

    Lifecycle:
      1. Client connects -> added to broadcast set, gets current state immediately
      2. Client sends messages -> we handle ping (clock sync) and report (logging)
      3. Client disconnects -> removed from broadcast set

    The `path` parameter is required by websockets 10.4 (Debian apt version)
    but not used. Newer versions of websockets use a different handler signature.
    """
    clients.add(websocket)
    remote = websocket.remote_address
    logger.info(f"Client connected: {remote} (total: {len(clients)})")

    try:
        # Send current FPP state immediately so the client doesn't have to
        # wait up to 100ms for the next broadcast tick
        if current_state:
            await websocket.send(json.dumps(current_state))

        # Listen for client messages
        client_ip = remote[0] if remote else "unknown"
        async for message in websocket:
            try:
                data = json.loads(message)
                msg_type = data.get("type")
                if msg_type == "ping":
                    # Clock sync: echo back client_ts + add server_ts.
                    # Client computes: RTT = now - client_ts
                    #                   offset = server_ts - client_ts - (RTT/2)
                    await websocket.send(json.dumps({
                        "type": "pong",
                        "client_ts": data.get("client_ts", 0),
                        "server_ts": int(time.time() * 1000)
                    }))
                elif msg_type == "report":
                    # Sync telemetry from client -> write to log file for analysis
                    write_sync_log(client_ip, data)
            except json.JSONDecodeError:
                pass
    except websockets.ConnectionClosed:
        pass
    finally:
        clients.discard(websocket)
        logger.info(f"Client disconnected: {remote} (total: {len(clients)})")


async def main():
    """Entry point: start the WebSocket server and FPP polling loop.

    WebSocket server settings:
      - ping_interval=20, ping_timeout=30: server-side keepalive pings every 20s,
        disconnect if no pong within 30s. This is separate from our client-side
        clock-sync pings (every 1s). These are the websockets library's built-in
        health check pings.
      - max_size=4096: limit message size to 4KB (our messages are tiny JSON)
      - compression=None: disable per-message compression to reduce CPU usage
        on the Pi 3B. Our messages are small enough that compression overhead
        outweighs the savings.
    """
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(name)s] %(levelname)s: %(message)s"
    )

    logger.info(f"Starting WebSocket sync beacon on port {WS_PORT}")
    logger.info(f"Polling FPP API every {POLL_INTERVAL_MS}ms")
    logger.info(f"Client sync log: {SYNC_LOG_PATH}")

    # Write separator to sync log on service start (helps identify restarts)
    try:
        SYNC_LOG_PATH.parent.mkdir(parents=True, exist_ok=True)
        with open(SYNC_LOG_PATH, "a") as f:
            f.write(f"\n--- ws-sync started {datetime.now().isoformat()} ---\n")
    except Exception:
        pass

    # Start the FPP polling loop as a background task
    poll_task = asyncio.create_task(fpp_poll_loop())

    # Start the WebSocket server (blocks forever, serving clients)
    async with websockets.serve(
        handle_client, WS_HOST, WS_PORT,
        ping_interval=20,
        ping_timeout=30,
        max_size=4096,
        compression=None
    ):
        logger.info(f"WebSocket server listening on ws://{WS_HOST}:{WS_PORT}")
        await poll_task  # runs forever alongside the WS server


if __name__ == "__main__":
    asyncio.run(main())
