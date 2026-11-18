#!/usr/bin/env python3
"""
Draws the isometric house used on the welcome page (pages/partials/welcome_house.svg).

Each part sits in its own <g class="h-…"> so the page can build the house as you scroll.
Run again after changing a measurement:  python3 bin/make_house_svg.py
"""
from pathlib import Path

CX, CY = 330, 205          # where (0,0,0) lands in the picture
COS, SIN = 0.866, 0.5      # isometric axes


def P(x, y, z=0.0):
    return (round(CX + (x - y) * COS, 1), round(CY + (x + y) * SIN - z, 1))


def poly(points, fill, cls="", extra=""):
    pts = " ".join(f"{a},{b}" for a, b in points)
    c = f' class="{cls}"' if cls else ""
    return f'<polygon{c} points="{pts}" fill="{fill}"{extra}/>'


def quad_x(x, y0, y1, z0, z1):          # a rectangle on a wall facing +x
    return [P(x, y0, z0), P(x, y1, z0), P(x, y1, z1), P(x, y0, z1)]


def quad_y(y, x0, x1, z0, z1):          # a rectangle on a wall facing +y
    return [P(x0, y, z0), P(x1, y, z0), P(x1, y, z1), P(x0, y, z1)]


W, D, H = 220, 150, 112    # house: width (x), depth (y), wall height
RIDGE, O = 76, 14          # roof height above the walls, eave overhang
G = -16                    # ground level (under the slab)

out = []
add = out.append

# ── ground: a lawn tile with a thick edge, a path to the door ─────────
add('<g class="h-ground">')
gx0, gx1, gy0, gy1 = -95, 315, -85, 250
add(poly([P(gx0, gy1, G), P(gx1, gy1, G), P(gx1, gy1, G - 22), P(gx0, gy1, G - 22)], "#5d8f4c"))
add(poly([P(gx1, gy0, G), P(gx1, gy1, G), P(gx1, gy1, G - 22), P(gx1, gy0, G - 22)], "#4b7a3d"))
add(poly([P(gx0, gy0, G), P(gx1, gy0, G), P(gx1, gy1, G), P(gx0, gy1, G)], "url(#lawn)"))
add(poly([P(70, D + 8, G + .5), P(102, D + 8, G + .5), P(102, gy1, G + .5), P(70, gy1, G + .5)], "#e9e1cf"))
add('</g>')

# ── trees and shrubs ─────────────────────────────────────────────────
def tree(x, y, size, dark, light):
    base = P(x, y, G)
    top = P(x, y, G + size * 1.9)
    s = [f'<g class="h-tree">',
         f'<ellipse cx="{base[0]}" cy="{base[1] + 2}" rx="{size * .75}" ry="{size * .32}" fill="#3f6a33" opacity=".45"/>',
         f'<rect x="{base[0] - 4}" y="{top[1] + size * .6}" width="8" height="{base[1] - top[1] - size * .6}" fill="#7a5536" rx="2"/>',
         f'<circle cx="{top[0]}" cy="{top[1]}" r="{size}" fill="{dark}"/>',
         f'<circle cx="{top[0] - size * .28}" cy="{top[1] - size * .3}" r="{size * .62}" fill="{light}"/>',
         '</g>']
    return "".join(s)

# trees behind the house are drawn before it, so the roof covers them
add('<g class="h-trees">')
add(tree(-40, -70, 32, "#3f8a4e", "#5fae69"))
add(tree(150, -95, 26, "#3a7f48", "#59a463"))
add('</g>')

# ── foundation slab ─────────────────────────────────────────────────
add('<g class="h-slab">')
s0, s1 = -10, 10
add(poly([P(s0, D + s1, 0), P(W + s1, D + s1, 0), P(W + s1, D + s1, G), P(s0, D + s1, G)], "#b9b1a0"))
add(poly([P(W + s1, s0, 0), P(W + s1, D + s1, 0), P(W + s1, D + s1, G), P(W + s1, s0, G)], "#a39a87"))
add(poly([P(s0, s0, 0), P(W + s1, s0, 0), P(W + s1, D + s1, 0), P(s0, D + s1, 0)], "#d6cfbf"))
add('</g>')

# ── walls ────────────────────────────────────────────────────────────
add('<g class="h-walls">')
add(poly(quad_y(D, 0, W, 0, H), "url(#wallFront)"))
add(poly(quad_x(W, 0, D, 0, H), "url(#wallSide)"))
add(poly([P(W, 0, H), P(W, D, H), P(W, D / 2, H + RIDGE)], "#e2c39d"))          # gable
# siding lines on the front
for z in range(14, H, 14):
    add(f'<polyline points="{P(0, D, z)[0]},{P(0, D, z)[1]} {P(W, D, z)[0]},{P(W, D, z)[1]}" stroke="#e3c7a3" stroke-width="1" fill="none"/>')
