#!/usr/bin/env python3
"""Génère les schémas SVG du README (français/anglais, thèmes clair/sombre).

Usage : python3 .github/assets/src/make_diagrams.py
Les chiffres viennent de metrics.json (même dossier), mesurés et documentés dans docs/BENCHMARK.md.
Aucune dépendance hors bibliothèque standard ; Pillow est utilisé s'il est présent pour mesurer les textes.
"""
import json
import os
from html import escape

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.dirname(HERE)
M = json.load(open(os.path.join(HERE, 'metrics.json'), encoding='utf-8'))

FONT = "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"
MONO = "'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace"

THEMES = {
    'light': dict(card='#ffffff', panel='#f6f8fa', border='#d0d7de', ink='#1f2328', ink2='#59636e', muted='#818b98',
                  accent='#2747d4', accent_soft='#eef1ff', accent_line='#c9d3ff', wp='#8a909a', wp_soft='#f2f3f5',
                  good='#0ca30c', bad='#d03b3b', arrow='#9aa4b1'),
    'dark': dict(card='#161b22', panel='#0d1117', border='#30363d', ink='#e6edf3', ink2='#9da7b3', muted='#7d8590',
                 accent='#6b8bff', accent_soft='#1a2244', accent_line='#2f3f86', wp='#737b87', wp_soft='#1c2128',
                 good='#0ca30c', bad='#d03b3b', arrow='#6e7681'),
}

# --- Mesure du texte (Inter si disponible, sinon estimation) ---------------------------------
try:
    from PIL import ImageFont
    _FONTS = {}

    def _font(size, weight):
        key = (size, weight)
        if key not in _FONTS:
            name = {400: 'Inter-Regular.otf', 500: 'Inter-Medium.otf', 600: 'Inter-SemiBold.otf', 700: 'Inter-Bold.otf', 800: 'Inter-ExtraBold.otf'}.get(weight, 'Inter-Regular.otf')
            _FONTS[key] = ImageFont.truetype('/usr/share/fonts/opentype/inter/' + name, size)
        return _FONTS[key]

    def text_w(s, size, weight=400):
        return _font(size, weight).getlength(s) * 1.06  # marge pour Segoe UI / San Francisco
except Exception:  # pragma: no cover
    def text_w(s, size, weight=400):
        return len(s) * size * (0.58 if weight >= 600 else 0.54)


def mono_w(s, size):
    return len(s) * size * 0.62


def wrap(s, size, width, weight=400):
    words, lines, cur = s.split(' '), [], ''
    for w in words:
        t = (cur + ' ' + w).strip()
        if text_w(t, size, weight) <= width or not cur:
            cur = t
        else:
            lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines


# --- Primitives SVG ------------------------------------------------------------------------
def svg(w, h, body, title):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}" role="img" aria-label="{escape(title)}">'
            f'<title>{escape(title)}</title>{body}</svg>\n')


def rect(x, y, w, h, r=12, fill='none', stroke=None, sw=1, extra=''):
    s = f' stroke="{stroke}" stroke-width="{sw}"' if stroke else ''
    return f'<rect x="{x:.1f}" y="{y:.1f}" width="{w:.1f}" height="{h:.1f}" rx="{r}" fill="{fill}"{s}{extra}/>'


def text(x, y, s, size=16, fill='#000', weight=400, anchor='start', family=FONT, extra=''):
    return (f'<text x="{x:.1f}" y="{y:.1f}" font-family="{family}" font-size="{size}" font-weight="{weight}" '
            f'fill="{fill}" text-anchor="{anchor}"{extra}>{escape(s)}</text>')


def lines(x, y, rows, size, fill, weight=400, lh=1.35, anchor='start'):
    out = ''
    for i, r in enumerate(rows):
        out += text(x, y + i * size * lh, r, size, fill, weight, anchor)
    return out


def para(x, y, s, size, width, fill, weight=400, lh=1.35):
    rows = wrap(s, size, width, weight)
    return lines(x, y, rows, size, fill, weight, lh), len(rows) * size * lh


