<?php

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');


function http_get_json($url) {
  $ctx = stream_context_create(['http' => ['timeout' => 1.0]]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) return null;
  $js = json_decode($raw, true);
  if (!is_array($js)) return null;
  return $js;
}


function basename_noext($path) {
  if (!$path) return "";
  $p = basename($path);
  return preg_replace('/\.[^.]+$/', '', $p);
}


$srcUrl = "http://127.0.0.1/api/fppd/status";

// Capture timing around FPP call for clock offset estimation
// Use round() not intval() - intval() overflows on 32-bit PHP (Pi 3B)
$server_ms_start = round(microtime(true) * 1000);
$src = http_get_json($srcUrl);
$server_ms_end = round(microtime(true) * 1000);
// Midpoint is best estimate of when pos_ms was valid
$server_ms = round(($server_ms_start + $server_ms_end) / 2);


if ($src === null) {
  echo json_encode([
    "state" => "stop",
    "base" => "",
    "pos_ms" => 0,
    "mp3_url" => "",
    "server_ms" => $server_ms,
    "debug" => "Cannot read $srcUrl"
  ]);
  exit;
}


$status = isset($src["status"]) ? intval($src["status"]) : -1;
$status_name = isset($src["status_name"]) ? strval($src["status_name"]) : "";

$state = "stop";
$sn = strtolower($status_name);
if ($sn === "playing" || $sn === "play") $state = "play";
else if ($sn === "paused" || $sn === "pause") $state = "pause";
else if ($sn === "idle" || $sn === "stopped" || $sn === "stop") $state = "stop";
else {
  if ($status === 1) $state = "play";
  else if ($status === 2) $state = "pause";
  else $state = "stop";
}


$seq = isset($src["current_sequence"]) ? strval($src["current_sequence"]) : "";
$base = basename_noext($seq);

// Use milliseconds_elapsed for frame-accurate position (seconds_played is whole-seconds only)
$pos_ms = isset($src["milliseconds_elapsed"]) ? round($src["milliseconds_elapsed"]) : 0;
$sec_played = $pos_ms / 1000.0;


// Check for audio file - prefer MP3, fall back to M4A, then other formats
$audio_url = "";
if ($base !== "") {
  $music_dir = "/home/fpp/media/music";
  $formats = ["mp3", "m4a", "mp4", "aac", "ogg", "wav"];

  foreach ($formats as $ext) {
    if (file_exists("$music_dir/$base.$ext")) {
      $audio_url = "/music/" . rawurlencode($base) . ".$ext";
      break;
    }
  }
}

$mp3_url = $audio_url;


echo json_encode([
  "state" => $state,
  "base" => $base,
  "pos_ms" => $pos_ms,
  "mp3_url" => $mp3_url,
  "server_ms" => $server_ms,
  "server_ms_start" => $server_ms_start,
  "server_ms_end" => $server_ms_end,
  "debug_status" => $status,
  "debug_status_name" => $status_name,
  "debug_seq" => $seq,
  "debug_seconds_played" => $sec_played
]);
