#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VERSION=$(cat "$ROOT_DIR/VERSION" 2>/dev/null || echo "unknown")

echo ""
echo "========================================="
echo "  FPP Eavesdrop - v${VERSION}"
echo "========================================="
echo ""

# Detect FPP web root
WEBROOT="/opt/fpp/www"
if [[ ! -d "$WEBROOT" ]]; then
  WEBROOT="/var/www/html"
fi
if [[ ! -d "$WEBROOT" ]]; then
  echo "[ERROR] Cannot find web root. Is this an FPP system?"
  exit 1
fi
echo "[install] Web root: $WEBROOT"

MUSIC_DIR="/home/fpp/media/music"
LISTEN_SYNC="/home/fpp/listen-sync"

# Deploy web files
echo "[install] Deploying web files..."
sudo mkdir -p "$WEBROOT/listen"
sudo cp -f "$ROOT_DIR/www/listen/listen.html" "$WEBROOT/listen/listen.html" 2>/dev/null || \
  sudo cp -f "$ROOT_DIR/www/listen/index.html" "$WEBROOT/listen/index.html" 2>/dev/null || true
sudo cp -f "$ROOT_DIR/www/listen/status.php" "$WEBROOT/listen/status.php"
sudo cp -f "$ROOT_DIR/www/listen/admin.php" "$WEBROOT/listen/admin.php"
sudo cp -f "$ROOT_DIR/www/listen/version.php" "$WEBROOT/listen/version.php"
sudo cp -f "$ROOT_DIR/www/listen/logo.png" "$WEBROOT/listen/logo.png" 2>/dev/null || true
sudo cp -f "$ROOT_DIR/VERSION" "$WEBROOT/listen/VERSION" 2>/dev/null || true

# Create index.html redirect if listen.html exists
if [[ -f "$WEBROOT/listen/listen.html" ]]; then
  echo '<meta http-equiv="refresh" content="0;url=listen.html">' | sudo tee "$WEBROOT/listen/index.html" > /dev/null
fi

sudo chown -R www-data:www-data "$WEBROOT/listen" 2>/dev/null || true
sudo chmod -R 755 "$WEBROOT/listen" 2>/dev/null || true
echo "[install] Web files deployed"

# Create /music symlink
if [[ ! -L "$WEBROOT/music" ]] && [[ ! -d "$WEBROOT/music" ]]; then
  sudo ln -s "$MUSIC_DIR" "$WEBROOT/music"
  echo "[install] Created /music symlink"
elif [[ -L "$WEBROOT/music" ]]; then
  CURRENT=$(readlink -f "$WEBROOT/music")
  if [[ "$CURRENT" != "$MUSIC_DIR" ]]; then
    sudo rm -f "$WEBROOT/music"
    sudo ln -s "$MUSIC_DIR" "$WEBROOT/music"
    echo "[install] Updated /music symlink"
  fi
fi
sudo chmod -R a+rX "$MUSIC_DIR" 2>/dev/null || true

# Create config directory
echo "[install] Setting up listener config..."
sudo mkdir -p "$LISTEN_SYNC"
sudo chown fpp:fpp "$LISTEN_SYNC"

# Fix Windows line endings on shell scripts (in case cloned on Windows)
if command -v sed >/dev/null 2>&1; then
  sed -i 's/\r$//' "$ROOT_DIR/server/listener-ap.sh" 2>/dev/null || true
  sed -i 's/\r$//' "$ROOT_DIR/server/ws-sync-server.py" 2>/dev/null || true
fi

# ====== WiFi Access Point ======

# Install AP dependencies
for pkg in hostapd dnsmasq; do
  if ! dpkg -l "$pkg" 2>/dev/null | grep -q "^ii"; then
    echo "[install] Installing $pkg..."
    sudo apt-get install -y "$pkg" 2>/dev/null || true
  fi
done
# Ensure hostapd is unmasked (Raspbian masks it by default)
sudo systemctl unmask hostapd 2>/dev/null || true

# Deploy default AP config (if not already present)
AP_CONF="$LISTEN_SYNC/ap.conf"
if [[ ! -f "$AP_CONF" ]]; then
  sudo tee "$AP_CONF" > /dev/null <<'EOF'
