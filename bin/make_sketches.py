#!/usr/bin/env python3
"""
Draws the empty-state sketches in pages/partials/sketches/*.svg, in the style of the
"Ian Xiaohei Illustrations" skill (.claude/skills/ian-xiaohei-illustrations, MIT, by Ian):
pure white, thin wobbly black pen lines, a few red / orange / blue notes, lots of space,
and Xiaohei — the small black creature — doing the scene's one job.

The skill expects an image model; here every drawing is plain SVG from this script,
so it stays sharp, tiny and editable. Labels are Persian (font: Vazirmatn, OFL).

    python3 bin/make_sketches.py
"""
from __future__ import annotations

import math
import random
from pathlib import Path

W, H = 640, 360
INK, RED, ORANGE, BLUE = "#161616", "#d6452f", "#e8892b", "#2f6fd1"
OUT = Path(__file__).resolve().parent.parent / "pages/partials/sketches"


class Pen:
    """Hand-drawn primitives: every line is resampled and nudged a little, the same way each run."""

    def __init__(self, seed: int):
        self.r = random.Random(seed)
        self.parts: list[str] = []

    # ── low level ──────────────────────────────────────────────────────
    def _wobble(self, pts, amp=0.9, step=7.0):
        out = []
        phase = self.r.uniform(0, 6.28)
        for (x0, y0), (x1, y1) in zip(pts, pts[1:]):
            d = math.hypot(x1 - x0, y1 - y0)
            n = max(1, int(d / step))
            nx, ny = (-(y1 - y0) / d, (x1 - x0) / d) if d else (0, 0)
            for i in range(n):
                t = i / n
                k = amp * (math.sin(phase + (len(out) * 0.55)) * 0.6 + self.r.uniform(-0.4, 0.4))
                out.append((x0 + (x1 - x0) * t + nx * k, y0 + (y1 - y0) * t + ny * k))
        out.append(pts[-1])
        return out

    @staticmethod
    def _d(pts, close=False):
        s = "M" + " L".join(f"{x:.1f},{y:.1f}" for x, y in pts)
        return s + (" Z" if close else "")

    def path(self, pts, color=INK, width=2.0, close=False, fill="none", dash=None, amp=0.9):
        p = self._wobble(pts + ([pts[0]] if close else []), amp)
        extra = f' stroke-dasharray="{dash}"' if dash else ""
        self.parts.append(f'<path d="{self._d(p, close)}" fill="{fill}" stroke="{color}" stroke-width="{width}"{extra}/>')

    # ── shapes ─────────────────────────────────────────────────────────
    def line(self, x0, y0, x1, y1, **k):
        # pens overshoot a touch at the ends
        ox, oy = (x1 - x0) * 0.02, (y1 - y0) * 0.02
        self.path([(x0 - ox, y0 - oy), (x1 + ox, y1 + oy)], **k)

    def rect(self, x, y, w, h, **k):
        for a, b in [((x, y), (x + w, y)), ((x + w, y), (x + w, y + h)), ((x + w, y + h), (x, y + h)), ((x, y + h), (x, y))]:
            self.line(*a, *b, **k)

    def ellipse_pts(self, cx, cy, rx, ry, a0=0.0, a1=360.0, n=40):
        return [(cx + rx * math.cos(math.radians(a0 + (a1 - a0) * i / n)), cy + ry * math.sin(math.radians(a0 + (a1 - a0) * i / n))) for i in range(n + 1)]

    def ellipse(self, cx, cy, rx, ry, a0=0.0, a1=360.0, **k):
        self.path(self.ellipse_pts(cx, cy, rx, ry, a0, a1), **k)

    def curve(self, pts, n=24):
        """Quadratic-ish smooth curve through control points (Catmull-Rom)."""
        out = []
        p = [pts[0]] + pts + [pts[-1]]
        for i in range(1, len(p) - 2):
            for j in range(n):
                t = j / n
                t2, t3 = t * t, t * t * t
                out.append(tuple(0.5 * ((2 * p[i][c]) + (-p[i - 1][c] + p[i + 1][c]) * t
                                        + (2 * p[i - 1][c] - 5 * p[i][c] + 4 * p[i + 1][c] - p[i + 2][c]) * t2
                                        + (-p[i - 1][c] + 3 * p[i][c] - 3 * p[i + 1][c] + p[i + 2][c]) * t3) for c in (0, 1)))
        out.append(pts[-1])
        return out

    def arrow(self, pts, color=ORANGE, width=2.0, head=7):
        c = self.curve(pts)
        self.path(c, color=color, width=width, amp=0.5)
        (x0, y0), (x1, y1) = c[-3], c[-1]
        a = math.atan2(y1 - y0, x1 - x0)
        for s in (-1, 1):
            self.line(x1, y1, x1 - head * math.cos(a + s * 0.5), y1 - head * math.sin(a + s * 0.5), color=color, width=width)

    def label(self, x, y, text, color=INK, size=21, anchor="middle", arrow_to=None, via=None):
        """Persian note; optional thin pointer that starts beside the note and ends at what it names."""
        self.parts.append(
            f'<text x="{x}" y="{y}" fill="{color}" font-size="{size}" text-anchor="{anchor}" direction="rtl" unicode-bidi="plaintext">{text}</text>')
        if arrow_to:
            half = len(text) * size * 0.29
            tx, ty = arrow_to
            if tx < x - half:
                sx, sy = x - half - 6, y - size * 0.3
            elif tx > x + half:
                sx, sy = x + half + 6, y - size * 0.3
            else:
                sx, sy = x, (y + 8 if ty > y else y - size - 4)
            mx, my = via if via else ((sx + tx) / 2, (sy + ty) / 2 - 10)
            self.arrow([(sx, sy), (mx, my), arrow_to], color=color, width=1.6, head=6)

    def raw(self, s: str):
        self.parts.append(s)

    # ── Xiaohei ────────────────────────────────────────────────────────
    def xiaohei(self, x, y, s=1.0, look=(0.0, 0.0), hands=(), legs="stand", lean=0.0):
        """A solid black bean with white eyes and thin limbs. (x, y) = where the feet meet the ground."""
        bw, bh = 24 * s, 34 * s
        cx, cy = x + lean * 8 * s, y - 15 * s - bh
        pts = []
        for i in range(48):
            a = 2 * math.pi * i / 48
            # bean: narrower, rounder top, flatter bottom, slightly lopsided, hand-drawn edge
            top = math.sin(a) < 0
            rx = bw * (0.94 if top else 1.04) * (1.0 + 0.05 * math.sin(a * 3 + 1.1))
            ry = bh * (1.0 if top else 0.86)
            r = 1 + self.r.uniform(-0.012, 0.012)
            pts.append((cx + rx * math.cos(a) * r + lean * 5 * s * (-math.sin(a)), cy + ry * math.sin(a) * r))
        body = self.curve(pts + [pts[0]], n=4)
        self.raw(f'<path d="{self._d(body, True)}" fill="{INK}" stroke="{INK}" stroke-width="1.4"/>')
        # eyes: white dots with small pupils looking somewhere
        ex = cx + 3 * s + lean * 4 * s
        for dx in (-8 * s, 8 * s):
            px, py = ex + dx, cy - 10 * s
            self.raw(f'<circle cx="{px:.1f}" cy="{py:.1f}" r="{4.6 * s:.1f}" fill="#fff"/>')
            self.raw(f'<circle cx="{px + look[0] * 2.2 * s:.1f}" cy="{py + look[1] * 2.2 * s:.1f}" r="{1.9 * s:.1f}" fill="{INK}"/>')
        # legs
        hip_y = cy + bh * 0.8
        if legs == "sit":
            for dx in (-10 * s, 10 * s):
                self.path([(cx + dx, hip_y), (cx + dx + 12 * s, hip_y + 4 * s), (cx + dx + 14 * s, y)], width=2.0 * s)
        else:
            stride = 7 * s if legs == "walk" else 0
            for dx, st in ((-9 * s, -stride), (9 * s, stride)):
                fx = cx + dx + st
                self.path([(cx + dx, hip_y), (fx, y)], width=2.0 * s)
                self.line(fx, y, fx + 5 * s, y, width=2.0 * s)
        # arms: shoulder -> elbow -> hand, for each target
        for hx, hy in hands:
            sx = cx + (bw * 0.9 if hx > cx else -bw * 0.9)
            sy = cy + 2 * s
            ex2, ey2 = (sx + hx) / 2, (sy + hy) / 2 + 6 * s
            self.path([(sx, sy), (ex2, ey2), (hx, hy)], width=1.9 * s)
        return cx, cy

    def svg(self, title: str) -> str:
        return (f'<svg class="sketch-art" viewBox="0 0 {W} {H}" xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="t">'
                f'<title id="t">{title}</title>'
                f'<rect width="{W}" height="{H}" fill="#fff"/>'
                f'<g stroke-linecap="round" stroke-linejoin="round" font-family="Vazirmatn, Tahoma, sans-serif">'
                + "".join(self.parts) + "</g></svg>\n")


