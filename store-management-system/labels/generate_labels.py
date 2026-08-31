#!/usr/bin/env python3
"""Generate product label PDFs for the store management label module."""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

from reportlab.lib.pagesizes import A4, landscape, portrait
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas


LAYOUTS: dict[int, dict[str, Any]] = {
    1: {
        "cols": 1,
        "rows": 1,
        "landscape": True,
        "wide": True,
        "font": 20,
        "line_gap_mm": 7,
        "image_ratio": 0.44,
        "min_font": 11,
        "text_offset_mm": 14,
    },
    2: {"cols": 2, "rows": 1, "landscape": False, "wide": False, "font": 10, "line_gap_mm": 4.5, "image_ratio": 0.38, "min_font": 7},
    4: {"cols": 2, "rows": 2, "landscape": False, "wide": False, "font": 9, "line_gap_mm": 4, "image_ratio": 0.34, "min_font": 6},
    6: {"cols": 2, "rows": 3, "landscape": False, "wide": False, "font": 8, "line_gap_mm": 3.5, "image_ratio": 0.30, "min_font": 5.5},
    8: {"cols": 2, "rows": 4, "landscape": False, "wide": False, "font": 7, "line_gap_mm": 3, "image_ratio": 0.28, "min_font": 5},
}


def chunk_labels(labels: list[dict[str, Any]], per_page: int) -> list[list[dict[str, Any]]]:
    pages: list[list[dict[str, Any]]] = []
    for index in range(0, len(labels), per_page):
        pages.append(labels[index : index + per_page])
    return pages


def wrap_text_lines(
    text: str,
    font_name: str,
    font_size: float,
    max_width: float,
    c: canvas.Canvas,
) -> list[str]:
    value = (text or "").upper().strip()
    if not value:
        return [""]

    lines: list[str] = []
    current = ""

    for word in value.split():
        candidate = f"{current} {word}".strip() if current else word
        if c.stringWidth(candidate, font_name, font_size) <= max_width:
            current = candidate
            continue

        if current:
            lines.append(current)
            current = ""

        if c.stringWidth(word, font_name, font_size) <= max_width:
            current = word
            continue

        chunk = ""
        for char in word:
            test = chunk + char
            if c.stringWidth(test, font_name, font_size) <= max_width:
                chunk = test
            else:
                if chunk:
                    lines.append(chunk)
                chunk = char
        current = chunk

    if current:
        lines.append(current)

    return lines or [""]


def estimate_blocks_height(
    c: canvas.Canvas,
    blocks: list[str],
    font_name: str,
    font_size: float,
    max_width: float,
    line_gap: float,
    block_gap: float,
) -> float:
    total = 0.0
    for index, block in enumerate(blocks):
        wrapped = wrap_text_lines(block, font_name, font_size, max_width, c)
        total += len(wrapped) * line_gap
        if index < len(blocks) - 1:
            total += block_gap
    return total


def pick_font_size(
    c: canvas.Canvas,
    blocks: list[str],
    max_width: float,
    max_height: float,
    font_name: str,
    start_size: float,
    min_size: float,
    line_gap_mm: float,
    block_gap: float,
) -> tuple[float, float]:
    sizes = []
    size = start_size
    while size >= min_size:
        sizes.append(size)
        size -= 1 if start_size >= 14 else 0.5

    if min_size not in sizes:
        sizes.append(min_size)

    for font_size in sizes:
        line_gap = max(font_size * 1.25, line_gap_mm * mm * (font_size / start_size))
        height = estimate_blocks_height(c, blocks, font_name, font_size, max_width, line_gap, block_gap)
        if height <= max_height:
            return font_size, line_gap

    font_size = min_size
    line_gap = max(font_size * 1.25, line_gap_mm * mm * (font_size / start_size))
    return font_size, line_gap


def draw_text_blocks(
    c: canvas.Canvas,
    x: float,
    y_top: float,
    max_width: float,
    max_height: float,
    font_name: str,
    font_size: float,
    line_gap: float,
    blocks: list[str],
    block_gap: float,
) -> None:
    cursor_y = y_top
    min_y = y_top - max_height

    c.setFont(font_name, font_size)

    for block_index, block in enumerate(blocks):
        for line in wrap_text_lines(block, font_name, font_size, max_width, c):
            if cursor_y - font_size < min_y:
                return
            c.drawString(x, cursor_y - font_size, line)
            cursor_y -= line_gap

        if block_index < len(blocks) - 1:
            cursor_y -= block_gap


