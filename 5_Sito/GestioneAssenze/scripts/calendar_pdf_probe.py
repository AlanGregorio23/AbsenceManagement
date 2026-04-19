#!/usr/bin/env python3
import argparse
import calendar
import datetime as dt
import json
import statistics
from collections import Counter
from pathlib import Path

from pypdf import PdfReader
from pypdf.generic import ContentStream


MONTH_CYCLE = [
    "AGOSTO",
    "SETTEMBRE",
    "OTTOBRE",
    "NOVEMBRE",
    "DICEMBRE",
    "GENNAIO",
    "FEBBRAIO",
    "MARZO",
    "APRILE",
    "MAGGIO",
    "GIUGNO",
    "LUGLIO",
]

MONTH_NUM = {
    "GENNAIO": 1,
    "FEBBRAIO": 2,
    "MARZO": 3,
    "APRILE": 4,
    "MAGGIO": 5,
    "GIUGNO": 6,
    "LUGLIO": 7,
    "AGOSTO": 8,
    "SETTEMBRE": 9,
    "OTTOBRE": 10,
    "NOVEMBRE": 11,
    "DICEMBRE": 12,
}


def sig_rgb(sig: str) -> tuple[float, float, float] | None:
    if not sig.startswith("scn:"):
        return None
    try:
        r_s, g_s, b_s = sig.split(":", 1)[1].split(",")[:3]
        return float(r_s), float(g_s), float(b_s)
    except Exception:
        return None


def luminance(sig: str) -> float:
    rgb = sig_rgb(sig)
    if rgb is None:
        return 1e9
    r, g, b = rgb
    return 0.2126 * r + 0.7152 * g + 0.0722 * b


def year_for_month(month_number: int) -> int:
    return 2025 if month_number >= 8 else 2026


def expected_red_days(month_label: str) -> set[int]:
    month_number = MONTH_NUM[month_label]
    year = year_for_month(month_number)
    _, days_in_month = calendar.monthrange(year, month_number)
    if days_in_month >= 31:
        return set()
    return set(range(days_in_month + 1, 32))


def find_dark_green_signature(scn_counter: Counter[str]) -> str | None:
    green_sigs: list[str] = []
    for sig in scn_counter:
        rgb = sig_rgb(sig)
        if rgb is None:
            continue
        r, g, b = rgb
        if g > r and g > b:
            green_sigs.append(sig)
    if not green_sigs:
        return None
    return min(green_sigs, key=luminance)


def find_red_signature(scn_counter: Counter[str]) -> str | None:
    red_sigs: list[str] = []
    for sig in scn_counter:
        rgb = sig_rgb(sig)
        if rgb is None:
            continue
        r, g, b = rgb
        if r > 0.6 and g < 0.25 and b < 0.25:
            red_sigs.append(sig)
    if not red_sigs:
        return None
    return max(red_sigs, key=lambda s: scn_counter[s])


def infer_month_order(
    row_count: int,
    red_by_row: dict[int, set[int]],
    month_order_from_text: list[str],
) -> tuple[list[str], str]:
    if row_count == 12 and red_by_row:
        candidates: list[tuple[int, list[str], str]] = []
        for direction_name, base in [
            ("forward", MONTH_CYCLE),
            ("reverse", list(reversed(MONTH_CYCLE))),
        ]:
            for shift in range(12):
                seq = base[shift:] + base[:shift]
                mismatch = 0
                for row_index in range(12):
                    observed = red_by_row.get(row_index, set())
                    expected = expected_red_days(seq[row_index])
                    mismatch += len(observed.symmetric_difference(expected))
                candidates.append((mismatch, seq, f"{direction_name}+shift{shift}"))

        best_mismatch, best_seq, best_meta = min(candidates, key=lambda item: item[0])
        return best_seq, f"red-calibrated ({best_meta}, mismatch={best_mismatch})"

    if len(month_order_from_text) >= 6:
        return month_order_from_text, "text-positioned"

    return list(reversed(MONTH_CYCLE)), "fallback-reverse-cycle"


