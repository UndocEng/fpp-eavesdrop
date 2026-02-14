#!/usr/bin/env python3
"""
audio2fseq.py — Encode audio into FSEQ v2 channel data for frame-locked playback.

Converts WAV/MP3 audio into an FSEQ v2 file where each frame's channel data
contains raw PCM samples. FPP plays this sequence and streams the channels
via HTTPVirtualDisplay SSE, allowing browsers to decode and play audio
in perfect sync with the light show's master clock.

Channel layout per frame (default 40fps, 44100Hz mono):
  [0]    = 0xAA  (sync marker high)
  [1]    = 0x55  (sync marker low)
  [2..N] = PCM sample bytes (high byte, low byte per 16-bit sample)

Usage:
  python3 audio2fseq.py input.wav -o audio.fseq
  python3 audio2fseq.py input.mp3 -o audio.fseq --fps 40
  python3 audio2fseq.py input.mp3 --merge lights.fseq -o merged.fseq
"""

import argparse
import struct
import sys
import os
import wave
import array

SYNC_MARKER = bytes([0xAA, 0x55])
FSEQ_MAGIC = b"PSEQ"


def read_wav(path):
    """Read a WAV file and return (samples, sample_rate, channels, sample_width).
    Returns 16-bit signed integer samples as a list."""
    with wave.open(path, "rb") as wf:
        n_channels = wf.getnchannels()
        sample_width = wf.getsampwidth()
        sample_rate = wf.getframerate()
        n_frames = wf.getnframes()
        raw = wf.readframes(n_frames)

    if sample_width == 1:
        # 8-bit unsigned -> 16-bit signed
        samples = array.array("b", [int(b) - 128 for b in raw])
        samples = array.array("h", [s * 256 for s in samples])
    elif sample_width == 2:
        samples = array.array("h")
        samples.frombytes(raw)
    elif sample_width == 3:
        # 24-bit -> 16-bit (take top 2 bytes)
        samples = array.array("h")
        for i in range(0, len(raw), 3):
            val = int.from_bytes(raw[i : i + 3], byteorder="little", signed=True)
            samples.append(val >> 8)
    elif sample_width == 4:
        # 32-bit -> 16-bit
        tmp = array.array("i")
        tmp.frombytes(raw)
        samples = array.array("h", [s >> 16 for s in tmp])
    else:
        raise ValueError(f"Unsupported sample width: {sample_width}")

    return list(samples), sample_rate, n_channels, 2


def read_audio(path):
    """Read audio file. Supports WAV natively, MP3 via pydub if available."""
    ext = os.path.splitext(path)[1].lower()

    if ext == ".wav":
        return read_wav(path)

    # Try pydub for MP3 and other formats
    try:
        from pydub import AudioSegment
    except ImportError:
        print(
            "Error: pydub is required for non-WAV files. Install with: pip install pydub",
            file=sys.stderr,
        )
        print("(Also requires ffmpeg installed on system)", file=sys.stderr)
        sys.exit(1)

    audio = AudioSegment.from_file(path)
    audio = audio.set_sample_width(2)  # 16-bit
    raw = audio.raw_data
    samples = array.array("h")
    samples.frombytes(raw)
    return list(samples), audio.frame_rate, audio.channels, 2