def bar(x, y, w, h, fill, r=4):
    """Barre horizontale : carrée à la base (gauche), arrondie au bout des données (droite)."""
    if w <= 0:
        return ''
    r = min(r, w, h / 2)
    return (f'<path d="M{x:.1f},{y:.1f} h{w - r:.1f} q{r:.1f},0 {r:.1f},{r:.1f} v{h - 2 * r:.1f} '
            f'q0,{r:.1f} {-r:.1f},{r:.1f} h{-(w - r):.1f} z" fill="{fill}"/>')


def check(x, y, color, size=18):
    """Pastille de statut « bon » : rond + coche (l'icône accompagne toujours un libellé)."""
    s = size
    return (f'<circle cx="{x + s / 2:.1f}" cy="{y + s / 2:.1f}" r="{s / 2:.1f}" fill="{color}"/>'
            f'<path d="M{x + s * .28:.1f},{y + s * .52:.1f} l{s * .15:.1f},{s * .15:.1f} l{s * .3:.1f},{-s * .32:.1f}" '
            f'fill="none" stroke="#fff" stroke-width="{s * .12:.1f}" stroke-linecap="round" stroke-linejoin="round"/>')


def cross(x, y, color, size=18):
    s = size
    return (f'<circle cx="{x + s / 2:.1f}" cy="{y + s / 2:.1f}" r="{s / 2:.1f}" fill="{color}"/>'
            f'<path d="M{x + s * .33:.1f},{y + s * .33:.1f} l{s * .34:.1f},{s * .34:.1f} M{x + s * .67:.1f},{y + s * .33:.1f} l{-s * .34:.1f},{s * .34:.1f}" '
            f'stroke="#fff" stroke-width="{s * .12:.1f}" stroke-linecap="round"/>')


def arrow(x1, y1, x2, y2, color, sw=2):
    import math
    a = math.atan2(y2 - y1, x2 - x1)
    hx, hy = x2 - 9 * math.cos(a), y2 - 9 * math.sin(a)
    p1 = (hx + 5 * math.sin(a), hy - 5 * math.cos(a))
    p2 = (hx - 5 * math.sin(a), hy + 5 * math.cos(a))
    return (f'<line x1="{x1:.1f}" y1="{y1:.1f}" x2="{hx:.1f}" y2="{hy:.1f}" stroke="{color}" stroke-width="{sw}" stroke-linecap="round"/>'
            f'<path d="M{x2:.1f},{y2:.1f} L{p1[0]:.1f},{p1[1]:.1f} L{p2[0]:.1f},{p2[1]:.1f} z" fill="{color}"/>')


def logo(x, y, s=48):
    k = s / 24
    return (f'<rect x="{x}" y="{y}" width="{s}" height="{s}" rx="{6 * k:.1f}" fill="#2747d4"/>'
            f'<path transform="translate({x},{y - 2 * k:.1f}) scale({k:.3f})" fill="#fff" '
            'd="M4.2 8.2h2.9l1.9 8 2.1-8h2.2l2.1 8 1.9-8h2.8l-3.4 11.6h-2.5l-2.1-7.6-2.1 7.6H7.6z"/>')


def chip(x, y, label, t, kind='wp', size=13):
    w = text_w(label, size, 600) + 22
    fill, ink = (t['wp_soft'], t['ink2']) if kind == 'wp' else (t['accent_soft'], t['accent'])
    return rect(x, y, w, size + 13, (size + 13) / 2, fill) + text(x + w / 2, y + size + 3, label, size, ink, 600, 'middle'), w


def card(x, y, w, h, t, r=14, fill=None):
    return rect(x, y, w, h, r, fill or t['card'], t['border'])


def frame(W, H, t):
    return rect(0.5, 0.5, W - 1, H - 1, 18, t['card'], t['border'])


def header(t, W, title, subtitle, x=48, y=60):
    out = text(x, y, title, 26, t['ink'], 700)
    if subtitle:
        out += text(x, y + 30, subtitle, 15, t['ink2'])
    return out


def fmt(n, lang, dec=0):
    s = f'{n:,.{dec}f}'
    return s.replace(',', ' ').replace('.', ',') if lang == 'fr' else s


