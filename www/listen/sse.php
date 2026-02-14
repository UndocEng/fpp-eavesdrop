<?php
/**
 * sse.php — Server-Sent Events relay for audio-in-FSEQ frame-locked playback.
 *
 * Companion-file approach: when FPP plays a light sequence (e.g. Elvis.fseq),
 * this script looks for a matching audio FSEQ file (Elvis_Audio.fseq) in the
 * audio-fseq directory. FPP never touches the audio file — sse.php reads it
 * directly at the matching frame position derived from FPP's seconds_played.
 *
 * The browser decodes the PCM samples via Web Audio API — zero drift.
 *
 * Query params:
 *   channels  = audio channels per frame (default 2206)
 *   fps       = target frame rate (default 40)
 */

// Prevent output buffering
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
while (ob_get_level()) ob_end_clean();

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('X-Accel-Buffering: no');

$channelsPerFrame = isset($_GET['channels']) ? intval($_GET['channels']) : 2206;
$fps = isset($_GET['fps']) ? intval($_GET['fps']) : 40;
$sleepUs = intval(1000000 / $fps);

// Audio FSEQ files live in a separate directory — FPP never plays them
$audioDir = '/home/fpp/media/audio-fseq/';
$fppStatusUrl = 'http://127.0.0.1/api/fppd/status';

$frameId = 0;
$lastSeqFile = '';
$fseqHandle = null;
$fseqDataOffset = 0;
$fseqChannelCount = 0;
$fseqFrameCount = 0;
$fseqStepTime = 0;

/**
 * Parse FSEQ v2 header from an open file handle.
 * Returns array with header fields or null on error.
 */
function parseFseqHeader($fh) {
    fseek($fh, 0);
    $magic = fread($fh, 4);
    if ($magic !== 'PSEQ') return null;

    $dataOffset = unpack('v', fread($fh, 2))[1];   // uint16 LE
    $minorVer = unpack('C', fread($fh, 1))[1];
    $majorVer = unpack('C', fread($fh, 1))[1];
    fread($fh, 2); // skip var header offset
    $channelCount = unpack('V', fread($fh, 4))[1];  // uint32 LE
    $frameCount = unpack('V', fread($fh, 4))[1];     // uint32 LE
    $stepTime = unpack('C', fread($fh, 1))[1];       // uint8

    return [
        'dataOffset'   => $dataOffset,
        'version'      => "$majorVer.$minorVer",
        'channelCount' => $channelCount,
        'frameCount'   => $frameCount,
        'stepTime'     => $stepTime,
    ];
}

/**
 * Read a single frame's channel data from the FSEQ file.
 */
function readFrame($fh, $dataOffset, $channelCount, $frameNum) {
    $offset = $dataOffset + ($frameNum * $channelCount);
    fseek($fh, $offset);
    $data = fread($fh, $channelCount);
    if ($data === false || strlen($data) < $channelCount) return null;
    return $data;
}

/**
 * Send an SSE event.
 */
function sendSSE($id, $event, $data) {
    echo "id: $id\n";
    echo "event: $event\n";
    echo "data: $data\n\n";
    flush();
}

/**
 * Find the companion audio FSEQ for a given sequence name.
 * Convention: "Elvis.fseq" -> look for "Elvis_Audio.fseq" in the audio dir.
 * Also tries exact match in case the audio file has the same name.
 */
function findAudioFseq($audioDir, $seqName) {
    $baseName = pathinfo($seqName, PATHINFO_FILENAME);

    // Try {basename}_Audio.fseq first
    $path = $audioDir . $baseName . '_Audio.fseq';
    if (file_exists($path)) return $path;

    // Try {basename}_audio.fseq (lowercase)
    $path = $audioDir . $baseName . '_audio.fseq';
    if (file_exists($path)) return $path;

    // Try exact sequence name in audio dir
    $path = $audioDir . $baseName . '.fseq';
    if (file_exists($path)) return $path;

    return null;
}

// Main loop
set_time_limit(0);
ignore_user_abort(false);

// Ensure audio directory exists
if (!is_dir($audioDir)) {
    @mkdir($audioDir, 0755, true);
}

