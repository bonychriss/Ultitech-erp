import os
from PIL import Image, ImageDraw, ImageFont

out = os.path.join(os.environ['TEMP'], 'fr_build', 'orgchart.png')
W, H = 2000, 1000
im = Image.new('RGB', (W, H), 'white')
d = ImageDraw.Draw(im)
try:
    f_b = ImageFont.truetype('timesbd.ttf', 34)
    f_r = ImageFont.truetype('times.ttf', 30)
except OSError:
    f_b = f_r = ImageFont.load_default()


def box(cx, cy, w, h, lines, fill):
    x0, y0 = cx - w // 2, cy - h // 2
    d.rounded_rectangle([x0, y0, x0 + w, y0 + h], radius=18, fill=fill, outline='#1f3864', width=4)
    total = len(lines) * 40
    y = cy - total // 2
    for i, t in enumerate(lines):
        f = f_b if i == 0 else f_r
        tw = d.textlength(t, font=f)
        d.text((cx - tw / 2, y), t, fill='black', font=f)
        y += 40
    return (cx, y0, y0 + h)


def line(a, b):
    d.line([a, b], fill='#1f3864', width=4)


top = box(W // 2, 110, 560, 110, ['Executive Director'], '#d9e2f3')
mid = box(W // 2, 330, 560, 110, ['Operations Manager'], '#d9e2f3')
line((W // 2, top[2]), (W // 2, mid[1]))

depts = [
    ['Procurement &', 'Supply Chain'],
    ['Warehouse &', 'Inventory Control'],
    ['Corporate Sales', '& Marketing'],
    ['Finance &', 'Accounting'],
    ['Human Resources', '& Admin'],
]
n = len(depts)
bw, gap = 340, 50
total = n * bw + (n - 1) * gap
x0 = (W - total) // 2 + bw // 2
ybar = 500
line((W // 2, mid[2]), (W // 2, ybar))
line((x0, ybar), (x0 + (n - 1) * (bw + gap), ybar))
for i, lines in enumerate(depts):
    cx = x0 + i * (bw + gap)
    b = box(cx, 640, bw, 150, lines, '#fbe5d6')
    line((cx, ybar), (cx, b[1]))

erp = box(W // 2, 890, total, 110, ['ERP System (Ultitech ERP)', 'shared by all departments for vouchers, accounting, sales and stock'], '#e2efda')
for i in range(n):
    cx = x0 + i * (bw + gap)
    d.line([(cx, 715), (cx, erp[1])], fill='#70ad47', width=3)
im.save(out)
print(out)