# AP Configuration for FPP Eavesdrop
# Edit via web UI or manually. Changes take effect on service restart.
WLAN_IF=wlan1
AP_IP=192.168.50.1
AP_MASK=24
EOF
  sudo chmod 644 "$AP_CONF"
  echo "[install] Default AP config created (IP: 192.168.50.1)"
else
  echo "[install] AP config exists, keeping current settings"
fi

# Read configured interface from ap.conf
WLAN_IF="wlan1"
if [[ -f "$AP_CONF" ]]; then
  source "$AP_CONF"
  WLAN_IF="${WLAN_IF:-wlan1}"
fi

# Deploy default hostapd config with WPA2 (if not already present)
HOSTAPD_CONF="$LISTEN_SYNC/hostapd-listener.conf"
if [[ ! -f "$HOSTAPD_CONF" ]]; then
  sudo tee "$HOSTAPD_CONF" > /dev/null <<EOF
interface=$WLAN_IF
driver=nl80211
ssid=SHOW_AUDIO
hw_mode=g
channel=6
country_code=US
wmm_enabled=1
ieee80211n=1
auth_algs=1
ignore_broadcast_ssid=0
ap_isolate=0

# WPA2 configuration
wpa=2
wpa_passphrase=Listen123
wpa_key_mgmt=WPA-PSK
wpa_pairwise=CCMP
rsn_pairwise=CCMP
EOF
  sudo chmod 644 "$HOSTAPD_CONF"
  echo "[install] Default WiFi password: Listen123"
else
  echo "[install] Hostapd config exists, keeping current settings"
fi

# Configure sudoers for www-data (admin.php WiFi management)
echo "[install] Configuring sudoers..."
SUDOERS_FILE="/etc/sudoers.d/listener-sync"
sudo tee "$SUDOERS_FILE" > /dev/null <<'EOF'
# Allow www-data to manage listener AP config and service
www-data ALL=(ALL) NOPASSWD: /usr/bin/tee /home/fpp/listen-sync/hostapd-listener.conf
www-data ALL=(ALL) NOPASSWD: /usr/bin/tee /home/fpp/listen-sync/ap.conf
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart listener-ap.service
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl stop hostapd
EOF
sudo chmod 440 "$SUDOERS_FILE"

if sudo visudo -cf "$SUDOERS_FILE" > /dev/null 2>&1; then
  echo "[install] Sudoers configured"
else
  echo "[ERROR] Invalid sudoers syntax! Removing."
  sudo rm -f "$SUDOERS_FILE"
  exit 1
fi

# Deploy listener-ap script
echo "[install] Deploying listener-ap..."
sudo cp -f "$ROOT_DIR/server/listener-ap.sh" "$LISTEN_SYNC/listener-ap.sh"
sudo chown fpp:fpp "$LISTEN_SYNC/listener-ap.sh"
sudo chmod 755 "$LISTEN_SYNC/listener-ap.sh"
# Fix CRLF on deployed copy too
if command -v sed >/dev/null 2>&1; then
  sudo sed -i 's/\r$//' "$LISTEN_SYNC/listener-ap.sh" 2>/dev/null || true
fi

# Deploy listener-ap systemd service
if [[ -f "$ROOT_DIR/server/listener-ap.service" ]]; then
  sudo cp -f "$ROOT_DIR/server/listener-ap.service" /etc/systemd/system/listener-ap.service
  sudo systemctl daemon-reload
  sudo systemctl enable listener-ap 2>/dev/null || true
  echo "[install] listener-ap service installed"

  # Only start if the configured interface is present
  if [[ -e "/sys/class/net/$WLAN_IF" ]]; then
    sudo systemctl restart listener-ap 2>/dev/null || true
    echo "[install] listener-ap service started ($WLAN_IF detected)"
  else
    echo "[install] $WLAN_IF not detected -- listener-ap enabled but not started"
    echo "          Connect $WLAN_IF and run: sudo systemctl start listener-ap"
  fi
fi

# ====== WebSocket Sync Server ======