while (!connection_aborted()) {
    $t0 = microtime(true);

    // Get FPP status
    $ctx = stream_context_create(['http' => ['timeout' => 0.5]]);
    $statusJson = @file_get_contents($fppStatusUrl, false, $ctx);

    if ($statusJson === false) {
        sendSSE($frameId++, 'error', json_encode(['msg' => 'FPP unreachable']));
        usleep($sleepUs);
        continue;
    }

    $status = json_decode($statusJson, true);
    if (!$status) {
        usleep($sleepUs);
        continue;
    }

    $fppStatus = isset($status['status']) ? $status['status'] : 0;
    // FPP status: 0=idle, 1=playing, 2=stopping
    $currentSeq = isset($status['current_sequence']) ? $status['current_sequence'] : '';
    $secPlayed = isset($status['seconds_played']) ? floatval($status['seconds_played']) : 0;

    // Not playing — send idle event
    if ($fppStatus == 0 || empty($currentSeq)) {
        if ($fseqHandle) { fclose($fseqHandle); $fseqHandle = null; }
        $lastSeqFile = '';
        sendSSE($frameId++, 'idle', json_encode(['status' => 'idle']));
        usleep($sleepUs);
        continue;
    }

    // Sequence changed — find matching audio FSEQ
    if ($currentSeq !== $lastSeqFile) {
        if ($fseqHandle) fclose($fseqHandle);
        $fseqHandle = null;

        $audioPath = findAudioFseq($audioDir, $currentSeq);

        if ($audioPath && file_exists($audioPath)) {
            $fseqHandle = fopen($audioPath, 'rb');
            if ($fseqHandle) {
                $hdr = parseFseqHeader($fseqHandle);
                if ($hdr) {
                    $fseqDataOffset = $hdr['dataOffset'];
                    $fseqChannelCount = $hdr['channelCount'];
                    $fseqFrameCount = $hdr['frameCount'];
                    $fseqStepTime = $hdr['stepTime'];
                    $lastSeqFile = $currentSeq;

                    $baseName = pathinfo($currentSeq, PATHINFO_FILENAME);
                    sendSSE($frameId++, 'seq_open', json_encode([
                        'file'         => $baseName,
                        'audioFile'    => basename($audioPath),
                        'channels'     => $fseqChannelCount,
                        'frames'       => $fseqFrameCount,
                        'stepTime'     => $fseqStepTime,
                    ]));
                } else {
                    fclose($fseqHandle);
                    $fseqHandle = null;
                }
            }
        }

        if (!$fseqHandle) {
            $baseName = pathinfo($currentSeq, PATHINFO_FILENAME);
            sendSSE($frameId++, 'no_audio', json_encode([
                'msg' => 'No audio FSEQ for: ' . $baseName,
                'hint' => 'Create with: python3 audio2fseq.py input.mp3 -o ' . $baseName . '_Audio.fseq'
            ]));
            $lastSeqFile = $currentSeq; // Don't spam — remember we checked this seq
            usleep($sleepUs);
            continue;
        }
    }

    // If no audio file for current sequence, just idle
    if (!$fseqHandle) {
        usleep($sleepUs);
        continue;
    }

    // Calculate current frame number from FPP's position
    $posMs = $secPlayed * 1000;
    $frameNum = intval($posMs / $fseqStepTime);
    if ($frameNum >= $fseqFrameCount) $frameNum = $fseqFrameCount - 1;
    if ($frameNum < 0) $frameNum = 0;

    // Read frame data from audio FSEQ
    $frameData = readFrame($fseqHandle, $fseqDataOffset, $fseqChannelCount, $frameNum);
    if ($frameData !== null) {
        $audioData = substr($frameData, 0, min($channelsPerFrame, $fseqChannelCount));
        sendSSE($frameId++, 'frame', base64_encode($audioData));
    }

    // Sleep for remainder of frame period
    $elapsed = (microtime(true) - $t0) * 1000000;
    $remaining = $sleepUs - $elapsed;
    if ($remaining > 1000) usleep(intval($remaining));
}

// Cleanup
if ($fseqHandle) fclose($fseqHandle);