# --- Chiffres du logiciel : mesurés sur l'archive d'installation (dist/) ----------------------
def software():
    import glob
    import zipfile
    root = os.path.dirname(os.path.dirname(OUT))
    z = sorted(glob.glob(os.path.join(root, 'dist', 'wordless-*.zip')))[-1]
    zf = zipfile.ZipFile(z)
    files = [i for i in zf.infolist() if not i.is_dir()]
    return {
        'zip_kb': os.path.getsize(z) / 1000,
        'files': len(files),
        'size_mb': sum(i.file_size for i in files) / 1e6,
        'php_lines': sum(zf.read(i).count(b'\n') for i in files if i.filename.endswith('.php')),
    }


SW = software()
W_ = M['software']


def _num(n, lang):
    return f'{n:,}'.replace(',', '\u202f' if lang == 'fr' else ',')


VARS = {
    'wl_zip_kb': f"{SW['zip_kb']:.0f}",
    'wl_zip': f"{SW['zip_kb']:.0f} Ko", 'wl_zip_en': f"{SW['zip_kb']:.0f} KB",
    'ratio_zip': f"{W_['wp_zip_mb'] * 1000 / SW['zip_kb']:.0f}",
    'wl_files': str(SW['files']), 'wl_files_txt': str(SW['files']), 'wl_files_en': str(SW['files']),
    'ratio_files': f"{W_['wp_files'] / SW['files']:.0f}",
    'wl_php': str(SW['php_lines']), 'wl_php_txt': _num(SW['php_lines'], 'fr'), 'wl_php_en': _num(SW['php_lines'], 'en'),
    'ratio_php': f"{W_['wp_php_lines'] / SW['php_lines']:.0f}",
    'ratio_size': f"{W_['wp_size_mb'] / SW['size_mb']:.0f}",
    'wl_size': f"{SW['size_mb']:.1f}".replace('.', ',') + ' Mo', 'wl_size_en': f"{SW['size_mb']:.1f} MB",
}

# --- Textes ---------------------------------------------------------------------------------
_raw = open(os.path.join(HERE, 'texts.json'), encoding='utf-8').read()
for _k, _v in VARS.items():
    _raw = _raw.replace('{' + _k + '}', _v)
T = json.loads(_raw)


# --- 1. Bandeau d'accueil (sombre, identique dans les deux thèmes) ---------------------------
def hero(lang):
    L = T[lang]['hero']
    W, H = 1280, 560
    body = ('<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0b1022"/>'
            '<stop offset=".55" stop-color="#111a44"/><stop offset="1" stop-color="#1b2a7a"/></linearGradient>'
            '<radialGradient id="r" cx=".85" cy=".1" r=".7"><stop offset="0" stop-color="#4b67ff" stop-opacity=".35"/>'
            '<stop offset="1" stop-color="#4b67ff" stop-opacity="0"/></radialGradient>'
            '<pattern id="d" width="28" height="28" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.2" fill="#ffffff" fill-opacity=".07"/></pattern></defs>')
    body += rect(0, 0, W, H, 22, 'url(#g)') + rect(0, 0, W, H, 22, 'url(#r)') + rect(0, 0, W, H, 22, 'url(#d)')
    body += logo(64, 56, 52) + text(132, 95, 'Wordless', 40, '#ffffff', 800, extra=' letter-spacing="-0.5"')
    body += text(W - 64, 92, L['badge'], 15, '#aab8ff', 600, 'end')
    rows = wrap(L['title'], 50, 820, 800)
    body += lines(64, 186, rows, 50, '#ffffff', 800, 1.12)
    y = 186 + len(rows) * 56 + 6
    body += lines(64, y, wrap(L['subtitle'], 20, 900, 400), 20, '#c9d3ff', 400, 1.4)
    # Tuiles de chiffres
    tiles = L['tiles']
    tw, th, gap, ty = 270, 130, 18, H - 130 - 52
    for i, tile in enumerate(tiles):
        x = 64 + i * (tw + gap)
        body += rect(x, ty, tw, th, 16, '#ffffff', None, extra=' fill-opacity=".06"') + rect(x + .5, ty + .5, tw - 1, th - 1, 16, 'none', '#ffffff', 1, ' stroke-opacity=".14"')
        body += text(x + 22, ty + 56, tile[0], 38, '#ffffff', 800, extra=' letter-spacing="-0.5"')
        body += text(x + 22, ty + 84, tile[1], 15, '#dfe5ff', 600)
        body += text(x + 22, ty + 108, tile[2], 13, '#93a3e0')
    return svg(W, H, body, L['aria'])


