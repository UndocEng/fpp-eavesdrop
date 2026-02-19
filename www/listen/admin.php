<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

switch ($action) {
  case 'get_sequences':
    $iface = getAPInterface();
    $hasWlan = file_exists('/sys/class/net/wlan0') || file_exists('/sys/class/net/wlan1');
    $hasIface = file_exists('/sys/class/net/' . $iface);
    echo json_encode([
      "success" => true,
      "sequences" => getSequences(),
      "playlists" => getPlaylists(),
      "has_wlan" => $hasWlan,
      "has_wlan1" => $hasWlan,  // compat
      "ap_iface" => $iface,
      "current_ssid" => $hasIface ? getCurrentSSID() : ""
    ]);
    break;
  case 'start_sequence':
    echo json_encode(startSequence($_POST['sequence'] ?? ''));
    break;
  case 'stop_playback':
    echo json_encode(stopPlayback());
    break;
  case 'get_ap_config':
    echo json_encode(getAPConfig());
    break;
  case 'change_password':
    echo json_encode(changePassword($_POST['password'] ?? ''));
    break;
  case 'change_ssid':
    echo json_encode(changeSSID($_POST['ssid'] ?? ''));
    break;
  case 'change_ip':
    echo json_encode(changeIP($_POST['ip'] ?? ''));
    break;
  case 'change_interface':
    echo json_encode(changeInterface($_POST['iface'] ?? ''));
    break;
  case 'save_ap_config':
    echo json_encode(saveAPConfig(
      $_POST['iface'] ?? '',
      $_POST['ssid'] ?? '',
      $_POST['password'] ?? '',
      $_POST['ip'] ?? ''
    ));
    break;
  case 'get_ap_clients':
    echo json_encode(getAPClients());
    break;
  default:
    echo json_encode(["success" => false, "error" => "Unknown action"]);
}


// ── FPP Playback ────────────────────────────────────────────────────

function getPlaylists() {
  $ctx = stream_context_create(['http' => ['timeout' => 2.0]]);
  $raw = @file_get_contents('http://127.0.0.1/api/playlists', false, $ctx);
  if ($raw === false) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? array_values($data) : [];
}


function getSequences() {
  $ctx = stream_context_create(['http' => ['timeout' => 2.0]]);
  $raw = @file_get_contents('http://127.0.0.1/api/sequence', false, $ctx);
  if ($raw === false) return [];
  $data = json_decode($raw, true);
  if (!is_array($data)) return [];
  // Return with .fseq extension for Start Playlist command
  return array_map(function($s) { return $s . '.fseq'; }, array_values($data));
}


function startSequence($name) {
  if ($name === '') {
    return ["success" => false, "error" => "Nothing selected"];
  }
  // "Start Playlist" works for both playlists and .fseq sequences
  return sendFPPCommand([
    "command" => "Start Playlist",
    "args" => [$name]
  ]);
}


function stopPlayback() {
  return sendFPPCommand(["command" => "Stop Now"]);
}


function sendFPPCommand($cmd) {
  $json = json_encode($cmd);
  $opts = [
    'http' => [
      'method' => 'POST',
      'header' => "Content-Type: application/json\r\n",
      'content' => $json,
      'timeout' => 3.0
    ]
  ];
  $ctx = stream_context_create($opts);
  $result = @file_get_contents('http://127.0.0.1/api/command', false, $ctx);
  if ($result === false) {
    return ["success" => false, "error" => "FPP command failed"];
  }
  return ["success" => true];
}


// ── WiFi Access Point ───────────────────────────────────────────────

function getFPPTetherState() {
  $ctx = stream_context_create(['http' => ['timeout' => 2.0]]);
  $raw = @file_get_contents('http://127.0.0.1/api/settings/EnableTethering', false, $ctx);
  if ($raw === false) return null;
  $val = json_decode($raw, true);
  // API returns full settings object with "value" key
  if (is_array($val) && isset($val['value'])) return strval($val['value']);
  // Or it might return just the value as a string
  if (is_string($val)) return $val;
  return null;
}