def map_fill_rectangles_to_cells(
    rectangles: list[tuple[float, float, float, float]],
    unique_x: list[float],
    unique_y: list[float],
    cell_height: float,
) -> set[tuple[int, int]]:
    """
    Returns set of (row_index, day_number).

    Handles standard 1-cell rectangles and merged vertical rectangles.
    """
    if not unique_x or not unique_y:
        return set()

    overlap_threshold = max(cell_height * 0.35, 8.0)
    cells: set[tuple[int, int]] = set()

    for x, y, w, h in rectangles:
        if not (14.0 <= w <= 17.5):
            continue
        if h < overlap_threshold:
            continue

        day_index = min(range(len(unique_x)), key=lambda i: abs(unique_x[i] - round(x, 1)))
        day_number = day_index + 1
        rect_bottom = y
        rect_top = y + h

        for row_index, row_bottom in enumerate(unique_y):
            row_top = row_bottom + cell_height
            overlap = max(0.0, min(rect_top, row_top) - max(rect_bottom, row_bottom))
            if overlap < overlap_threshold:
                continue
            cells.add((row_index, day_number))

    return cells


def analyze_pdf(pdf_path: Path) -> dict:
    reader = PdfReader(str(pdf_path))

    op_counter: Counter[str] = Counter()
    rgb_color_counter: Counter[tuple[float, float, float]] = Counter()
    gray_color_counter: Counter[float] = Counter()
    scn_counter: Counter[str] = Counter()
    filled_rectangles_by_fill: Counter[str] = Counter()
    fill_rect_samples: dict[str, list[tuple[float, float, float, float]]] = {}
    fill_rectangles_all: dict[str, list[tuple[float, float, float, float]]] = {}
    text_items: list[tuple[float, float, str]] = []
    page_previews: list[dict] = []

    for page_index, page in enumerate(reader.pages, start=1):
        def visitor_text(text, _cm, tm, _font_dict, _font_size):
            clean = (text or "").strip()
            if not clean:
                return
            try:
                x = float(tm[4])
                y = float(tm[5])
            except Exception:
                return
            text_items.append((x, y, clean))

        extracted_text = (page.extract_text(visitor_text=visitor_text) or "").strip()
        page_preview = {
            "page": page_index,
            "text_length": len(extracted_text),
            "text_preview": extracted_text.replace("\n", " ")[:300] if extracted_text else "",
            "content_parse_error": None,
        }

        try:
            content = ContentStream(page.get_contents(), reader)
        except Exception as exc:
            page_preview["content_parse_error"] = repr(exc)
            page_previews.append(page_preview)
            continue

        current_fill = "default:black"
        pending_rectangles: list[tuple[float, float, float, float]] = []

        for operands, op in content.operations:
            op_name = op.decode("latin1") if isinstance(op, bytes) else str(op)
            op_counter[op_name] += 1

            if op_name == "rg" and len(operands) == 3:
                rgb = (float(operands[0]), float(operands[1]), float(operands[2]))
                current_fill = f"rgb:{rgb[0]:.4f},{rgb[1]:.4f},{rgb[2]:.4f}"
                rgb_color_counter[rgb] += 1
                continue

            if op_name == "g" and len(operands) == 1:
                gray = float(operands[0])
                current_fill = f"gray:{gray:.4f}"
                gray_color_counter[gray] += 1
                continue

            if op_name in {"scn", "sc"}:
                parts: list[str] = []
                for value in operands:
                    if isinstance(value, (int, float)):
                        parts.append(f"{float(value):.4f}")
                    else:
                        parts.append(str(value))
                signature = ",".join(parts)
                current_fill = f"{op_name}:{signature}"
                scn_counter[current_fill] += 1
                continue

            if op_name == "re" and len(operands) == 4:
                pending_rectangles.append(
                    (
                        float(operands[0]),
                        float(operands[1]),
                        float(operands[2]),
                        float(operands[3]),
                    )
                )
                continue

            if op_name in {"f", "F", "f*"} and pending_rectangles:
                filled_rectangles_by_fill[current_fill] += len(pending_rectangles)
                samples = fill_rect_samples.setdefault(current_fill, [])
                all_rects = fill_rectangles_all.setdefault(current_fill, [])
                all_rects.extend(pending_rectangles)
                while pending_rectangles and len(samples) < 6:
                    samples.append(pending_rectangles.pop(0))
                pending_rectangles.clear()
                continue

            if op_name in {"S", "s", "n", "B", "B*"}:
                pending_rectangles.clear()

        page_previews.append(page_preview)

    day_grid_rects: list[tuple[float, float, float, float]] = []
    for rects in fill_rectangles_all.values():
        for x, y, w, h in rects:
            if 14.0 <= w <= 17.5 and 50.0 <= h <= 70.5:
                day_grid_rects.append((x, y, w, h))

    unique_x = sorted({round(x, 1) for x, _y, _w, _h in day_grid_rects})
    unique_y = sorted({round(y, 1) for _x, y, _w, _h in day_grid_rects}, reverse=True)
    cell_heights = [h for _x, _y, _w, h in day_grid_rects]
    cell_height = float(statistics.median(cell_heights)) if cell_heights else 61.0

    month_hits = [
        (x, y, t.upper())
        for x, y, t in text_items
        if t.upper() in MONTH_CYCLE
    ]
    month_order_from_text: list[str] = []
    if month_hits:
        month_hits_sorted = sorted(month_hits, key=lambda it: (-it[1], it[0]))
        seen: set[str] = set()
        for _x, _y, label in month_hits_sorted:
            if label not in seen:
                seen.add(label)
                month_order_from_text.append(label)

    dark_green_sig = find_dark_green_signature(scn_counter)
    red_sig = find_red_signature(scn_counter)

    red_by_row: dict[int, set[int]] = {}
    if red_sig is not None:
        red_cells = map_fill_rectangles_to_cells(
            fill_rectangles_all.get(red_sig, []),
            unique_x,
            unique_y,
            cell_height,
        )
        for row_index, day_number in red_cells:
            red_by_row.setdefault(row_index, set()).add(day_number)

    month_order, month_order_reason = infer_month_order(
        len(unique_y),
        red_by_row,
        month_order_from_text,
    )

    month_by_row_index: dict[int, str] = {}
    for idx, _y in enumerate(unique_y):
        if idx < len(month_order):
            month_by_row_index[idx] = month_order[idx]

    dark_dates: list[str] = []
    dark_by_row: dict[int, set[int]] = {}
    if dark_green_sig is not None:
        dark_cells = map_fill_rectangles_to_cells(
            fill_rectangles_all.get(dark_green_sig, []),
            unique_x,
            unique_y,
            cell_height,
        )
        for row_index, day_number in dark_cells:
            dark_by_row.setdefault(row_index, set()).add(day_number)
            month_label = month_by_row_index.get(row_index)
            if not month_label:
                continue
            month_number = MONTH_NUM.get(month_label)
            if not month_number:
                continue
            year = year_for_month(month_number)
            try:
                date_obj = dt.date(year, month_number, day_number)
            except ValueError:
                continue
            dark_dates.append(date_obj.isoformat())

    dark_dates = sorted(set(dark_dates))

    return {
        "pdf": str(pdf_path),
        "size_bytes": pdf_path.stat().st_size,
        "pages": len(reader.pages),
        "page_previews": page_previews,
        "op_counter": op_counter,
        "rgb_color_counter": rgb_color_counter,
        "gray_color_counter": gray_color_counter,
        "scn_counter": scn_counter,
        "filled_rectangles_by_fill": filled_rectangles_by_fill,
        "fill_rect_samples": fill_rect_samples,
        "month_order": month_order,
        "month_order_source": month_order_reason,
        "unique_x": unique_x,
        "unique_y": unique_y,
        "cell_height": cell_height,
        "dark_green_signature": dark_green_sig,
        "red_signature": red_sig,
        "dark_dates": dark_dates,
        "dark_by_row": dark_by_row,
        "red_by_row": red_by_row,
        "month_by_row_index": month_by_row_index,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("pdf", type=Path)
    parser.add_argument(
        "--json",
        action="store_true",
        dest="json_mode",
        help="Kept for compatibility with the Laravel caller. Output is always JSON.",
    )
    args = parser.parse_args()

    pdf_path = args.pdf
    if not pdf_path.exists():
        print(json.dumps({"error": f"file not found: {pdf_path}"}))
        return 1

    result = analyze_pdf(pdf_path)
    payload = {
        "dates": result["dark_dates"],
        "metadata": {
            "month_order": result["month_order"],
            "month_order_source": result["month_order_source"],
            "columns": len(result["unique_x"]),
            "rows": len(result["unique_y"]),
            "dark_signature": result["dark_green_signature"],
            "red_signature": result["red_signature"],
            "red_by_row": {
                str(k): sorted(v) for k, v in result["red_by_row"].items()
            },
            "dark_by_row": {
                str(k): sorted(v) for k, v in result["dark_by_row"].items()
            },
        },
    }
    print(json.dumps(payload, ensure_ascii=False))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
