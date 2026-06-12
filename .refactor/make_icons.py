#!/usr/bin/env python3
"""Genera iconos PWA (PNG sin dependencias): fondo azul con 'M' blanca en bloques."""
import struct, zlib
from pathlib import Path

OUT = Path("/Users/jesusdiaz/Documents/megamundo-logistica/assets/img")
BG = (29, 78, 216)   # azul #1d4ed8
FG = (255, 255, 255)

def make_png(size, path):
    px = [[BG for _ in range(size)] for _ in range(size)]
    # 'M' en bloques dentro de la zona segura central (40%-padding para maskable)
    u = size / 16.0
    def rect(x0, y0, x1, y1):
        for y in range(int(y0 * u), int(y1 * u)):
            for x in range(int(x0 * u), int(x1 * u)):
                if 0 <= x < size and 0 <= y < size:
                    px[y][x] = FG
    # barras verticales y diagonales centrales de la M (caja 4..12 en x, 5..11 en y)
    rect(4, 5, 5.6, 11)            # barra izquierda
    rect(10.4, 5, 12, 11)          # barra derecha
    steps = 8
    for i in range(steps):         # diagonal izquierda hacia el centro
        x0 = 5.6 + (7.0 - 5.6) * i / steps
        y0 = 5 + (8.6 - 5) * i / steps
        rect(x0, y0, x0 + 0.9, y0 + 1.1)
    for i in range(steps):         # diagonal derecha hacia el centro
        x0 = 10.4 - (10.4 - 8.1) * i / steps
        y0 = 5 + (8.6 - 5) * i / steps
        rect(x0, y0, x0 + 0.9, y0 + 1.1)

    raw = b"".join(b"\x00" + bytes(c for p in row for c in p) for row in px)
    def chunk(tag, data):
        out = struct.pack(">I", len(data)) + tag + data
        return out + struct.pack(">I", zlib.crc32(tag + data) & 0xFFFFFFFF)
    png = (b"\x89PNG\r\n\x1a\n"
           + chunk(b"IHDR", struct.pack(">IIBBBBB", size, size, 8, 2, 0, 0, 0))
           + chunk(b"IDAT", zlib.compress(raw, 9))
           + chunk(b"IEND", b""))
    path.write_bytes(png)
    print(f"OK {path.name}: {size}x{size}, {len(png)} bytes")

OUT.mkdir(parents=True, exist_ok=True)
make_png(192, OUT / "pwa-icon-192.png")
make_png(512, OUT / "pwa-icon-512.png")