function setFPPTether($mode) {
  // $mode: "0" = conditional, "1" = enabled, "2" = disabled
  $opts = [
    'http' => [
      'method' => 'PUT',
      'header' => "Content-Type: application/json\r\n",
      'content' => json_encode($mode),
      'timeout' => 3.0
    ]
  ];
  $ctx = stream_context_create($opts);
  $result = @file_get_contents('http://127.0.0.1/api/settings/EnableTethering', false, $ctx);
  if ($result === false) return false;
  $data = json_decode($result, true);
  return isset($data['status']) && $data['status'] === 'OK';
}

function disableFPPTetherForSBS() {
  // Disable FPP tethering via API (persists across reboots)
  setFPPTether("2");
  // Stop FPP's hostapd service if running (prevent wlan0 conflict)
  exec("sudo /usr/bin/systemctl stop hostapd 2>/dev/null", $out, $ret);
}

function restoreFPPTether() {
  // Restore tethering to conditional mode (FPP default)
  setFPPTether("0");
}

function getAPInterface() {
  $apConf = "/home/fpp/listen-sync/ap.conf";
  if (file_exists($apConf)) {
    $lines = file($apConf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, 'WLAN_IF=') === 0) {
          return substr($line, 8);
        }
      }
    }
  }
  return 'wlan1';
}

function getCurrentSSID() {
  $conf = "/home/fpp/listen-sync/hostapd-listener.conf";
  $lines = @file($conf, FILE_IGNORE_NEW_LINES);
  if ($lines === false) return "";
  foreach ($lines as $line) {
    if (strpos($line, 'ssid=') === 0) {
      return substr($line, 5);
    }
  }
  return "";
}


function getAPConfig() {
  $iface = getAPInterface();
  $hasIface = file_exists('/sys/class/net/' . $iface);
  $hasWlan = file_exists('/sys/class/net/wlan0') || file_exists('/sys/class/net/wlan1');

  $result = [
    "success" => true,
    "has_wlan1" => $hasWlan,  // compat
    "has_wlan" => $hasWlan,
    "has_iface" => $hasIface,
    "ap_iface" => $iface,
    "ssid" => "",
    "ip" => "192.168.50.1",
    "netmask" => "24",
    "service_active" => false
  ];

  if (!$hasIface) return $result;

  // Read SSID from hostapd config
  $result["ssid"] = getCurrentSSID();

  // Read IP from ap.conf
  $apConf = "/home/fpp/listen-sync/ap.conf";
  if (file_exists($apConf)) {
    $lines = file($apConf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, 'AP_IP=') === 0) {
          $result["ip"] = substr($line, 6);
        }
        if (strpos($line, 'AP_MASK=') === 0) {
          $result["netmask"] = substr($line, 8);
        }
      }
    }
  }

  // Check if service is running
  exec("systemctl is-active listener-ap 2>/dev/null", $out, $ret);
  $result["service_active"] = ($ret === 0);

  // Check for subnet conflict with other interfaces
  $result["subnet_conflict"] = checkSubnetConflict($result["ip"], intval($result["netmask"]));

  // Report FPP tether state
  $tetherState = getFPPTetherState();
  $result["tether_state"] = $tetherState;
  $tetherLabels = ["0" => "conditional", "1" => "enabled", "2" => "disabled"];
  $result["tether_label"] = isset($tetherLabels[$tetherState]) ? $tetherLabels[$tetherState] : "unknown";

  return $result;
}