for z in range(14, H, 14):
    add(f'<polyline points="{P(W, 0, z)[0]},{P(W, 0, z)[1]} {P(W, D, z)[0]},{P(W, D, z)[1]}" stroke="#c9a881" stroke-width="1" fill="none"/>')
add(poly(quad_y(D, -3, 3, 0, H), "#fff7ea"))          # corner boards
add(poly(quad_x(W, D - 3, D + 0.1, 0, H), "#f3e3cc"))
add('</g>')

# ── roof (back slope, chimney, front slope, ridge cap) ───────────────
add('<g class="h-roof">')
zr, ze = H + RIDGE, H - 8
add(poly([P(-O, D / 2, zr), P(W + O, D / 2, zr), P(W + O, -O, ze), P(-O, -O, ze)], "#8e3a2b"))
add(poly([P(W + O, D / 2, zr), P(W + O, -O, ze), P(W + O, -O, ze - 7), P(W + O, D / 2, zr - 7)], "#6a271c"))
# chimney on the back slope
cx0, cx1, cy0, cy1, ctop = 150, 176, 22, 46, zr + 26
add(poly(quad_x(cx1, cy0, cy1, H + 30, ctop), "#8f4a3c"))
add(poly(quad_y(cy1, cx0, cx1, H + 30, ctop), "#b0604f"))
add(poly([P(cx0 - 3, cy0 - 3, ctop), P(cx1 + 3, cy0 - 3, ctop), P(cx1 + 3, cy1 + 3, ctop), P(cx0 - 3, cy1 + 3, ctop)], "#6d3a30"))
add(poly([P(-O, D / 2, zr), P(W + O, D / 2, zr), P(W + O, D + O, ze), P(-O, D + O, ze)], "url(#roofFront)"))
for i in range(1, 6):                                    # shingle courses
    t = i / 6
    a = P(-O, D / 2 + (D / 2 + O) * t, zr - (zr - ze) * t)
    b = P(W + O, D / 2 + (D / 2 + O) * t, zr - (zr - ze) * t)
    add(f'<polyline points="{a[0]},{a[1]} {b[0]},{b[1]}" stroke="#a9432f" stroke-width="1.2" fill="none"/>')
add(poly([P(W + O, D / 2, zr), P(W + O, D + O, ze), P(W + O, D + O, ze - 7), P(W + O, D / 2, zr - 7)], "#7d2f22"))   # fascia
add(poly([P(-O, D / 2, zr + 3), P(W + O, D / 2, zr + 3), P(W + O, D / 2, zr - 2), P(-O, D / 2, zr - 2)], "#6a271c"))
add('</g>')

# ── windows and the door ─────────────────────────────────────────────
add('<g class="h-openings">')
front_windows = [(18, 58), (150, 194)]
for x0, x1 in front_windows:
    add(poly(quad_y(D + .5, x0 - 3, x1 + 3, 34, 86), "#fffaf1"))
    add(poly(quad_y(D + .6, x0, x1, 37, 83), "#2e4a5c", "pane"))
    mid = (x0 + x1) / 2
    a, b = P(mid, D + .7, 37), P(mid, D + .7, 83)
    add(f'<line x1="{a[0]}" y1="{a[1]}" x2="{b[0]}" y2="{b[1]}" stroke="#fffaf1" stroke-width="2"/>')
    a, b = P(x0, D + .7, 60), P(x1, D + .7, 60)
    add(f'<line x1="{a[0]}" y1="{a[1]}" x2="{b[0]}" y2="{b[1]}" stroke="#fffaf1" stroke-width="2"/>')
# door + step
add(poly(quad_y(D + .5, 74, 100, 0, 72), "#fffaf1"))
add(poly(quad_y(D + .6, 77, 97, 0, 69), "#0f766e"))
k = P(93, D + .7, 34)
add(f'<circle cx="{k[0]}" cy="{k[1]}" r="2.2" fill="#f4c95d"/>')
add(poly([P(68, D + 18, 0), P(106, D + 18, 0), P(106, D + 18, -6), P(68, D + 18, -6)], "#cfc6b4"))
add(poly([P(68, D, 0), P(106, D, 0), P(106, D + 18, 0), P(68, D + 18, 0)], "#e6dfcf"))
# side windows
for y0, y1 in [(22, 62), (88, 128)]:
    add(poly(quad_x(W + .5, y0 - 3, y1 + 3, 34, 86), "#f1e4d0"))
    add(poly(quad_x(W + .6, y0, y1, 37, 83), "#24394a", "pane"))
    mid = (y0 + y1) / 2
    a, b = P(W + .7, mid, 37), P(W + .7, mid, 83)
    add(f'<line x1="{a[0]}" y1="{a[1]}" x2="{b[0]}" y2="{b[1]}" stroke="#f1e4d0" stroke-width="2"/>')
