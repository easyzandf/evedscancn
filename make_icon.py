#!/usr/bin/env python3
"""Generate EVE D-Scan favicon: radar sweep + ship silhouette.

v3: filled vivid-blue disc (no empty space, no huge dark area), white radar
rings, large white ship, orange sweep wedge + leading edge + red blip.
"""
import math
from PIL import Image, ImageDraw

SS = 2048           # supersample render size
CX = CY = SS / 2
DISC_R = SS * 0.47  # filled disc radius (fills ~94% of canvas)

def rot(x, y, deg):
    a = math.radians(deg)
    c, s = math.cos(a), math.sin(a)
    return x * c - y * s, x * s + y * c

def main():
    img = Image.new('RGBA', (SS, SS), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)

    BLUE   = (46, 111, 216, 255)      # vivid scan blue
    BLUE_EDGE = (30, 78, 160, 255)    # darker rim
    WHITE  = (244, 248, 255, 255)
    WHITE_SOFT = (255, 255, 255, 70)
    ORANGE = (250, 170, 60, 255)
    ORG_SOFT = (250, 170, 60, 175)
    RED    = (255, 90, 90, 255)

    # --- filled disc + darker rim (fills the canvas, no empty space) ---
    d.ellipse([CX - DISC_R, CY - DISC_R, CX + DISC_R, CY + DISC_R], fill=BLUE)
    rw = SS // 56
    d.ellipse([CX - DISC_R, CY - DISC_R, CX + DISC_R, CY + DISC_R],
              outline=BLUE_EDGE, width=rw)
    # thin white inner highlight ring
    d.ellipse([CX - DISC_R * 0.96, CY - DISC_R * 0.96,
               CX + DISC_R * 0.96, CY + DISC_R * 0.96],
              outline=WHITE_SOFT, width=SS // 220)

    # --- two faint white radar range rings (scan feel) ---
    for rr in (0.34, 0.66):
        d.ellipse([CX - DISC_R * rr, CY - DISC_R * rr,
                   CX + DISC_R * rr, CY + DISC_R * rr],
                  outline=WHITE_SOFT, width=SS // 300)

    # --- radar sweep wedge (upper-right, ship rides inside it) ---
    def wpt(deg, r):
        a = math.radians(deg)
        return CX + math.cos(a) * r, CY + math.sin(a) * r
    d.polygon([(CX, CY), wpt(15, DISC_R * 0.99), wpt(68, DISC_R * 0.99)], fill=ORG_SOFT)
    d.line([(CX, CY), wpt(58, DISC_R * 1.0)], fill=ORANGE, width=SS // 70)

    # --- ship silhouette pointing 45° (nose ~70% of disc radius) ---
    nose = 0.70 * DISC_R
    wing = 0.46 * DISC_R
    ship = [(nose, 0), (0, wing), (-0.20 * nose, 0.18 * wing),
            (-0.50 * nose, 0), (-0.20 * nose, -0.18 * wing), (0, -wing)]
    ship = [rot(x, y, 45) for x, y in ship]
    d.polygon([(CX + x, CY + y) for x, y in ship], fill=WHITE)

    # --- target blip at the sweep leading edge ---
    bx, by = wpt(58, DISC_R * 0.82)
    rr = SS // 70
    d.ellipse([bx - rr, by - rr, bx + rr, by + rr], fill=RED)
    d.ellipse([bx - rr * 2.1, by - rr * 2.1, bx + rr * 2.1, by + rr * 2.1],
              outline=RED, width=SS // 240)

    img = img.resize((256, 256), Image.LANCZOS)
    img.save('favicon.png')
    img.save('favicon.ico', format='ICO',
             sizes=[(16, 16), (24, 24), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)])
    bg = Image.new('RGB', (180, 180), (22, 33, 62))
    s = img.resize((160, 160), Image.LANCZOS)
    bg.paste(s, (10, 10), s)
    bg.save('apple-touch-icon.png')
    print('done: favicon.png, favicon.ico, apple-touch-icon.png')

if __name__ == '__main__':
    main()