function checkSubnetConflict($apIP, $cidr) {
  // Compute AP network address
  $apLong = ip2long($apIP);
  if ($apLong === false) return null;
  $mask = $cidr > 0 ? (~0 << (32 - $cidr)) : 0;
  $apNetwork = $apLong & $mask;

  // Read all interface IPs
  $output = [];
  exec("ip -4 addr show 2>/dev/null", $output);
  $conflicts = [];
  $currentIface = '';
  foreach ($output as $line) {
    // Match interface name lines (e.g., "2: eth0: <...")
    if (preg_match('/^\d+:\s+(\S+):/', $line, $m)) {
      $currentIface = $m[1];
    }
    // Match inet lines (e.g., "    inet 10.1.66.204/24 ...")
    if (preg_match('/inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $m)) {
      $ifIP = $m[1];
      $ifCidr = intval($m[2]);
      // Skip our AP interface and loopback
      $apIface = getAPInterface();
      if ($currentIface === $apIface || $currentIface === 'lo') continue;
      $ifLong = ip2long($ifIP);
      if ($ifLong === false) continue;
      $ifMask = $ifCidr > 0 ? (~0 << (32 - $ifCidr)) : 0;
      $ifNetwork = $ifLong & $ifMask;
      // Check if networks overlap (compare using the smaller mask)
      $checkMask = ($mask & $ifMask);
      if (($apLong & $checkMask) === ($ifLong & $checkMask)) {
        $conflicts[] = $currentIface . " (" . $ifIP . "/" . $ifCidr . ")";
      }
    }
  }
  if (empty($conflicts)) return null;
  return "AP subnet overlaps with: " . implode(", ", $conflicts) .
    ". Clients may have trouble reaching the FPP at those addresses.";
}


function changePassword($newPass) {
  if (strlen($newPass) < 8 || strlen($newPass) > 63) {
    return ["success" => false, "error" => "Password must be 8-63 characters"];
  }
  // Only allow printable ASCII, no quotes or backslashes
  if (!preg_match('/^[a-zA-Z0-9 !#$%&()*+,\-.\/0-9:;<=>?@\[\]^_`{|}~]+$/', $newPass)) {
    return ["success" => false, "error" => "Password contains invalid characters"];
  }

  $conf = "/home/fpp/listen-sync/hostapd-listener.conf";

  $lines = @file($conf, FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    return ["success" => false, "error" => "Cannot read config"];
  }

  $newLines = [];
  $found = false;
  foreach ($lines as $line) {
    if (strpos($line, 'wpa_passphrase=') === 0) {
      $newLines[] = "wpa_passphrase=" . $newPass;
      $found = true;
    } else {
      $newLines[] = $line;
    }
  }
  if (!$found) {
    $newLines[] = "wpa_passphrase=" . $newPass;
  }

  $content = implode("\n", $newLines) . "\n";
  $tmpFile = tempnam(sys_get_temp_dir(), 'hostapd_');
  file_put_contents($tmpFile, $content);

  exec("sudo /usr/bin/tee /home/fpp/listen-sync/hostapd-listener.conf < " . escapeshellarg($tmpFile) . " > /dev/null 2>&1", $out, $ret);
  unlink($tmpFile);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to write config"];
  }

  exec("sudo /usr/bin/systemctl restart listener-ap.service 2>&1", $out, $ret);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to restart AP"];
  }

  return ["success" => true, "message" => "Password changed. AP restarting -- reconnect with new password."];
}


function changeSSID($newSSID) {
  $newSSID = trim($newSSID);
  if (strlen($newSSID) < 1 || strlen($newSSID) > 32) {
    return ["success" => false, "error" => "SSID must be 1-32 characters"];
  }
  if (!preg_match('/^[a-zA-Z0-9 _\-]+$/', $newSSID)) {
    return ["success" => false, "error" => "SSID can only contain letters, numbers, spaces, hyphens, underscores"];
  }

  $conf = "/home/fpp/listen-sync/hostapd-listener.conf";
  $lines = @file($conf, FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    return ["success" => false, "error" => "Cannot read config"];
  }

  $newLines = [];
  $found = false;
  foreach ($lines as $line) {
    if (strpos($line, 'ssid=') === 0) {
      $newLines[] = "ssid=" . $newSSID;
      $found = true;
    } else {
      $newLines[] = $line;
    }
  }
  if (!$found) {
    $newLines[] = "ssid=" . $newSSID;
  }

  $content = implode("\n", $newLines) . "\n";
  $tmpFile = tempnam(sys_get_temp_dir(), 'hostapd_');
  file_put_contents($tmpFile, $content);

  exec("sudo /usr/bin/tee /home/fpp/listen-sync/hostapd-listener.conf < " . escapeshellarg($tmpFile) . " > /dev/null 2>&1", $out, $ret);
  unlink($tmpFile);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to write config"];
  }

  exec("sudo /usr/bin/systemctl restart listener-ap.service 2>&1", $out, $ret);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to restart AP"];
  }

  return ["success" => true, "message" => "SSID changed to \"" . $newSSID . "\". AP restarting -- reconnect to the new network name."];
}