# attic window in the gable
gw = [P(W + .6, D / 2 - 12, H + 22), P(W + .6, D / 2 + 12, H + 22), P(W + .6, D / 2 + 12, H + 44), P(W + .6, D / 2 - 12, H + 44)]
add(poly(gw, "#24394a", "pane"))
add('</g>')

# ── lights: warm glass that fades in over the dark panes ─────────────
add('<g class="h-lights">')
for x0, x1 in front_windows:
    add(poly(quad_y(D + .8, x0, x1, 37, 83), "url(#glow)"))
for y0, y1 in [(22, 62), (88, 128)]:
    add(poly(quad_x(W + .8, y0, y1, 37, 83), "url(#glowSide)"))
add(poly([(x, y) for x, y in gw], "url(#glowSide)"))
lamp = P(108, D + 1, 58)
add(f'<circle cx="{lamp[0]}" cy="{lamp[1]}" r="16" fill="url(#halo)"/>')
add(f'<circle cx="{lamp[0]}" cy="{lamp[1]}" r="3" fill="#ffe7a3"/>')
add('</g>')

add('<g class="h-trees">')
add(tree(-70, 120, 28, "#3f8a4e", "#5fae69"))
add(tree(280, -30, 26, "#3a7f48", "#59a463"))
add(tree(285, 200, 22, "#3f8a4e", "#64b46d"))
for x, y in [(10, D + 26), (130, D + 26), (178, D + 26)]:
    c = P(x, y, G + 9)
    add(f'<ellipse cx="{c[0]}" cy="{c[1]}" rx="15" ry="10" fill="#4f9a5a"/><ellipse cx="{c[0] - 4}" cy="{c[1] - 4}" rx="8" ry="5" fill="#74bf78"/>')
add('</g>')

# ── the sign out front ───────────────────────────────────────────────
add('<g class="h-sign">')
p0, p1 = P(-40, D + 70, G), P(-40, D + 70, G + 78)
add(f'<rect x="{p0[0] - 3}" y="{p1[1]}" width="6" height="{p0[1] - p1[1]}" fill="#f7f1e4"/>')
board = [P(-40, D + 70 - 30, G + 74), P(-40, D + 70 + 30, G + 74), P(-40, D + 70 + 30, G + 44), P(-40, D + 70 - 30, G + 44)]
add(poly([(x, y) for x, y in [(p1[0] - 34, p1[1] + 4), (p1[0] + 34, p1[1] + 4), (p1[0] + 34, p1[1] + 34), (p1[0] - 34, p1[1] + 34)]], "#0f766e"))
add(f'<text x="{p1[0]}" y="{p1[1] + 24}" text-anchor="middle" font-family="Public Sans, Arial, sans-serif" font-weight="700" font-size="14" fill="#fffef9" letter-spacing="1">SOLD</text>')
add('</g>')

defs = """<defs>
<linearGradient id="lawn" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#9fd08a"/><stop offset="1" stop-color="#79b766"/></linearGradient>
<linearGradient id="wallFront" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f6e2c6"/><stop offset="1" stop-color="#ecd2b0"/></linearGradient>
<linearGradient id="wallSide" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#dcbd98"/><stop offset="1" stop-color="#cba983"/></linearGradient>
<linearGradient id="roofFront" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#d4634a"/><stop offset="1" stop-color="#b94a35"/></linearGradient>
<linearGradient id="glow" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffe9a8"/><stop offset="1" stop-color="#f6b74e"/></linearGradient>
<linearGradient id="glowSide" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f7d98c"/><stop offset="1" stop-color="#e3a240"/></linearGradient>
<radialGradient id="halo"><stop offset="0" stop-color="#ffe8a3" stop-opacity=".9"/><stop offset="1" stop-color="#ffe8a3" stop-opacity="0"/></radialGradient>
</defs>"""

svg = ('<svg class="house" viewBox="40 0 590 520" xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="house-title">'
       '<title id="house-title">A house being built, from the ground up</title>' + defs + "".join(out) + "</svg>\n")
Path(__file__).resolve().parent.parent.joinpath("pages/partials/welcome_house.svg").write_text(svg)
print("wrote pages/partials/welcome_house.svg", len(svg), "bytes")