def draw_image_box(
    c: canvas.Canvas,
    image_path: str,
    x: float,
    y: float,
    width: float,
    height: float,
) -> None:
    path = Path(image_path)
    if not image_path or not path.is_file():
        c.setFont("Helvetica-Bold", min(8, height / 6))
        c.setFillColorRGB(0.55, 0.55, 0.55)
        c.drawCentredString(x + width / 2, y + height / 2, "NO IMAGE")
        c.setFillColorRGB(0, 0, 0)
        return

    try:
        c.drawImage(
            ImageReader(str(path)),
            x,
            y,
            width=width,
            height=height,
            preserveAspectRatio=True,
            anchor="c",
            mask="auto",
        )
    except Exception:
        c.setFont("Helvetica-Bold", min(8, height / 6))
        c.setFillColorRGB(0.55, 0.55, 0.55)
        c.drawCentredString(x + width / 2, y + height / 2, "NO IMAGE")
        c.setFillColorRGB(0, 0, 0)


def draw_label(
    c: canvas.Canvas,
    label: dict[str, Any],
    x: float,
    y: float,
    width: float,
    height: float,
    layout: dict[str, Any],
) -> None:
    padding = 3 * mm
    border = 2.5 if layout.get("wide") else 1.5
    c.setLineWidth(border)
    c.rect(x, y, width, height, stroke=1, fill=0)

    inner_x = x + padding
    inner_y = y + padding
    inner_w = width - (2 * padding)
    inner_h = height - (2 * padding)
    image_path = str(label.get("image_path") or "")

    font_name = "Helvetica-Bold"
    start_font = float(layout["font"])
    min_font = float(layout.get("min_font", 8))
    block_gap = 3.5 * mm if layout.get("wide") else 2 * mm

    blocks = [
        f"PRODUCT CODE: {label.get('code', '')}",
        f"PRODUCT NAME : {label.get('name', '')}",
        "SIZE(s) :",
    ]

    if layout.get("wide"):
        gap = 4 * mm
        image_w = inner_w * float(layout.get("image_ratio", 0.44))
        text_x = inner_x + image_w + gap
        text_w = inner_w - image_w - gap

        draw_image_box(c, image_path, inner_x, inner_y, image_w, inner_h)

        font_size, line_gap = pick_font_size(
            c,
            blocks,
            text_w,
            inner_h,
            font_name,
            start_font,
            min_font,
            float(layout["line_gap_mm"]),
            block_gap,
        )

        text_offset = float(layout.get("text_offset_mm", 0)) * mm
        draw_text_blocks(
            c,
            text_x,
            inner_y + inner_h - text_offset,
            text_w,
            inner_h - text_offset,
            font_name,
            font_size,
            line_gap,
            blocks,
            block_gap,
        )
        return

    image_ratio = float(layout.get("image_ratio", 0.4))
    image_h = inner_h * image_ratio
    text_h = inner_h - image_h - (2 * mm)

    draw_image_box(c, image_path, inner_x, inner_y + text_h + (2 * mm), inner_w, image_h)

    font_size, line_gap = pick_font_size(
        c,
        blocks,
        inner_w,
        text_h,
        font_name,
        start_font,
        min_font,
        float(layout["line_gap_mm"]),
        block_gap,
    )

    draw_text_blocks(
        c,
        inner_x,
        inner_y + text_h,
        inner_w,
        text_h,
        font_name,
        font_size,
        line_gap,
        blocks,
        block_gap,
    )


def generate_pdf(payload: dict[str, Any], output_path: Path) -> None:
    per_page = int(payload.get("per_page") or 1)
    if per_page not in LAYOUTS:
        per_page = 1

    labels = payload.get("labels") or []
    if not labels:
        raise ValueError("No labels provided")

    layout = LAYOUTS[per_page]
    pages = chunk_labels(labels, per_page)
    page_size = landscape(A4) if layout["landscape"] else portrait(A4)
    page_w, page_h = page_size

    margin = 8 * mm
    gap = 6 * mm

    c = canvas.Canvas(str(output_path), pagesize=page_size)

    for page_labels in pages:
        usable_w = page_w - (2 * margin)
        usable_h = page_h - (2 * margin)
        cols = int(layout["cols"])
        rows = int(layout["rows"])

        cell_w = (usable_w - ((cols - 1) * gap)) / cols
        cell_h = (usable_h - ((rows - 1) * gap)) / rows

        for index, label in enumerate(page_labels):
            col = index % cols
            row = index // cols
            cell_x = margin + (col * (cell_w + gap))
            cell_y = page_h - margin - ((row + 1) * cell_h) - (row * gap)

            draw_label(c, label, cell_x, cell_y, cell_w, cell_h, layout)

        c.showPage()

    c.save()


def main() -> int:
    if len(sys.argv) < 3:
        print("Usage: generate_labels.py <input.json> <output.pdf>", file=sys.stderr)
        return 1

    input_path = Path(sys.argv[1])
    output_path = Path(sys.argv[2])

    try:
        payload = json.loads(input_path.read_text(encoding="utf-8"))
        generate_pdf(payload, output_path)
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