function changeIP($newIP) {
  $newIP = trim($newIP);

  // Validate IPv4 format
  if (!filter_var($newIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    return ["success" => false, "error" => "Invalid IPv4 address"];
  }

  // Reject loopback, multicast, 0.0.0.0
  $parts = explode('.', $newIP);
  if ($parts[0] === '0' || $parts[0] === '127' || intval($parts[0]) >= 224) {
    return ["success" => false, "error" => "Reserved IP address"];
  }

  // Read existing netmask from ap.conf (preserve it)
  $apConf = "/home/fpp/listen-sync/ap.conf";
  $mask = "24";
  if (file_exists($apConf)) {
    $lines = @file($apConf, FILE_IGNORE_NEW_LINES);
    if ($lines !== false) {
      foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, 'AP_MASK=') === 0) {
          $mask = substr($line, 8);
        }
      }
    }
  }

  $content = "# AP Configuration for FPP Eavesdrop\n";
  $content .= "AP_IP=" . $newIP . "\n";
  $content .= "AP_MASK=" . $mask . "\n";

  $tmpFile = tempnam(sys_get_temp_dir(), 'apconf_');
  file_put_contents($tmpFile, $content);

  exec("sudo /usr/bin/tee /home/fpp/listen-sync/ap.conf < " . escapeshellarg($tmpFile) . " > /dev/null 2>&1", $out, $ret);
  unlink($tmpFile);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to write AP config"];
  }

  exec("sudo /usr/bin/systemctl restart listener-ap.service 2>&1", $out, $ret);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to restart AP"];
  }

  return ["success" => true, "message" => "IP changed to " . $newIP . ". AP restarting -- reconnect and browse to http://" . $newIP . "/listen/"];
}


