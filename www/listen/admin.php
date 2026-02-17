<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {
  case 'get_sequences':
    echo json_encode([
      "success" => true,
      "sequences" => getSequences(),
      "playlists" => getPlaylists()
    ]);
    break;
  case 'start_sequence':
    echo json_encode(startSequence($_POST['sequence'] ?? ''));
    break;
  case 'stop_playback':
    echo json_encode(stopPlayback());
    break;
  default:
    echo json_encode(["success" => false, "error" => "Unknown action"]);
}


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
