import pymupdf
from pathlib import Path

king = Path(r'c:\xampp\htdocs\Ultitech-erp\king')
letter = pymupdf.open(king / 'letter-23-09-2026.pdf')
contract = pymupdf.open(king / 'Employment_Contract_PREVIEW.pdf')
print('LETTER pages', letter.page_count)
for i in range(min(2, letter.page_count)):
    p = letter[i]
    print(f' letter p{i+1}', p.rect, 'images', len(p.get_images()))
print('CONTRACT pages', contract.page_count)
for i in range(min(3, contract.page_count)):
    p = contract[i]
    print(f' contract p{i+1}', p.rect)
p0 = letter[0]
print('letter text sample:', repr(p0.get_text('text')[:400]))
for info in p0.get_image_info(xrefs=True):
    print(' img', info.get('xref'), info.get('bbox'), info.get('width'), info.get('height'))
# Render letter page top/bottom crops for inspection
out = king / '_assets'
out.mkdir(exist_ok=True)
pix = p0.get_pixmap(matrix=pymupdf.Matrix(2, 2), alpha=False)
pix.save(str(out / '_letter_page1_full.png'))
print('saved letter page render', pix.width, pix.height)
