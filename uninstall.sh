#!/usr/bin/env bash
set -euo pipefail

echo ""
echo "========================================="
echo "  FPP Eavesdrop - Uninstall"
echo "========================================="
echo ""

# Detect FPP web root (same logic as install.sh)
WEBROOT="/opt/fpp/www"
if [[ ! -d "$WEBROOT" ]]; then
  WEBROOT="/var/www/html"
fi
if [[ ! -d "$WEBROOT" ]]; then
  echo "[warn] Cannot find web root — skipping web file removal"
  WEBROOT=""
fi

# 1. Stop and disable the listener-ap service
echo "[uninstall] Stopping listener-ap service..."
if systemctl is-active listener-ap >/dev/null 2>&1; then
  sudo systemctl stop listener-ap
fi
if systemctl is-enabled listener-ap >/dev/null 2>&1; then
  sudo systemctl disable listener-ap
fi
sudo rm -f /etc/systemd/system/listener-ap.service
sudo systemctl daemon-reload
echo "[uninstall] listener-ap service removed"

# 2. Remove sudoers entry
echo "[uninstall] Removing sudoers entry..."
sudo rm -f /etc/sudoers.d/listener-sync
echo "[uninstall] Sudoers entry removed"

# 3. Remove web files
if [[ -n "$WEBROOT" ]]; then
  echo "[uninstall] Removing web files..."
  sudo rm -rf "$WEBROOT/listen"
  echo "[uninstall] Web files removed"

  # 4. Remove /music symlink (only if it's a symlink we created)
  if [[ -L "$WEBROOT/music" ]]; then
    LINK_TARGET=$(readlink -f "$WEBROOT/music")
    if [[ "$LINK_TARGET" == "/home/fpp/media/music" ]]; then
      sudo rm -f "$WEBROOT/music"
      echo "[uninstall] Removed /music symlink"
    else
      echo "[uninstall] /music symlink points elsewhere ($LINK_TARGET) — leaving it"
    fi
  fi
fi

# 5. Remove listener config directory
echo "[uninstall] Removing listener config..."
if [[ -d "/home/fpp/listen-sync" ]]; then
  sudo rm -rf /home/fpp/listen-sync
  echo "[uninstall] Config directory removed"
fi

# 6. Kill any leftover hostapd/dnsmasq started by our script
# (only kill the ones using our config, not system-wide ones)
if pgrep -f "hostapd-listener.conf" >/dev/null 2>&1; then
  sudo pkill -f "hostapd-listener.conf" || true
  echo "[uninstall] Stopped listener hostapd"
fi
if pgrep -f "listener-dnsmasq.conf" >/dev/null 2>&1; then
  sudo pkill -f "listener-dnsmasq.conf" || true
  echo "[uninstall] Stopped listener dnsmasq"
fi

# 7. Clean up temp files
sudo rm -f /tmp/listener-dnsmasq.conf

echo ""
echo "========================================="
echo "  Uninstall complete!"
echo "========================================="
echo "  All listener files, configs, and"
echo "  services have been removed."
echo "========================================="
