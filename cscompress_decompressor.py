#!/usr/bin/env python3
"""
Standalone CSCompress decompressor for the SAP Content Server format observed in the
user-provided samples.

No third-party dependencies are required. Only Python's standard library is used.

Observed format:
- bytes 0..3  : little-endian uncompressed length
- byte 4      : version/algorithm nibble
- bytes 5..6  : magic 0x1f 0x9d
- byte 7      : 'special' (for algorithm 2 this is the compression level)
- bytes 8..   : payload

For algorithm 2 (LZH in SAP's naming), the payload is effectively a raw DEFLATE
stream with a small SAP-specific bit prefix:
- first 2 bits (LSB-first) encode x
- then x further nonsense bits are present
- the remaining bits form the raw DEFLATE bitstream

This was validated against five original/compressed PDF pairs supplied by the user.
"""

from __future__ import annotations

import argparse
import pathlib
import struct
import sys
import zlib

CS_MAGIC = b"\x1f\x9d"
CS_HEAD_SIZE = 8
CS_ALGORITHM_LZH = 2
NONSENSE_LENBITS = 2


class CsCompressError(Exception):
    pass


def _shift_lsb_bitstream(data: bytes, skip_bits: int) -> bytes:
    """Drop skip_bits from an LSB-first bitstream and repack to bytes."""
    if skip_bits < 0:
        raise ValueError("skip_bits must be >= 0")

    out = bytearray()
    acc = 0
    accbits = 0
    total_bits = len(data) * 8

    for bit_index in range(skip_bits, total_bits):
        bit = (data[bit_index // 8] >> (bit_index % 8)) & 1
        acc |= bit << accbits
        accbits += 1
        if accbits == 8:
            out.append(acc)
            acc = 0
            accbits = 0

    if accbits:
        out.append(acc)

    return bytes(out)



def parse_header(blob: bytes) -> dict[str, int]:
    if len(blob) < CS_HEAD_SIZE:
        raise CsCompressError("File too small to be a CSCompress blob")

    orig_len = struct.unpack_from("<I", blob, 0)[0]
    veralg = blob[4]
    version = (veralg >> 4) & 0x0F
    algorithm = veralg & 0x0F
    magic = blob[5:7]
    special = blob[7]

    if magic != CS_MAGIC:
        raise CsCompressError(
            f"Invalid CSCompress magic: expected {CS_MAGIC.hex()}, got {magic.hex()}"
        )

    return {
        "orig_len": orig_len,
        "version": version,
        "algorithm": algorithm,
        "special": special,
    }



def decompress_lzh_payload(payload: bytes) -> bytes:
    if not payload:
        raise CsCompressError("Empty payload")

    # SAP's LZH stream starts with NONSENSE_LENBITS bits that store x.
    x = payload[0] & ((1 << NONSENSE_LENBITS) - 1)
    skip_bits = NONSENSE_LENBITS + x
    shifted = _shift_lsb_bitstream(payload, skip_bits)

    try:
        return zlib.decompress(shifted, -15)  # raw DEFLATE
    except zlib.error as e:
        raise CsCompressError(
            f"Raw DEFLATE decompression failed after stripping {skip_bits} prefix bits: {e}"
        ) from e



def decompress_cscompress(blob: bytes) -> tuple[bytes, dict[str, int]]:
    header = parse_header(blob)
    payload = blob[CS_HEAD_SIZE:]

    if header["algorithm"] != CS_ALGORITHM_LZH:
        raise CsCompressError(
            "This standalone script currently supports only algorithm 2 (LZH). "
            f"Found algorithm {header['algorithm']}."
        )

    out = decompress_lzh_payload(payload)

    if len(out) != header["orig_len"]:
        raise CsCompressError(
            f"Length mismatch after decompression: expected {header['orig_len']}, got {len(out)}"
        )

    return out, header



def default_output_name(infile: pathlib.Path, data: bytes) -> pathlib.Path:
    if data.startswith(b"%PDF-"):
        return infile.with_suffix(".pdf")
    return infile.with_suffix(".decompressed")



def main() -> int:
    parser = argparse.ArgumentParser(description="Standalone CSCompress decompressor")
    parser.add_argument("input", help="Input .compressed / CSCompress file")
    parser.add_argument("output", nargs="?", help="Optional output file path")
    parser.add_argument(
        "--info-only",
        action="store_true",
        help="Only print header information, do not decompress",
    )
    args = parser.parse_args()

    infile = pathlib.Path(args.input)
    blob = infile.read_bytes()
    header = parse_header(blob)

    print(f"Input      : {infile}")
    print(f"Orig length: {header['orig_len']}")
    print(f"Version    : {header['version']}")
    print(f"Algorithm  : {header['algorithm']}")
    print(f"Special    : {header['special']}")

    if args.info_only:
        return 0

    data, _ = decompress_cscompress(blob)
    outfile = pathlib.Path(args.output) if args.output else default_output_name(infile, data)
    outfile.write_bytes(data)
    print(f"Output     : {outfile}")
    print(f"Wrote      : {len(data)} bytes")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except CsCompressError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        raise SystemExit(2)