function saveAPConfig($iface, $ssid, $password, $ip) {
  $errors = [];
  $changes = [];

  // Validate all provided fields first (before writing anything)
  $iface = trim($iface);
  $ssid = trim($ssid);
  $password = $password; // don't trim passwords
  $ip = trim($ip);

  if ($iface && $iface !== 'wlan0' && $iface !== 'wlan1') {
    return ["success" => false, "error" => "Interface must be wlan0 or wlan1"];
  }
  if ($ssid && (strlen($ssid) < 1 || strlen($ssid) > 32)) {
    return ["success" => false, "error" => "SSID must be 1-32 characters"];
  }
  if ($ssid && !preg_match('/^[a-zA-Z0-9 _\-]+$/', $ssid)) {
    return ["success" => false, "error" => "SSID can only contain letters, numbers, spaces, hyphens, underscores"];
  }
  if ($password && (strlen($password) < 8 || strlen($password) > 63)) {
    return ["success" => false, "error" => "Password must be 8-63 characters"];
  }
  if ($password && !preg_match('/^[a-zA-Z0-9 !#$%&()*+,\-.\/0-9:;<=>?@\[\]^_`{|}~]+$/', $password)) {
    return ["success" => false, "error" => "Password contains invalid characters"];
  }
  if ($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
      return ["success" => false, "error" => "Invalid IPv4 address"];
    }
    $parts = explode('.', $ip);
    if ($parts[0] === '0' || $parts[0] === '127' || intval($parts[0]) >= 224) {
      return ["success" => false, "error" => "Reserved IP address"];
    }
  }

  // Apply interface + IP changes to ap.conf (single write)
  if ($iface || $ip) {
    $apConf = "/home/fpp/listen-sync/ap.conf";
    $lines = @file($apConf, FILE_IGNORE_NEW_LINES);
    $newLines = [];
    $foundIface = false;
    $foundIP = false;
    if ($lines !== false) {
      foreach ($lines as $line) {
        if ($iface && strpos(trim($line), 'WLAN_IF=') === 0) {
          $newLines[] = "WLAN_IF=" . $iface;
          $foundIface = true;
        } elseif ($ip && strpos(trim($line), 'AP_IP=') === 0) {
          $newLines[] = "AP_IP=" . $ip;
          $foundIP = true;
        } else {
          $newLines[] = $line;
        }
      }
    }
    if ($iface && !$foundIface) $newLines[] = "WLAN_IF=" . $iface;
    if ($ip && !$foundIP) $newLines[] = "AP_IP=" . $ip;

    $content = implode("\n", $newLines) . "\n";
    $tmpFile = tempnam(sys_get_temp_dir(), 'apconf_');
    file_put_contents($tmpFile, $content);
    exec("sudo /usr/bin/tee /home/fpp/listen-sync/ap.conf < " . escapeshellarg($tmpFile) . " > /dev/null 2>&1", $out, $ret);
    unlink($tmpFile);
    if ($ret !== 0) return ["success" => false, "error" => "Failed to write AP config"];
    if ($iface) $changes[] = "interface=" . $iface;
    if ($ip) $changes[] = "IP=" . $ip;
  }

  // Apply SSID, password, and interface changes to hostapd config (single write)
  if ($ssid || $password || $iface) {
    $hostapdConf = "/home/fpp/listen-sync/hostapd-listener.conf";
    $hLines = @file($hostapdConf, FILE_IGNORE_NEW_LINES);
    if ($hLines !== false) {
      $hNewLines = [];
      foreach ($hLines as $line) {
        if ($ssid && strpos($line, 'ssid=') === 0) {
          $hNewLines[] = "ssid=" . $ssid;
        } elseif ($password && strpos($line, 'wpa_passphrase=') === 0) {
          $hNewLines[] = "wpa_passphrase=" . $password;
        } elseif ($iface && strpos($line, 'interface=') === 0) {
          $hNewLines[] = "interface=" . $iface;
        } else {
          $hNewLines[] = $line;
        }
      }
      $hContent = implode("\n", $hNewLines) . "\n";
      $tmpFile2 = tempnam(sys_get_temp_dir(), 'hostapd_');
      file_put_contents($tmpFile2, $hContent);
      exec("sudo /usr/bin/tee /home/fpp/listen-sync/hostapd-listener.conf < " . escapeshellarg($tmpFile2) . " > /dev/null 2>&1", $out, $ret);
      unlink($tmpFile2);
      if ($ret !== 0) return ["success" => false, "error" => "Failed to write hostapd config"];
      if ($ssid) $changes[] = "SSID=" . $ssid;
      if ($password) $changes[] = "password changed";
    }
  }

  // Handle FPP tether when interface changes
  if ($iface) {
    $currentIface = getAPInterface();  // re-read after write
    if ($iface === 'wlan0') {
      disableFPPTetherForSBS();
    } elseif ($iface === 'wlan1') {
      restoreFPPTether();
    }
  }

  // Restart AP service
  exec("sudo /usr/bin/systemctl restart listener-ap.service 2>&1", $out, $ret);

  $msg = "AP config saved and service restarting.";
  if ($iface === 'wlan0') $msg .= " SBS mode active -- FPP tether disabled.";
  elseif ($iface === 'wlan1') $msg .= " FPP tether restored to default.";
  if ($ip) $msg .= " New AP address: http://" . $ip . "/listen/";

  return ["success" => true, "message" => $msg];
}


