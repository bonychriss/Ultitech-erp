"""Convert the docx to PDF with LibreOffice and record the printed page number of every heading/figure."""
import json
import os
import re
import subprocess
import sys

import pymupdf

T = os.environ['TEMP']
B = os.path.join(T, 'fr_build')
DOCX = os.path.join(B, 'Baraka_Field_Report.docx')
PDF = os.path.join(B, 'Baraka_Field_Report.pdf')
SOFFICE = r'C:\Program Files\LibreOffice\program\soffice.com'

if os.path.exists(PDF):
    os.remove(PDF)
for attempt in range(4):
    subprocess.run([SOFFICE, '--headless', '--norestore', '--convert-to', 'pdf', '--outdir', B, DOCX],
                   capture_output=True, text=True)
    if os.path.exists(PDF):
        break
else:
    sys.exit('PDF conversion failed')

norm = lambda s: re.sub(r'\s+', ' ', s).strip().lower()
d = pymupdf.open(PDF)
texts = [norm(p.get_text()) for p in d]
lines = [[norm(l) for l in p.get_text().splitlines() if l.strip()] for p in d]
lines = [ls[1:] if ls and re.fullmatch(r'[0-9]+|[ivx]+', ls[0]) else ls for ls in lines]
print('PDF_PAGES', len(d))

body = next(i for i in range(1, len(d)) if lines[i] and lines[i][0] == 'chapter one')


def roman(n):
    vals = [(10, 'x'), (9, 'ix'), (5, 'v'), (4, 'iv'), (1, 'i')]
    out = ''
    for v, s in vals:
        while n >= v:
            out += s; n -= v
    return out


def label(idx):
    return str(idx - body + 1) if idx >= body else roman(idx)


e = json.load(open(os.path.join(B, 'entries.json')))
pages = {}
missing = []
cur = 1
for level, text, key in e['toc']:
    k = norm(key)
    start = cur if cur >= body or not k.startswith('chapter') else body
    found = None
    for i in range(start, len(d)):
        if i < body and i != 0 and any(l == k for l in lines[i][:2]):
            found = i; break
        if i >= body and any(l.startswith(k) for l in lines[i]):
            found = i; break
    if found is None:
        missing.append(key); continue
    pages[key] = label(found)
    cur = found
    if found < body and k == 'list of figures':
        cur = body
cur = body
for text, key in e['figs']:
    k = norm(key)
    found = next((i for i in range(cur, len(d)) if any(l.startswith(k) for l in lines[i])), None)
    if found is None:
        missing.append(key); continue
    pages[key] = label(found); cur = found
json.dump(pages, open(os.path.join(B, 'pages.json'), 'w'), indent=1)
print('BODY_START_IDX', body, 'BODY_PAGES', len(d) - body, 'MISSING', missing)