# ── the scenes ────────────────────────────────────────────────────────

def star_pts(cx, cy, r):
    pts = [(cx + r * math.cos(math.radians(-90 + 72 * k)), cy + r * math.sin(math.radians(-90 + 72 * k))) for k in range(5)]
    return [pts[0], pts[2], pts[4], pts[1], pts[3]]


def house(p, x, y, w=24, h=18, width=1.5):
    """A tiny house: (x, y) is the bottom-left corner."""
    p.rect(x, y - h, w, h, width=width)
    p.path([(x - 3, y - h + 1), (x + w / 2, y - h - w * 0.55), (x + w + 3, y - h + 1)], width=width)


def search_none():
    p = Pen(11)
    # a dark hole in the ground: everything that matches these filters
    p.ellipse(310, 276, 130, 30)
    p.ellipse(310, 280, 106, 19, 200, 340, width=1.3)
    for x in range(222, 400, 22):
        p.line(x, 278, x + 8, 290, width=1.0)
    # Xiaohei on the rim, fishing
    p.xiaohei(150, 270, 1.0, look=(1, -0.6), hands=[(190, 214), (196, 224)], lean=0.25)
    p.path(p.curve([(190, 220), (262, 150), (342, 106)]), width=2.2)
    p.line(342, 106, 346, 198, width=1.2)
    # the net comes up empty
    p.ellipse(346, 204, 22, 7)
    p.path(p.curve([(324, 204), (332, 232), (346, 240), (360, 232), (368, 204)]))
    for x in (332, 346, 360):
        p.line(x, 207, x + (346 - x) * 0.3, 234, width=1.0)
    p.line(327, 218, 365, 218, width=1.0)
    p.label(470, 150, "تور خالی", RED, arrow_to=(372, 206), via=(420, 176))
    # the filter dial, turned too tight
    p.ellipse(540, 262, 22, 22)
    p.line(540, 262, 552, 247, width=2.2)
    p.label(540, 306, "فیلتر", INK, size=18)
    p.arrow([(512, 230), (540, 216), (566, 232)], color=ORANGE)
    p.label(540, 198, "کمی بازترش کن", BLUE, size=19)
    return p.svg("چیزی پیدا نشد؛ فیلترها را کمی باز کنید")


