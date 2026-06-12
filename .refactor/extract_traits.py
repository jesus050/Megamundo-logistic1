#!/usr/bin/env python3
"""Extracción mecánica de ScannerViewController en traits por dominio.

Garantías:
1. Cada método se mueve VERBATIM (rangos de líneas exactos, sin reescritura).
2. Verificación de teselado: rangos extraídos + conservados cubren 1..N
   exactamente una vez y reconstruyen el archivo original byte a byte.
3. Chequeo de balance de llaves/paréntesis consciente de tags PHP, strings
   y comentarios para cada archivo generado.
"""
import sys
from pathlib import Path

ROOT = Path("/Users/jesusdiaz/Documents/megamundo-logistica")
SRC = ROOT / "src/Presentation/Front/ScannerViewController.php"
CONCERNS = ROOT / "src/Presentation/Front/Concerns"

# Rangos 1-indexed inclusivos, derivados del mapa de métodos (grep).
TRAITS = {
    "NotificacionesTrait":     [(530, 768)],
    "ReportesTrait":           [(769, 1127)],
    "ProductosNuevosTrait":    [(1128, 1337)],
    "UsuariosTrait":           [(1338, 1562)],
    "SincronizacionTrait":     [(1563, 1766)],
    "HistorialTrait":          [(1767, 2031)],
    "SistemaTrait":            [(2032, 2152)],
    "FacturasTrait":           [(2153, 2804), (2817, 3020), (3229, 3492)],
    "PedidosTrait":            [(2805, 2816), (3681, 4375)],
    "PreciosTrait":            [(3021, 3228), (5390, 5534), (5770, 5816), (6124, 6236)],
    "MekanoTrait":             [(3493, 3680)],
    "ConfigIaTrait":           [(4376, 4726), (5218, 5250)],
    "ExhibicionTrait":         [(4727, 5024), (5120, 5124), (6527, 6566)],
    "BodegaTrait":             [(5025, 5119), (5125, 5217), (5535, 5660), (6492, 6526), (6567, 6623)],
    "ProductosSinImagenTrait": [(5251, 5389)],
    "EtiquetasTrait":          [(5817, 6010)],
    "JefaturaTrait":           [(6011, 6123), (6237, 6297)],
    "LoteDetailTrait":         [(6298, 6491)],
}

# Lo que conserva la clase principal (shell de la app).
KEEP = [(1, 529), (5661, 5769), (6624, 6759)]

TRAIT_HEADER = """<?php
namespace MegaMundo\\Logistica\\Presentation\\Front\\Concerns;

if ( ! defined( 'ABSPATH' ) ) {{ exit; }}

use MegaMundo\\Logistica\\Domain\\Lote\\LoteRepository;
use MegaMundo\\Logistica\\Domain\\Lote\\LoteItemRepository;
use MegaMundo\\Logistica\\Infrastructure\\Security\\PermissionGuard;
use MegaMundo\\Logistica\\Application\\Lote\\ChecklistService;
use MegaMundo\\Logistica\\Infrastructure\\OpenAI\\OpenAiVisionService;
use MegaMundo\\Logistica\\Application\\Export\\MekanoExportService;

trait {name} {{
"""

USE_BLOCK = """
    use Concerns\\NotificacionesTrait;
    use Concerns\\ReportesTrait;
    use Concerns\\ProductosNuevosTrait;
    use Concerns\\UsuariosTrait;
    use Concerns\\SincronizacionTrait;
    use Concerns\\HistorialTrait;
    use Concerns\\SistemaTrait;
    use Concerns\\FacturasTrait;
    use Concerns\\PedidosTrait;
    use Concerns\\PreciosTrait;
    use Concerns\\MekanoTrait;
    use Concerns\\ConfigIaTrait;
    use Concerns\\ExhibicionTrait;
    use Concerns\\BodegaTrait;
    use Concerns\\ProductosSinImagenTrait;
    use Concerns\\EtiquetasTrait;
    use Concerns\\JefaturaTrait;
    use Concerns\\LoteDetailTrait;
"""

def check_tiling(total_lines):
    spans = []
    for ranges in TRAITS.values():
        spans.extend(ranges)
    spans.extend(KEEP)
    spans.sort()
    pos = 1
    for a, b in spans:
        if a != pos:
            sys.exit(f"ERROR teselado: esperaba inicio {pos}, encontré {a}")
        if b < a:
            sys.exit(f"ERROR rango invertido: {a}-{b}")
        pos = b + 1
    if pos != total_lines + 1:
        sys.exit(f"ERROR teselado: terminé en {pos - 1}, archivo tiene {total_lines}")
    print(f"OK teselado: {len(spans)} rangos cubren 1..{total_lines} sin huecos ni solapes")

