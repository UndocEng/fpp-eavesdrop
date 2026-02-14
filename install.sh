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

# Deploy default hostapd config with WPA2 (if not already present)
HOSTAPD_CONF="$LISTEN_SYNC/hostapd-listener.conf"
if [[ ! -f "$HOSTAPD_CONF" ]]; then
  sudo tee "$HOSTAPD_CONF" > /dev/null <<'EOF'
interface=wlan1
driver=nl80211
ssid=SHOW_AUDIO
hw_mode=g
channel=6
country_code=US
wmm_enabled=1
ieee80211n=1
auth_algs=1
ignore_broadcast_ssid=0
ap_isolate=1

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

# Configure sudoers for www-data (admin.php needs this)
echo "[install] Configuring sudoers..."
SUDOERS_FILE="/etc/sudoers.d/listener-sync"
sudo tee "$SUDOERS_FILE" > /dev/null <<'EOF'
# Allow www-data to manage listener AP config and service
www-data ALL=(ALL) NOPASSWD: /usr/bin/tee /home/fpp/listen-sync/hostapd-listener.conf
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart listener-ap.service
EOF
sudo chmod 440 "$SUDOERS_FILE"

if sudo visudo -cf "$SUDOERS_FILE" > /dev/null 2>&1; then
  echo "[install] Sudoers configured"
else
  echo "[ERROR] Invalid sudoers syntax! Removing."
  sudo rm -f "$SUDOERS_FILE"
  exit 1
fi

# Fix Windows line endings on shell scripts (in case cloned on Windows)
if command -v sed >/dev/null 2>&1; then
  sed -i 's/\r$//' "$ROOT_DIR/server/listener-ap.sh" 2>/dev/null || true
fi

# Deploy listener-ap systemd service
if [[ -f "$ROOT_DIR/server/listener-ap.service" ]]; then
  sudo cp "$ROOT_DIR/server/listener-ap.service" /etc/systemd/system/listener-ap.service
  sudo chmod +x "$ROOT_DIR/server/listener-ap.sh"
  sudo systemctl daemon-reload
  sudo systemctl enable listener-ap 2>/dev/null || true
  echo "[install] listener-ap service installed"
fi

echo ""
echo "========================================="
echo "  Install complete!"
echo "========================================="
echo "  Page:     http://192.168.50.1/listen/"
echo "  WiFi:     SHOW_AUDIO (WPA2)"
echo "  Password: Listen123 (change via web UI)"
echo "========================================="