# --- 2. Comparatif chiffré (forme « emphase » : Wordless en bleu, WordPress en gris) -----------
def bench(lang, theme):
    t, L = THEMES[theme], T[lang]['bench']
    W = 1280
    panels = L['panels']
    pw, gap, top = (W - 96 - 2 * 32) / 3, 32, 160
    step = 100
    panel_h = 70 + len(panels[0]['metrics']) * step + 4
    foot_rows = wrap(L['footer'], 12.5, W - 96)
    H = int(top + panel_h + 36 + len(foot_rows) * 17 + 18)
    body = frame(W, H, t) + header(t, W, L['title'], L['subtitle'])
    # Légende (toujours présente pour deux séries)
    lx = 48
    for name, color in ((L['wp'], t['wp']), (L['wl'], t['accent'])):
        body += rect(lx, 118, 14, 14, 3, color) + text(lx + 22, 130, name, 14, t['ink2'], 600)
        lx += text_w(name, 14, 600) + 60
    for p, panel in enumerate(panels):
        px = 48 + p * (pw + gap)
        body += rect(px, top, pw, panel_h, 14, t['panel'], t['border'])
        body += text(px + 22, top + 38, panel['title'], 17, t['ink'], 700)
        y = top + 72
        for m in panel['metrics']:
            label, wp, wl = m['label'], m['wp'], m['wl']
            ratio = m.get('ratio', '')
            body += text(px + 22, y, label, 14, t['ink2'], 500)
            wl_label_w = text_w(m['wl_txt'], 14, 700) + (text_w(ratio, 13, 700) + 12 if ratio else 0)
            maxw = pw - 44 - max(text_w(m['wp_txt'], 14, 500), wl_label_w) - 12
            for i, (v, txt, color) in enumerate(((wp, m['wp_txt'], t['wp']), (wl, m['wl_txt'], t['accent']))):
                by = y + 12 + i * 28
                bw = 0 if v == 0 else max(3, maxw * v / max(wp, wl))
                body += bar(px + 22, by, bw, 20, color)
                vx = px + 22 + bw + (8 if bw else 0)
                body += text(vx, by + 15, txt, 14, t['ink'], 700 if i == 1 else 500)
                if i == 1 and ratio:
                    body += text(vx + text_w(txt, 14, 700) + 10, by + 15, ratio, 13, t['accent'], 700)
            y += step
    body += lines(48, top + panel_h + 36, foot_rows, 12.5, t['muted'], 400, 1.36)
    return svg(W, H, body, L['title'])