def php_brace_check(text, fname):
    """Cuenta {} y () solo en código PHP real (fuera de HTML, strings, comentarios)."""
    in_php = False
    state = None  # None | 'sq' | 'dq' | 'line' | 'block'
    brace = paren = 0
    i, n = 0, len(text)
    while i < n:
        if not in_php:
            if text.startswith("<?php", i):
                in_php = True; i += 5; continue
            if text.startswith("<?=", i):
                in_php = True; i += 3; continue
            i += 1; continue
        c = text[i]
        if state == "sq":
            if c == "\\": i += 2; continue
            if c == "'": state = None
            i += 1; continue
        if state == "dq":
            if c == "\\": i += 2; continue
            if c == '"': state = None
            i += 1; continue
        if state == "line":
            if c == "\n": state = None
            elif text.startswith("?>", i):
                state = None; in_php = False; i += 2; continue
            i += 1; continue
        if state == "block":
            if text.startswith("*/", i):
                state = None; i += 2; continue
            i += 1; continue
        # código PHP normal
        if text.startswith("?>", i):
            in_php = False; i += 2; continue
        if c == "'": state = "sq"; i += 1; continue
        if c == '"': state = "dq"; i += 1; continue
        if text.startswith("//", i) or c == "#":
            state = "line"; i += 1; continue
        if text.startswith("/*", i):
            state = "block"; i += 2; continue
        if c == "{": brace += 1
        elif c == "}": brace -= 1
        elif c == "(": paren += 1
        elif c == ")": paren -= 1
        if brace < 0 or paren < 0:
            sys.exit(f"ERROR {fname}: cierre sin apertura (brace={brace}, paren={paren}) en offset {i}")
        i += 1
    if brace != 0 or paren != 0:
        sys.exit(f"ERROR {fname}: desbalance final brace={brace}, paren={paren}")
    print(f"OK balance: {fname} (llaves y paréntesis cuadran)")

def main():
    original = SRC.read_text()
    lines = original.split("\n")
    # split("\n") sobre archivo terminado en \n da un último elemento vacío
    if lines and lines[-1] == "":
        lines = lines[:-1]
    total = len(lines)
    print(f"Archivo original: {total} líneas")
    check_tiling(total)

    def slice_lines(a, b):
        return "\n".join(lines[a - 1 : b])

    CONCERNS.mkdir(parents=True, exist_ok=True)
    extracted_parts = {}

    for name, ranges in TRAITS.items():
        body = "\n\n".join(slice_lines(a, b).rstrip() for a, b in ranges)
        content = TRAIT_HEADER.format(name=name) + body + "\n}\n"
        path = CONCERNS / f"{name}.php"
        path.write_text(content)
        php_brace_check(content, path.name)
        extracted_parts[name] = ranges

    # Clase principal: 1..13 (hasta "class ... {"), bloque use, 14..529, shell, escáner.
    head = slice_lines(1, 13)
    rest_keep1 = slice_lines(14, 529)
    keep2 = slice_lines(5661, 5769)
    keep3 = slice_lines(6624, 6759)
    new_main = head + USE_BLOCK + rest_keep1.rstrip() + "\n\n" + keep2.rstrip() + "\n\n" + keep3 + "\n"
    SRC.write_text(new_main)
    php_brace_check(new_main, SRC.name)

    # Verificación final de reconstrucción exacta (rangos verbatim).
    for name, ranges in extracted_parts.items():
        trait_text = (CONCERNS / f"{name}.php").read_text()
        for a, b in ranges:
            chunk = slice_lines(a, b).rstrip()
            if chunk not in trait_text:
                sys.exit(f"ERROR verbatim: rango {a}-{b} no aparece intacto en {name}")
    print("OK verbatim: todos los rangos extraídos aparecen intactos en sus traits")

    new_lines = sum(1 for _ in (CONCERNS).glob("*.php"))
    print(f"Generados {new_lines} traits en {CONCERNS}")
    print(f"Clase principal reducida a {len(new_main.splitlines())} líneas")

if __name__ == "__main__":
    main()