# Install python3-websockets (required by ws-sync-server.py)
echo "[install] Checking Python websockets dependency..."
if python3 -c "import websockets" 2>/dev/null; then
  echo "[install] python3-websockets already installed"
else
  echo "[install] Installing python3-websockets..."
  if sudo apt-get install -y python3-websockets 2>/dev/null; then
    echo "[install] Installed python3-websockets via apt"
  elif pip3 install websockets 2>/dev/null; then
    echo "[install] Installed websockets via pip3"
  else
    echo "[WARN] Could not install python3-websockets. WebSocket sync will not work."
    echo "       Install manually: sudo apt install python3-websockets"
  fi
fi

# Deploy WebSocket sync server
echo "[install] Deploying WebSocket sync server..."
sudo cp -f "$ROOT_DIR/server/ws-sync-server.py" "$LISTEN_SYNC/ws-sync-server.py"
sudo chown fpp:fpp "$LISTEN_SYNC/ws-sync-server.py"
sudo chmod 755 "$LISTEN_SYNC/ws-sync-server.py"
echo "[install] ws-sync-server.py deployed"

# Deploy ws-sync systemd service
if [[ -f "$ROOT_DIR/config/ws-sync.service" ]]; then
  sudo cp -f "$ROOT_DIR/config/ws-sync.service" /etc/systemd/system/ws-sync.service
  sudo systemctl daemon-reload
  sudo systemctl enable ws-sync 2>/dev/null || true
  sudo systemctl restart ws-sync 2>/dev/null || true
  echo "[install] ws-sync service installed and started"
fi

# Configure Apache for WebSocket proxy
echo "[install] Configuring Apache WebSocket proxy..."

# Enable required Apache modules
for mod in proxy proxy_wstunnel headers rewrite; do
  if ! apache2ctl -M 2>/dev/null | grep -q "${mod}_module"; then
    sudo a2enmod "$mod" 2>/dev/null || true
  fi
done

# Deploy Apache config
if [[ -f "$ROOT_DIR/config/apache-listener.conf" ]]; then
  sudo cp -f "$ROOT_DIR/config/apache-listener.conf" /etc/apache2/conf-available/listener.conf
  sudo a2enconf listener 2>/dev/null || true
  echo "[install] Apache listener config deployed"
fi

# Restart Apache to apply changes
sudo systemctl restart apache2 2>/dev/null || sudo apachectl restart 2>/dev/null || true
echo "[install] Apache restarted"

# ====== FPP Plugin Registration ======

PLUGIN_DIR="/home/fpp/media/plugins/fpp-eavesdrop"
echo "[install] Registering FPP plugin..."
sudo mkdir -p "$PLUGIN_DIR"
sudo cp -f "$ROOT_DIR/pluginInfo.json" "$PLUGIN_DIR/pluginInfo.json"
sudo cp -f "$ROOT_DIR/api.php" "$PLUGIN_DIR/api.php"
sudo chown -R fpp:fpp "$PLUGIN_DIR"
echo "[install] Plugin registered"

# Deploy custom.js footer button (appends if not already present)
CUSTOM_JS="/home/fpp/media/config/custom.js"
if ! grep -q "fpp-eavesdrop" "$CUSTOM_JS" 2>/dev/null; then
  echo "[install] Adding Eavesdrop footer button..."
  cat >> "$CUSTOM_JS" <<'JSEOF'