function changeInterface($newIface) {
  $newIface = trim($newIface);
  if ($newIface !== 'wlan0' && $newIface !== 'wlan1') {
    return ["success" => false, "error" => "Interface must be wlan0 or wlan1"];
  }

  if (!file_exists('/sys/class/net/' . $newIface)) {
    return ["success" => false, "error" => $newIface . " is not present on this system"];
  }

  // Update WLAN_IF in ap.conf (preserve other values)
  $apConf = "/home/fpp/listen-sync/ap.conf";
  $lines = @file($apConf, FILE_IGNORE_NEW_LINES);
  $newLines = [];
  $found = false;
  if ($lines !== false) {
    foreach ($lines as $line) {
      if (strpos(trim($line), 'WLAN_IF=') === 0) {
        $newLines[] = "WLAN_IF=" . $newIface;
        $found = true;
      } else {
        $newLines[] = $line;
      }
    }
  }
  if (!$found) {
    array_unshift($newLines, "WLAN_IF=" . $newIface);
  }

  $content = implode("\n", $newLines) . "\n";
  $tmpFile = tempnam(sys_get_temp_dir(), 'apconf_');
  file_put_contents($tmpFile, $content);
  exec("sudo /usr/bin/tee /home/fpp/listen-sync/ap.conf < " . escapeshellarg($tmpFile) . " > /dev/null 2>&1", $out, $ret);
  unlink($tmpFile);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to write AP config"];
  }

  // Update interface= line in hostapd config
  $hostapdConf = "/home/fpp/listen-sync/hostapd-listener.conf";
  $hLines = @file($hostapdConf, FILE_IGNORE_NEW_LINES);
  if ($hLines !== false) {
    $hNewLines = [];
    foreach ($hLines as $line) {
      if (strpos($line, 'interface=') === 0) {
        $hNewLines[] = "interface=" . $newIface;
      } else {
        $hNewLines[] = $line;
      }
    }
    $hContent = implode("\n", $hNewLines) . "\n";
    $tmpFile2 = tempnam(sys_get_temp_dir(), 'hostapd_');
    file_put_contents($tmpFile2, $hContent);
    exec("sudo /usr/bin/tee /home/fpp/listen-sync/hostapd-listener.conf < " . escapeshellarg($tmpFile2) . " > /dev/null 2>&1", $out, $ret);
    unlink($tmpFile2);
  }

  // Handle FPP tether when interface changes
  if ($newIface === 'wlan0') {
    disableFPPTetherForSBS();
  } elseif ($newIface === 'wlan1') {
    restoreFPPTether();
  }

  exec("sudo /usr/bin/systemctl restart listener-ap.service 2>&1", $out, $ret);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to restart AP"];
  }

  $msg = "AP switched to " . $newIface . ". Service restarting.";
  if ($newIface === 'wlan0') {
    $msg .= " SBS mode active -- FPP tether disabled.";
  } else {
    $msg .= " FPP tether restored to default.";
  }
  return ["success" => true, "message" => $msg];
}


// ── AP Connected Clients ─────────────────────────────────────────────

function getAPClients() {
  $iface = getAPInterface();
  if (!file_exists('/sys/class/net/' . $iface)) {
    return ["success" => true, "clients" => [], "count" => 0];
  }

  $clients = [];

  // Read DHCP leases (timestamp mac ip hostname client-id)
  $leases = @file("/var/lib/misc/dnsmasq.leases", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  $leaseMap = [];
  if ($leases !== false) {
    foreach ($leases as $line) {
      $parts = preg_split('/\s+/', $line, 5);
      if (count($parts) >= 4) {
        $leaseMap[strtolower($parts[1])] = [
          "ip" => $parts[2],
          "hostname" => ($parts[3] !== '*') ? $parts[3] : ''
        ];
      }
    }
  }

  // Read AP interface ARP table from /proc/net/arp
  $arp = @file("/proc/net/arp", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($arp !== false) {
    foreach ($arp as $i => $line) {
      if ($i === 0) continue; // skip header
      $parts = preg_split('/\s+/', $line);
      // columns: IP, HW type, Flags, HW address, Mask, Device
      if (count($parts) >= 6 && $parts[5] === $iface && $parts[2] !== '0x0') {
        $mac = strtolower($parts[3]);
        $client = [
          "mac" => $mac,
          "ip" => $parts[0],
          "hostname" => ""
        ];
        if (isset($leaseMap[$mac])) {
          $client["hostname"] = $leaseMap[$mac]["hostname"];
        }
        $clients[] = $client;
      }
    }
  }

  return ["success" => true, "clients" => $clients, "count" => count($clients)];
}