def to_mono(samples, n_channels):
    """Mix multi-channel audio down to mono."""
    if n_channels == 1:
        return samples
    mono = []
    for i in range(0, len(samples), n_channels):
        chunk = samples[i : i + n_channels]
        mono.append(sum(chunk) // len(chunk))
    return mono


def resample_linear(samples, src_rate, dst_rate):
    """Simple linear interpolation resampler."""
    if src_rate == dst_rate:
        return samples
    ratio = src_rate / dst_rate
    out_len = int(len(samples) * dst_rate / src_rate)
    out = []
    for i in range(out_len):
        src_pos = i * ratio
        idx = int(src_pos)
        frac = src_pos - idx
        if idx + 1 < len(samples):
            val = samples[idx] * (1 - frac) + samples[idx + 1] * frac
        else:
            val = samples[min(idx, len(samples) - 1)]
        out.append(int(max(-32768, min(32767, val))))
    return out


def samples_to_frame_channels(samples, samples_per_frame):
    """Convert PCM samples into frame channel data with sync markers.
    Each frame: [0xAA, 0x55, hi0, lo0, hi1, lo1, ...]"""
    frames = []
    total_samples = len(samples)
    frame_idx = 0

    while frame_idx * samples_per_frame < total_samples:
        start = frame_idx * samples_per_frame
        end = start + samples_per_frame
        chunk = samples[start:end]

        # Pad last frame with silence if needed
        if len(chunk) < samples_per_frame:
            chunk.extend([0] * (samples_per_frame - len(chunk)))

        frame_data = bytearray(SYNC_MARKER)
        for sample in chunk:
            # Convert signed 16-bit to unsigned for byte storage
            if sample < 0:
                sample += 65536
            frame_data.append((sample >> 8) & 0xFF)  # high byte
            frame_data.append(sample & 0xFF)  # low byte

        frames.append(bytes(frame_data))
        frame_idx += 1

    return frames


def build_variable_header(audio_filename, start_channel=0):
    """Build FSEQ v2 variable header with metadata tags."""
    tags = bytearray()

    # 'mf' tag — media/audio filename
    mf_code = b"mf"
    mf_value = os.path.basename(audio_filename).encode("utf-8") + b"\x00"
    tags.extend(mf_code)
    tags.extend(struct.pack("<H", len(mf_value)))
    tags.extend(mf_value)

    # 'sp' tag — source/producer
    sp_code = b"sp"
    sp_value = b"fpp-eavesdrop audio2fseq\x00"
    tags.extend(sp_code)
    tags.extend(struct.pack("<H", len(sp_value)))
    tags.extend(sp_value)

    return bytes(tags)


def write_fseq_v2(path, frames, channels_per_frame, step_time_ms, variable_header):
    """Write a valid FSEQ v2 uncompressed file."""
    frame_count = len(frames)

    # Calculate header offset (must be aligned to 4 bytes)
    fixed_header_size = 32
    var_header_size = len(variable_header)
    channel_data_offset = fixed_header_size + var_header_size
    # Align to 4 bytes
    padding = (4 - (channel_data_offset % 4)) % 4
    channel_data_offset += padding

    # Build fixed header (32 bytes)
    header = bytearray()
    header.extend(FSEQ_MAGIC)  # 0-3: magic "PSEQ"
    header.extend(
        struct.pack("<H", channel_data_offset)
    )  # 4-5: offset to channel data
    header.extend(struct.pack("<B", 0))  # 6: minor version (0)
    header.extend(struct.pack("<B", 2))  # 7: major version (2)
    # 8-9: variable header offset (right after fixed 32-byte header)
    header.extend(struct.pack("<H", fixed_header_size))
    header.extend(
        struct.pack("<I", channels_per_frame)
    )  # 10-13: channel count per frame
    header.extend(struct.pack("<I", frame_count))  # 14-17: frame count
    header.extend(struct.pack("<B", step_time_ms))  # 18: step time in ms
    header.extend(struct.pack("<B", 0))  # 19: flags (0 = no compression)
    header.extend(
        struct.pack("<B", 0)
    )  # 20: compression type (0 = uncompressed)
    header.extend(
        struct.pack("<B", 0)
    )  # 21: number of compression blocks (0 for uncompressed)
    header.extend(
        struct.pack("<B", 0)
    )  # 22: number of sparse ranges (0 = not sparse)
    header.extend(struct.pack("<B", 0))  # 23: reserved/flags
    header.extend(struct.pack("<Q", 0))  # 24-31: unique ID (0)

    assert len(header) == 32, f"Header size mismatch: {len(header)}"

    with open(path, "wb") as f:
        f.write(header)
        f.write(variable_header)
        # Padding
        if padding > 0:
            f.write(b"\x00" * padding)
        # Channel data — one frame after another
        for frame in frames:
            assert len(frame) == channels_per_frame, (
                f"Frame size mismatch: {len(frame)} != {channels_per_frame}"
            )
            f.write(frame)

    total_size = channel_data_offset + (frame_count * channels_per_frame)
    return total_size


def read_fseq_header(path):
    """Read an FSEQ v2 file header. Returns dict with header fields."""
    with open(path, "rb") as f:
        magic = f.read(4)
        if magic != FSEQ_MAGIC:
            raise ValueError(f"Not an FSEQ file (magic: {magic})")

        data_offset = struct.unpack("<H", f.read(2))[0]
        minor_ver = struct.unpack("<B", f.read(1))[0]
        major_ver = struct.unpack("<B", f.read(1))[0]
        var_header_offset = struct.unpack("<H", f.read(2))[0]
        channel_count = struct.unpack("<I", f.read(4))[0]
        frame_count = struct.unpack("<I", f.read(4))[0]
        step_time = struct.unpack("<B", f.read(1))[0]
        flags = struct.unpack("<B", f.read(1))[0]
        compression = struct.unpack("<B", f.read(1))[0]
        comp_blocks = struct.unpack("<B", f.read(1))[0]
        sparse_ranges = struct.unpack("<B", f.read(1))[0]

    return {
        "data_offset": data_offset,
        "version": (major_ver, minor_ver),
        "var_header_offset": var_header_offset,
        "channel_count": channel_count,
        "frame_count": frame_count,
        "step_time": step_time,
        "flags": flags,
        "compression": compression,
        "comp_blocks": comp_blocks,
        "sparse_ranges": sparse_ranges,
    }


def merge_into_fseq(audio_frames, audio_channels_per_frame, merge_path, output_path, start_channel):
    """Merge audio channel data into an existing FSEQ file at a channel offset."""
    info = read_fseq_header(merge_path)

    if info["compression"] != 0:
        print(
            "Error: Cannot merge into compressed FSEQ files. Decompress first.",
            file=sys.stderr,
        )
        sys.exit(1)

    if info["version"][0] != 2:
        print(
            f"Error: Only FSEQ v2 supported, got v{info['version'][0]}.{info['version'][1]}",
            file=sys.stderr,
        )
        sys.exit(1)

    light_channel_count = info["channel_count"]
    light_frame_count = info["frame_count"]
    audio_frame_count = len(audio_frames)

    # Use the longer of the two for total frames
    total_frames = max(light_frame_count, audio_frame_count)
    # Total channels = max of (light channels, start_channel + audio channels)
    total_channels = max(light_channel_count, start_channel + audio_channels_per_frame)

    print(f"  Light sequence: {light_channel_count} ch × {light_frame_count} frames")
    print(f"  Audio data: {audio_channels_per_frame} ch × {audio_frame_count} frames @ ch {start_channel}")
    print(f"  Merged output: {total_channels} ch × {total_frames} frames")

    # Read the original file's channel data
    with open(merge_path, "rb") as f:
        f.seek(info["data_offset"])
        light_data = f.read()

    # Build variable header for merged file
    var_header = build_variable_header("merged", start_channel)

    # Calculate new header
    fixed_header_size = 32
    var_header_size = len(var_header)
    channel_data_offset = fixed_header_size + var_header_size
    padding = (4 - (channel_data_offset % 4)) % 4
    channel_data_offset += padding

    # Build new fixed header
    header = bytearray()
    header.extend(FSEQ_MAGIC)
    header.extend(struct.pack("<H", channel_data_offset))
    header.extend(struct.pack("<B", 0))  # minor version
    header.extend(struct.pack("<B", 2))  # major version
    header.extend(struct.pack("<H", fixed_header_size))
    header.extend(struct.pack("<I", total_channels))
    header.extend(struct.pack("<I", total_frames))
    header.extend(struct.pack("<B", info["step_time"]))
    header.extend(struct.pack("<B", 0))  # flags
    header.extend(struct.pack("<B", 0))  # no compression
    header.extend(struct.pack("<B", 0))  # no compression blocks
    header.extend(struct.pack("<B", 0))  # no sparse ranges
    header.extend(struct.pack("<B", 0))  # reserved
    header.extend(struct.pack("<Q", 0))  # unique ID

    with open(output_path, "wb") as f:
        f.write(header)
        f.write(var_header)
        if padding > 0:
            f.write(b"\x00" * padding)

        for frame_i in range(total_frames):
            # Start with zeros for all channels
            frame = bytearray(total_channels)

            # Copy light data for this frame
            if frame_i < light_frame_count:
                light_start = frame_i * light_channel_count
                light_end = light_start + light_channel_count
                if light_end <= len(light_data):
                    frame[0:light_channel_count] = light_data[light_start:light_end]

            # Overlay audio data at start_channel offset
            if frame_i < audio_frame_count:
                audio_data = audio_frames[frame_i]
                frame[start_channel : start_channel + audio_channels_per_frame] = (
                    audio_data
                )

            f.write(frame)

    total_size = channel_data_offset + (total_frames * total_channels)
    return total_size, total_channels, total_frames


def main():
    parser = argparse.ArgumentParser(
        description="Encode audio into FSEQ v2 channel data for frame-locked playback.",
        epilog="Example: python3 audio2fseq.py song.wav -o audio.fseq --fps 40",
    )
    parser.add_argument("input", help="Input audio file (WAV, or MP3 if pydub installed)")
    parser.add_argument("-o", "--output", required=True, help="Output .fseq file path")
    parser.add_argument(
        "--fps",
        type=int,
        default=40,
        help="Frame rate in fps (default: 40). Determines step time.",
    )
    parser.add_argument(
        "--step-time",
        type=int,
        default=None,
        help="Step time in ms (overrides --fps if provided)",
    )
    parser.add_argument(
        "--sample-rate",
        type=int,
        default=44100,
        help="Output sample rate in Hz (default: 44100)",
    )
    parser.add_argument(
        "--merge",
        default=None,
        help="Path to existing .fseq to merge audio into (at --start-channel offset)",
    )
    parser.add_argument(
        "--start-channel",
        type=int,
        default=500000,
        help="Channel offset for merged mode (default: 500000)",
    )
    parser.add_argument(
        "--stereo",
        action="store_true",
        help="Keep stereo (default is mono to save channels)",
    )
    parser.add_argument(
        "-v", "--verbose", action="store_true", help="Verbose output"
    )

    args = parser.parse_args()

    # Calculate step time
    if args.step_time is not None:
        step_time_ms = args.step_time
    else:
        step_time_ms = 1000 // args.fps

    fps = 1000 / step_time_ms
    target_rate = args.sample_rate

    print(f"Audio-to-FSEQ Encoder")
    print(f"  Input:       {args.input}")
    print(f"  Output:      {args.output}")
    print(f"  FPS:         {fps} ({step_time_ms}ms step)")
    print(f"  Sample rate: {target_rate} Hz")
    print(f"  Mode:        {'Merged' if args.merge else 'Standalone'}")
    print()

    # Read audio
    print("Reading audio file...")
    samples, src_rate, n_channels, _ = read_audio(args.input)
    duration_sec = len(samples) / n_channels / src_rate
    print(
        f"  Source: {src_rate}Hz, {n_channels}ch, {len(samples)//n_channels} samples ({duration_sec:.2f}s)"
    )

    # Convert to mono unless stereo requested
    if not args.stereo or n_channels == 1:
        samples = to_mono(samples, n_channels)
        n_channels = 1
        print(f"  -> Mono: {len(samples)} samples")

    # Resample if needed
    if src_rate != target_rate:
        print(f"  Resampling {src_rate}Hz → {target_rate}Hz...")
        samples = resample_linear(samples, src_rate, target_rate)
        print(f"  -> {len(samples)} samples")

    # Calculate frame parameters
    samples_per_frame = int(target_rate * step_time_ms / 1000)
    channels_per_frame = 2 + (samples_per_frame * 2 * n_channels)  # sync + PCM bytes
    total_frames = (len(samples) + samples_per_frame - 1) // samples_per_frame

    print(f"\nFrame layout:")
    print(f"  Samples/frame:  {samples_per_frame}")
    print(f"  Channels/frame: {channels_per_frame} (2 sync + {samples_per_frame * 2 * n_channels} PCM)")
    print(f"  Total frames:   {total_frames}")
    print(f"  Duration:       {total_frames * step_time_ms / 1000:.2f}s")
    print()

    # Build frame data
    print("Encoding frames...")
    frames = samples_to_frame_channels(samples, samples_per_frame)
    print(f"  Encoded {len(frames)} frames")

    if args.merge:
        # Merge mode
        print(f"\nMerging into {args.merge}...")
        total_size, merged_ch, merged_frames = merge_into_fseq(
            frames, channels_per_frame, args.merge, args.output, args.start_channel
        )
        print(f"\nWrote merged FSEQ: {args.output}")
        print(f"  Size: {total_size:,} bytes ({total_size / 1024 / 1024:.1f} MB)")
        print(f"  Channels: {merged_ch}, Frames: {merged_frames}")
    else:
        # Standalone mode
        print("Writing FSEQ v2...")
        var_header = build_variable_header(args.input)
        total_size = write_fseq_v2(
            args.output, frames, channels_per_frame, step_time_ms, var_header
        )
        print(f"\nWrote standalone FSEQ: {args.output}")
        print(f"  Size: {total_size:,} bytes ({total_size / 1024 / 1024:.1f} MB)")

    # Print HTTPVirtualDisplay config hint
    print(f"\n--- FPP Configuration ---")
    if args.merge:
        print(f"HTTPVirtualDisplay startChannel: {args.start_channel + 1}")
        print(f"HTTPVirtualDisplay channelCount: {channels_per_frame}")
    else:
        print(f"HTTPVirtualDisplay startChannel: 1")
        print(f"HTTPVirtualDisplay channelCount: {channels_per_frame}")
    print(f'Add to co-other.json: {{"channelOutputs": [{{"type": "HTTPVirtualDisplay", "enabled": 1, "startChannel": {args.start_channel + 1 if args.merge else 1}, "channelCount": {channels_per_frame}}}]}}')

    if args.verbose:
        # Dump first frame for verification
        print(f"\n--- First frame hex dump (first 32 bytes) ---")
        first = frames[0][:32]
        print(" ".join(f"{b:02x}" for b in first))


if __name__ == "__main__":
    main()