# --- 3. Ce qui se passe à l'ouverture d'une page --------------------------------------------
def flow(lang, theme):
    t, L = THEMES[theme], T[lang]['flow']
    W = 1280
    tw, gap = 214, 30                       # boîte « total » et espacement
    n_max = max(len(L['wp_steps']), len(L['wl_steps']))
    bw = (W - 96 - 22 - tw - gap - (n_max - 1) * gap) / n_max
    def box_h(steps):
        h = 0
        for a, b in steps:
            h = max(h, 34 + len(wrap(a, 15, bw - 30, 700)) * 19 + 6 + len(wrap(b, 13, bw - 30)) * 17 + 14)
        return max(h, 96)
    bh = max(box_h(L['wp_steps']), box_h(L['wl_steps']))
    lane_h = 40 + bh + 20
    foot_rows = wrap(L['footer'], 12.5, W - 96)
    H = int(130 + 2 * lane_h + 40 + len(foot_rows) * 17 + 14)
    body = frame(W, H, t) + header(t, W, L['title'], L['subtitle'])
    lanes = (('wp', L['wp'], L['wp_steps'], L['wp_total']), ('wl', L['wl'], L['wl_steps'], L['wl_total']))
    for k, (kind, name, steps, total) in enumerate(lanes):
        y = 128 + k * (lane_h + 20)
        color = t['wp'] if kind == 'wp' else t['accent']
        soft = t['wp_soft'] if kind == 'wp' else t['accent_soft']
        body += rect(48, y, 6, lane_h - 10, 3, color)
        body += text(70, y + 22, name, 17, t['ink'], 700)
        bx, by = 70, y + 40
        for i, (a, b) in enumerate(steps):
            x = bx + i * (bw + gap)
            body += rect(x, by, bw, bh, 12, soft, t['accent_line'] if kind == 'wl' else t['border'])
            body += text(x + 16, by + 26, f'{i + 1}', 13, color, 800)
            ta = wrap(a, 15, bw - 30, 700)
            body += lines(x + 16, by + 48, ta, 15, t['ink'], 700, 1.27)
            body += lines(x + 16, by + 48 + len(ta) * 19 + 6, wrap(b, 13, bw - 30), 13, t['ink2'], 400, 1.3)
            if i < len(steps) - 1:
                body += arrow(x + bw + 5, by + bh / 2, x + bw + gap - 5, by + bh / 2, t['arrow'])
        tx = W - 48 - tw
        ex = bx + len(steps) * (bw + gap) - gap
        body += arrow(ex + 5, by + bh / 2, tx - 6, by + bh / 2, t['arrow'])
        body += rect(tx, by, tw, bh, 12, t['card'], color, 2)
        ty = by + bh / 2 - (len(total) - 1) * 11 + 5
        body += lines(tx + 18, ty, total, 15, t['ink'], 700, 1.45)
    body += lines(48, H - 18 - (len(foot_rows) - 1) * 17, foot_rows, 12.5, t['muted'], 400, 1.36)
    return svg(W, H, body, L['title'])


# --- 4. Migration WordPress → Wordless ------------------------------------------------------
def migration(lang, theme):
    t, L = THEMES[theme], T[lang]['migration']
    W, H = 1280, 640
    body = frame(W, H, t) + header(t, W, L['title'], L['subtitle'])
    top = 130
    # Colonne 1 : source
    cw = 330
    body += card(48, top, cw, 420, t, fill=t['panel'])
    body += text(70, top + 38, L['src_title'], 18, t['ink'], 700)
    y = top + 70
    for head, desc in L['src_items']:
        body += rect(70, y, cw - 44, 150, 12, t['card'], t['border'])
        body += text(90, y + 30, head, 15, t['ink'], 700)
        para_, h = para(90, y + 56, desc, 13.5, cw - 84, t['ink2'])
        body += para_
        y += 166
    # Colonne 2 : assistant
    mx, mw = 48 + cw + 50, 330
    body += arrow(48 + cw + 8, top + 210, mx - 8, top + 210, t['arrow'], 2.5)
    body += card(mx, top, mw, 420, t, fill=t['accent_soft'])
    body += logo(mx + 22, top + 20, 30) + text(mx + 62, top + 42, L['tool_title'], 18, t['ink'], 700)
    y = top + 82
    bar_x = mx + 56 + max(text_w(ph, 15, 500) for ph in L['phases']) + 14
    for i, ph in enumerate(L['phases']):
        body += check(mx + 24, y - 14, t['good'], 20)
        body += text(mx + 56, y + 1, ph, 15, t['ink'], 500)
        if mx + mw - 24 - bar_x > 24:
            body += bar(bar_x, y - 9, mx + mw - 24 - bar_x, 8, t['accent'], 4)
        y += 44
    body += text(mx + 24, top + 400, L['tool_note'], 13, t['ink2'], 600)
    # Colonne 3 : résultat
    rx, rw = mx + mw + 50, W - 48 - (mx + mw + 50)
    body += arrow(mx + mw + 8, top + 210, rx - 8, top + 210, t['arrow'], 2.5)
    body += card(rx, top, rw, 420, t)
    body += text(rx + 22, top + 38, L['res_title'], 18, t['ink'], 700)
    y = top + 76
    for item in L['results']:
        body += check(rx + 22, y - 14, t['good'], 20)
        p_, h = para(rx + 54, y + 1, item, 14.5, rw - 80, t['ink'])
        body += p_
        y += max(h, 20) + 18
    body += text(48, H - 30, L['footer'], 12.5, t['muted'])
    return svg(W, H, body, L['title'])


