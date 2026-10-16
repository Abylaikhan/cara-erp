#!/usr/bin/env bash
# make test-visual — the visual comparison legacy ↔ target at every size of the harness spec (§5: 1440×900, 1920×1080,
# 900×900, 899×900, 390×844), both themes each (tests/visual/legacy_compare.py, VIEWPORT=…). Prints the largest share of
# differing pixels per size; non-zero exit when any screenshot differs by more than 0.5 % or a size gives no result.
# About 8 minutes per size. Needs host python3 with Playwright and Chrome, as make visual-compare; the app must be up.
source "$(dirname "$0")/lib.sh"
status=0
work="$(mktemp -d "${TMPDIR:-/tmp}/promtech-visual.XXXXXX")"
trap 'rm -rf "$work"' EXIT
for vp in ${VIEWPORTS:-1440x900 1920x1080 900x900 899x900 390x844}; do
    info "Visual comparison at ${vp}"
    if ! VIEWPORT="$vp" "${PYTHON:-python3}" tests/visual/legacy_compare.py > "$work/$vp.json" 2> "$work/$vp.err"; then
        status=1
        mkdir -p .local-audit/visual && cp "$work/$vp.err" ".local-audit/visual/failed-${vp}-$(date -u +%Y%m%dT%H%M%SZ).err"
    fi
    python3 - "$work/$vp.json" "$work/$vp.err" <<'PY' || status=1
import json
import sys

text = open(sys.argv[1], encoding='utf-8').read()
if '{' not in text:
    lines = [line for line in open(sys.argv[2], encoding='utf-8').read().splitlines() if line.strip() and not line.startswith(' ')]
    print('  no result:', lines[-1] if lines else '(no error output)', '— the full error output is kept in .local-audit/visual/failed-*.err')
    sys.exit(1)
d = json.loads(text[text.index('{'):])
v = d['diff_percent']
over = sorted(((k, x) for k, x in v.items() if x > 0.5), key=lambda z: -z[1])
listed = ', '.join(f'{k} {x}' for k, x in over[:10])
line = f"{d['viewport']}: {len(v)} screenshots, largest {max(v.values())} %, over 0.5 %: {len(over)}"
print(line + (f' — {listed}' if over else ''))
print(f"  images: {d['output']}")
PY
done
[ "$status" -eq 0 ] && ok "every size within 0.5 %" || fail "some screenshots differ by more than 0.5 % (or a size gave no result)"
