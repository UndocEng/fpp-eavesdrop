<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

// Eavesdrop version from deployed VERSION file
$eavesdropVer = 'unknown';
$versionFile = __DIR__ . '/VERSION';
if (file_exists($versionFile)) {
  $eavesdropVer = trim(file_get_contents($versionFile));
}

// FPP version from API
$fppVer = 'unknown';
$data = @json_decode(@file_get_contents('http://127.0.0.1/api/fppd/version'), true);
if (isset($data['version'])) {
  $fppVer = $data['version'];
}

echo json_encode([
  'eavesdrop' => $eavesdropVer,
  'fpp' => $fppVer
]);
