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

# 1. Stop and disable the listener-ap service (legacy, may not exist)
if systemctl is-active listener-ap >/dev/null 2>&1; then
  echo "[uninstall] Stopping listener-ap service..."
  sudo systemctl stop listener-ap
fi
if systemctl is-enabled listener-ap >/dev/null 2>&1; then
  sudo systemctl disable listener-ap
fi
sudo rm -f /etc/systemd/system/listener-ap.service

# 2. Stop and disable ws-sync service
echo "[uninstall] Stopping ws-sync service..."
if systemctl is-active ws-sync >/dev/null 2>&1; then
  sudo systemctl stop ws-sync
fi
if systemctl is-enabled ws-sync >/dev/null 2>&1; then
  sudo systemctl disable ws-sync
fi
sudo rm -f /etc/systemd/system/ws-sync.service
sudo systemctl daemon-reload
echo "[uninstall] ws-sync service removed"

# 3. Remove Apache WebSocket proxy config
echo "[uninstall] Removing Apache listener config..."
sudo a2disconf listener 2>/dev/null || true
sudo rm -f /etc/apache2/conf-available/listener.conf
sudo rm -f /etc/apache2/conf-enabled/listener.conf
sudo systemctl restart apache2 2>/dev/null || true
echo "[uninstall] Apache listener config removed"

# 4. Remove sudoers entry (legacy, may not exist)
sudo rm -f /etc/sudoers.d/listener-sync

# 5. Remove web files
if [[ -n "$WEBROOT" ]]; then
  echo "[uninstall] Removing web files..."
  sudo rm -rf "$WEBROOT/listen"
  echo "[uninstall] Web files removed"

  # 6. Remove /music symlink (only if it's a symlink we created)
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

# 7. Remove listener config directory
echo "[uninstall] Removing listener config..."
if [[ -d "/home/fpp/listen-sync" ]]; then
  sudo rm -rf /home/fpp/listen-sync
  echo "[uninstall] Config directory removed"
fi

echo ""
echo "========================================="
echo "  Uninstall complete!"
echo "========================================="
echo "  All listener files, configs, and"
echo "  services have been removed."
echo "========================================="