def search_unclear():
    p = Pen(23)
    # a big map, held upside down
    p.rect(160, 120, 160, 100, width=2.0)
    for x in (213, 266):
        p.line(x, 122, x, 218, width=1.0, dash="4 4")
    p.path([(226, 168), (252, 168), (252, 148), (226, 148)], close=True, width=1.6)
    p.path([(222, 168), (239, 186), (256, 168)], width=1.6)
    p.line(294, 140, 294, 176, width=1.6)
    p.path([(288, 170), (294, 178), (300, 170)], width=1.6)
    p.label(294, 198, "N", INK, size=13)
    # Xiaohei beside it, holding the corner, puzzled
    p.xiaohei(366, 300, 1.05, look=(-1, -0.8), hands=[(322, 214), (322, 196)], lean=-0.2)
    p.label(392, 196, "؟", RED, size=30)
    p.label(412, 176, "؟", RED, size=20)
    p.label(100, 150, "کجا؟", RED, arrow_to=(156, 168), via=(120, 176))
    # what to write instead
    p.rect(466, 210, 134, 52, width=1.8)
    p.label(533, 242, "Richmond, VA", INK, size=18)
    p.label(533, 160, "شهر، County یا ZIP", BLUE, size=19, arrow_to=(533, 204), via=(548, 186))
    return p.svg("این جا را نتوانستم بخوانم؛ شهر، County یا ZIP بنویسید")