# --- 5. Connecteur MCP -----------------------------------------------------------------------
def mcp(lang, theme):
    t, L = THEMES[theme], T[lang]['mcp']
    W = 1280
    top = 128
    ox = 852
    ow = W - 48 - ox
    # Pré-calcul de la hauteur des outils
    def layout_tools():
        items, y = [], 70
        for group, tools in L['tools']:
            items.append(('g', 22, y, group))
            y += 12
            x = 22
            for tool in tools:
                w = mono_w(tool, 12) + 16
                if x + w > ow - 18:
                    x, y = 22, y + 30
                items.append(('t', x, y, tool, w))
                x += w + 6
            y += 52
        return items, y - 18
    tools_items, tools_h = layout_tools()
    clients_h = len(L['clients']) * 108 - 16
    block_h = max(tools_h, clients_h, 360)
    H = int(top + block_h + 30 + 64 + 50)
    body = frame(W, H, t) + header(t, W, L['title'], L['subtitle'])
    mid = top + block_h / 2
    # Clients
    cy = top + (block_h - clients_h) / 2
    for name, how in L['clients']:
        body += card(48, cy, 300, 92, t, fill=t['panel'])
        body += text(70, cy + 38, name, 16, t['ink'], 700)
        body += text(70, cy + 64, how, 13.5, t['ink2'])
        body += arrow(348 + 6, cy + 46, 436 - 8, mid, t['arrow'], 2)
        cy += 108
    # Serveur
    sx, sw = 436, 330
    rows = [wrap(item, 14, sw - 74) for item in L['server']]
    sh = 100 + sum(max(len(r), 1) * 19 + 14 for r in rows)
    sy = mid - sh / 2
    body += card(sx, sy, sw, sh, t, fill=t['accent_soft'])
    body += logo(sx + 24, sy + 24, 34) + text(sx + 70, sy + 48, 'Wordless /mcp', 20, t['ink'], 800)
    y = sy + 92
    for r in rows:
        body += check(sx + 24, y - 14, t['good'], 18)
        body += lines(sx + 52, y, r, 14, t['ink'], 400, 1.35)
        y += max(len(r), 1) * 19 + 14
    body += arrow(sx + sw + 8, mid, ox - 8, mid, t['arrow'], 2.5)
    # Outils
    body += card(ox, top, ow, block_h, t)
    body += text(ox + 22, top + 36, L['tools_title'], 17, t['ink'], 700)
    for it in tools_items:
        if it[0] == 'g':
            body += text(ox + it[1], top + it[2], it[3], 13.5, t['ink2'], 700)
        else:
            _, x, y, tool, w = it
            body += rect(ox + x, top + y, w, 22, 6, t['panel'], t['border']) + text(ox + x + 8, top + y + 15.5, tool, 12, t['ink'], 500, family=MONO)
    # Exemple de demande
    ey = top + block_h + 30
    body += rect(48, ey, W - 96, 64, 14, t['panel'], t['border'])
    body += text(72, ey + 39, L['example'], 16, t['ink'], 500, extra=' font-style="italic"')
    body += text(48, H - 22, L['footer'], 12.5, t['muted'])
    return svg(W, H, body, L['title'])


# --- 6. Sécurité ----------------------------------------------------------------------------
def security(lang, theme):
    t, L = THEMES[theme], T[lang]['security']
    W = 1280
    colw = (W - 96 - 40) / 2
    def column_h(items):
        return sum(max(len(wrap(i, 15, colw - 84)) * 20, 20) + 22 for i in items)
    H = int(150 + max(column_h(L['wp_items']), column_h(L['wl_items'])) + 150)
    body = frame(W, H, t) + header(t, W, L['title'], L['subtitle'])
    for c, (name, items, mark, color) in enumerate(((L['wp'], L['wp_items'], cross, t['bad']), (L['wl'], L['wl_items'], check, t['good']))):
        x = 48 + c * (colw + 40)
        body += card(x, 126, colw, H - 126 - 110, t, fill=t['panel'] if c == 0 else t['accent_soft'])
        body += text(x + 24, 162, name, 18, t['ink'], 700)
        y = 202
        for item in items:
            body += mark(x + 24, y - 15, color, 20)
            p_, h = para(x + 58, y, item, 15, colw - 84, t['ink'])
            body += p_
            y += max(h, 20) + 22
    note, h = para(48, H - 76, L['note'], 13, W - 96, t['ink2'])
    body += note + text(48, H - 26, L['footer'], 12.5, t['muted'])
    return svg(W, H, body, L['title'])