// -- fpp-eavesdrop: Undoc footer button --
$(document).ready(function() {
    if ($('#rebootShutdown').length) {
        var btn = $('<button>')
            .attr('type', 'button')
            .addClass('buttons wideButton btn-outline-light')
            .html('<i class="fas fa-fw fa-headphones fa-nbsp"></i>Undoc Eavesdrop')
            .on('click', function() { window.open('/listen/', '_blank'); });
        $('#rebootShutdown').prepend(btn);
    }

    // SBS mode warning on FPP Network Config page
    if (window.location.pathname === '/networkconfig.php' || window.location.pathname === '/networkconfig.php/') {
        $.getJSON('/listen/admin.php', { action: 'get_ap_config' }, function(res) {
            if (!res.success || res.ap_iface !== 'wlan0') return;
            // Inject warning banner
            var banner = $('<div>')
                .css({
                    background: '#4a0000', border: '2px solid #d33',
                    borderRadius: '6px', padding: '12px 16px', margin: '10px 0 16px',
                    color: '#ff6b6b', fontWeight: '600', fontSize: '14px'
                })
                .html('<i class="fas fa-exclamation-triangle"></i> ' +
                    '<b>SBS Mode Active</b> — wlan0 is running the Eavesdrop listener AP. ' +
                    'Scanning or changing wlan0 settings here will <b>disconnect all listeners</b>. ' +
                    '<a href="/listen/listen.html" style="color:#6bf;margin-left:4px;">Manage AP in Eavesdrop</a>');
            $('h1.title').after(banner);

            // Intercept wlan0 selection — warn before scan fires
            var origLoadSIDS = window.LoadSIDS;
            if (typeof origLoadSIDS === 'function') {
                window.LoadSIDS = function(iface) {
                    if (iface === 'wlan0' && !confirm(
                        'SBS Mode is active — wlan0 is running the Eavesdrop AP.\n\n' +
                        'Scanning will disconnect all listeners.\n\nContinue anyway?'
                    )) {
                        return;
                    }
                    return origLoadSIDS.apply(this, arguments);
                };
            }
        });
    }
});
// -- end fpp-eavesdrop --
JSEOF
  echo "[install] Footer button added"
else
  echo "[install] Footer button already present"
fi

# ====== Self-Test ======
echo ""
echo "[install] Running self-tests..."
TESTS_OK=true

# Check ws-sync service
if systemctl is-active ws-sync >/dev/null 2>&1; then
  echo "[test] ws-sync service: OK"
else
  echo "[test] ws-sync service: FAILED (check: journalctl -u ws-sync)"
  TESTS_OK=false
fi

# Probe ws-sync port (expect HTTP 426 Upgrade Required)
if command -v curl >/dev/null 2>&1; then
  HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/ 2>/dev/null || echo "000")
  if [[ "$HTTP_CODE" == "426" ]]; then
    echo "[test] ws-sync port 8080: OK (HTTP 426 = WebSocket expected)"
  else
    echo "[test] ws-sync port 8080: HTTP $HTTP_CODE (expected 426)"
    TESTS_OK=false
  fi
fi

# Check listener-ap service (only if configured interface exists)
if [[ -e "/sys/class/net/$WLAN_IF" ]]; then
  if systemctl is-active listener-ap >/dev/null 2>&1; then
    echo "[test] listener-ap service: OK"
  else
    echo "[test] listener-ap service: FAILED (check: journalctl -u listener-ap)"
    TESTS_OK=false
  fi
fi

# Get display values
LOCAL_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
if [[ -z "$LOCAL_IP" ]]; then
  LOCAL_IP="YOUR_FPP_IP"
fi
AP_SSID=$(grep '^ssid=' "$LISTEN_SYNC/hostapd-listener.conf" 2>/dev/null | cut -d= -f2 || echo "SHOW_AUDIO")
AP_IP=$(grep '^AP_IP=' "$LISTEN_SYNC/ap.conf" 2>/dev/null | cut -d= -f2 || echo "192.168.50.1")

echo ""
echo "========================================="
echo "  Install complete!"
echo "========================================="
echo "  Page:  http://${LOCAL_IP}/listen/"
echo "  Sync:  WebSocket (ws://${LOCAL_IP}/ws)"
if [[ -e "/sys/class/net/$WLAN_IF" ]]; then
SBS_NOTE=""
[[ "$WLAN_IF" == "wlan0" ]] && SBS_NOTE=" (SBS mode)"
echo "  WiFi:  ${AP_SSID} (WPA2) on ${WLAN_IF}${SBS_NOTE}"
echo "  AP IP: ${AP_IP}"
echo "  Pass:  Listen123 (change via web UI)"
fi
echo "========================================="
if [[ "$TESTS_OK" != "true" ]]; then
  echo "  WARNING: Some self-tests failed."
  echo "  Check: journalctl -u ws-sync -f"
  echo "========================================="
fi