def pipeline_empty():
    p = Pen(37)
    # the team's empty list on a clipboard
    p.rect(420, 100, 150, 192, width=2.0)
    p.rect(470, 90, 50, 16, width=1.6)
    for i, y in enumerate((140, 188, 236)):
        p.rect(436, y - 10, 16, 16, width=1.5)
        p.line(462, y - 2, 552, y - 2, width=1.2, dash="3 5")
    p.path(star_pts(444, 132, 7), color=RED, width=1.5, close=True)
    # Xiaohei walking in, a house carried on its back
    house(p, 140, 214, w=56, h=34, width=1.9)
    p.rect(160, 196, 12, 18, width=1.4)
    p.xiaohei(176, 300, 1.0, look=(1, 0.1), hands=[(146, 220), (198, 220)], legs="walk", lean=0.35)
    p.arrow([(236, 270), (330, 256), (416, 150)], color=ORANGE)
    p.label(110, 130, "اولین خانه", ORANGE, arrow_to=(150, 176), via=(120, 160))
    p.label(330, 120, "ستاره را بزن", BLUE, size=19, arrow_to=(434, 134), via=(400, 112))
    return p.svg("فهرست تیم خالی است؛ با ستاره اولین خانه را اضافه کنید")


def alerts_empty():
    p = Pen(41)
    # a post with a bell
    p.line(320, 104, 320, 300, width=2.2)
    p.line(320, 106, 384, 106, width=2.2)
    p.line(376, 106, 376, 122, width=1.6)
    p.path(p.curve([(356, 154), (360, 130), (376, 122), (392, 130), (396, 154)]), width=2.0)
    p.line(352, 156, 400, 156, width=2.0)
    p.ellipse(376, 162, 4, 4)
    # the rope comes down to Xiaohei, who sits on a crate and waits
    p.path(p.curve([(376, 166), (360, 214), (262, 226)]), width=1.3)
    p.rect(196, 270, 52, 30, width=1.8)
    p.line(196, 300, 272, 300, width=1.6)
    p.xiaohei(212, 270, 1.0, look=(1, -1), hands=[(262, 226)], legs="sit")
    # where new homes come from
    house(p, 556, 300, w=30, h=24, width=1.6)
    p.arrow([(552, 266), (500, 214), (412, 152)], color=ORANGE)
    p.label(470, 330, "ایمیل · تلگرام · واتساپ", ORANGE, size=18)
    p.label(120, 120, "منتظر خانه‌ی تازه", BLUE, arrow_to=(196, 196), via=(150, 170))
    return p.svg("هنوز هشداری ندارید؛ هر خانه‌ی تازه با ایمیل، تلگرام یا واتساپ خبر داده می‌شود")


def market_empty():
    p = Pen(53)
    # a hand-drawn chart whose line has not begun
    p.line(110, 290, 580, 290, width=2.0)
    p.line(110, 290, 110, 104, width=2.0)
    p.path(p.curve([(268, 256), (330, 244), (400, 248), (470, 220), (556, 196)]), color=BLUE, width=1.6, dash="2 7")
    # Xiaohei hammering a flag in where the line should start
    p.line(266, 290, 266, 206, width=2.0)
    p.path([(266, 208), (302, 218), (266, 230)], close=True, color=RED, width=1.8)
    p.xiaohei(214, 290, 0.95, look=(1, -0.7), hands=[(246, 214), (250, 222)], lean=0.25)
    p.line(248, 216, 262, 194, width=2.4)
    p.path([(254, 186), (270, 198), (266, 204), (250, 192)], close=True, width=2.0)
    p.label(360, 168, "یک شهر یا ZIP", RED, size=19, arrow_to=(306, 216), via=(330, 186))
    p.label(520, 164, "روند قیمت", BLUE, size=19, arrow_to=(500, 210), via=(522, 190))
    return p.svg("هنوز منطقه‌ای دنبال نمی‌کنید؛ یک شهر یا ZIP اضافه کنید")