# --- 7. GEO : être lu et cité par les IA -----------------------------------------------------
def geo(lang, theme):
    t, L = THEMES[theme], T[lang]['geo']
    W = 1280
    top = 128
    sx, sw = 404, 470
    rx, rw = 930, W - 48 - 930
    serves_h = 76 + sum(22 + len(wrap(d, 13, sw - 76)) * 17.6 + 12 for _, d in L['serves'])
    result_h = 66 + len(wrap(L['result_text'], 14, rw - 44)) * 19 + 16 + len(L['kpis']) * 68
    engines_h = 66 + len(L['engines']) * 62
    bh = int(max(serves_h, result_h, engines_h) + 14)
    H = top + bh + 64
    body = frame(W, H, t) + header(t, W, L['title'], L['subtitle'])
    mid = top + bh / 2
    # Moteurs
    body += card(48, top, 300, bh, t, fill=t['panel'])
    body += text(70, top + 36, L['engines_title'], 17, t['ink'], 700)
    y = top + 66
    for name, bots in L['engines']:
        body += text(70, y + 4, name, 15, t['ink'], 700)
        body += text(70, y + 25, bots, 12, t['ink2'], 500, family=MONO)
        y += 62
    body += arrow(348 + 8, mid, sx - 8, mid, t['arrow'], 2.5)
    # Ce que sert Wordless
    body += card(sx, top, sw, bh, t, fill=t['accent_soft'])
    body += logo(sx + 22, top + 16, 28) + text(sx + 60, top + 36, L['serves_title'], 17, t['ink'], 700)
    y = top + 76
    for head, desc in L['serves']:
        body += check(sx + 22, y - 14, t['good'], 18)
        body += text(sx + 50, y, head, 14.5, t['ink'], 700)
        p_, h = para(sx + 50, y + 20, desc, 13, sw - 76, t['ink2'])
        body += p_
        y += 22 + h + 12
    body += arrow(sx + sw + 8, mid, rx - 8, mid, t['arrow'], 2.5)
    # Résultat mesuré
    body += card(rx, top, rw, bh, t)
    body += text(rx + 22, top + 36, L['result_title'], 17, t['ink'], 700)
    p_, h = para(rx + 22, top + 66, L['result_text'], 14, rw - 44, t['ink'])
    body += p_
    y = top + 66 + h + 16
    for label, value in L['kpis']:
        body += rect(rx + 22, y, rw - 44, 58, 10, t['panel'], t['border'])
        body += text(rx + 38, y + 25, value, 20, t['ink'], 800)
        body += text(rx + 38, y + 46, label, 12.5, t['ink2'])
        y += 68
    body += text(48, H - 26, L['footer'], 12.5, t['muted'])
    return svg(W, H, body, L['title'])


def main():
    os.makedirs(OUT, exist_ok=True)
    n = 0
    for lang in ('fr', 'en'):
        open(os.path.join(OUT, f'hero-{lang}.svg'), 'w', encoding='utf-8').write(hero(lang)); n += 1
        for theme in ('light', 'dark'):
            for name, fn in (('bench', bench), ('flow', flow), ('migration', migration), ('mcp', mcp), ('security', security), ('geo', geo)):
                open(os.path.join(OUT, f'{name}-{lang}-{theme}.svg'), 'w', encoding='utf-8').write(fn(lang, theme)); n += 1
    print(n, 'schémas écrits dans', OUT)
    print(json.dumps(VARS, ensure_ascii=False))


if __name__ == '__main__':
    main()