def dashboard_start():
    p = Pen(67)
    # small houses dropping into a funnel
    for hx, hy in ((276, 84), (316, 66), (356, 88)):
        house(p, hx, hy, w=22, h=16)
    p.arrow([(320, 96), (320, 110)], color=ORANGE, head=5)
    p.path([(250, 118), (390, 118), (336, 196), (304, 196)], close=True, width=2.0)
    p.rect(304, 196, 32, 30, width=2.0)
    p.line(276, 226, 364, 226, width=1.8)
    p.line(276, 226, 276, 300, width=1.8)
    p.line(364, 226, 364, 300, width=1.8)
    # the crank on the side, turned by Xiaohei
    p.ellipse(380, 172, 8, 8)
    p.line(380, 172, 412, 196, width=2.2)
    p.ellipse(414, 198, 4, 4)
    p.xiaohei(450, 300, 1.0, look=(-1, -0.5), hands=[(416, 202), (420, 212)], lean=-0.35)
    # out the bottom: a house with its score
    house(p, 300, 286, w=40, h=26, width=1.8)
    p.rect(222, 250, 30, 20, color=RED, width=1.6)
    p.label(237, 265, "۸۲", RED, size=14)
    p.line(252, 262, 298, 270, color=RED, width=1.2)
    p.label(140, 110, "اول یک منطقه را بگرد", ORANGE, arrow_to=(268, 80), via=(250, 96))
    p.label(140, 330, "سود · ریسک · امتیاز", BLUE, size=19, arrow_to=(226, 274), via=(160, 290))
    return p.svg("هنوز فرصتی ثبت نشده؛ اول یک منطقه را جستجو کنید تا خانه‌ها امتیاز بگیرند")


def home_missing():
    p = Pen(79)
    # only the slab and a doorstep remain
    p.path([(200, 266), (440, 266), (500, 232), (260, 232)], close=True, width=2.0)
    p.line(200, 266, 200, 278, width=2.0)
    p.line(440, 266, 440, 278, width=2.0)
    p.line(500, 232, 500, 244, width=2.0)
    p.line(200, 278, 440, 278, width=2.0)
    p.line(440, 278, 500, 244, width=2.0)
    p.rect(300, 258, 34, 8, width=1.6)
    # the outline of what used to stand here
    p.path([(236, 232), (236, 140), (336, 84), (436, 140), (436, 232)], color=BLUE, width=1.4, dash="5 6")
    # Xiaohei, magnifier in hand, searching the slab
    p.xiaohei(346, 252, 0.95, look=(1, 0.6), hands=[(388, 222)])
    p.ellipse(398, 216, 9, 9, width=1.8)
    p.line(404, 223, 412, 231, width=2.2)
    p.label(118, 112, "این خانه دیگر نیست", RED, arrow_to=(232, 150), via=(226, 118))
    p.label(560, 196, "دوباره بگرد", BLUE, size=19, arrow_to=(410, 210), via=(480, 186))
    return p.svg("این خانه در فهرست نیست؛ دوباره جستجو کنید")


SCENES = {
    "search-none": search_none,
    "search-unclear": search_unclear,
    "pipeline-empty": pipeline_empty,
    "alerts-empty": alerts_empty,
    "market-empty": market_empty,
    "dashboard-start": dashboard_start,
    "home-missing": home_missing,
}

if __name__ == "__main__":
    OUT.mkdir(parents=True, exist_ok=True)
    for name, fn in SCENES.items():
        svg = fn()
        (OUT / f"{name}.svg").write_text(svg)
        print(f"{name}.svg  {len(svg):,} bytes")
