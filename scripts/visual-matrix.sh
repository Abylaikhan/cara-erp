#!/usr/bin/env bash
# make test-visual — the visual comparison legacy ↔ target at every size of the harness spec (§5: 1440×900, 1920×1080,
# 900×900, 899×900, 390×844), both themes each (tests/visual/legacy_compare.py, VIEWPORT=…). Prints the largest share of
# differing pixels per size; non-zero exit when any screenshot differs by more than 0.5 %. About 8 minutes per size.
# Needs host python3 with Playwright and Chrome, as make visual-compare; the app must be up.
source "$(dirname "$0")/lib.sh"
status=0
for vp in ${VIEWPORTS:-1440x900 1920x1080 900x900 899x900 390x844}; do
    info "Visual comparison at ${vp}"
    out="$(VIEWPORT="$vp" "${PYTHON:-python3}" tests/visual/legacy_compare.py 2>/dev/null)" || status=1
    printf '%s' "$out" | python3 -c '
import json, sys
t = sys.stdin.read()
d = json.loads(t[t.index("{"):])
v = d["diff_percent"]
over = sorted(((k, x) for k, x in v.items() if x > 0.5), key=lambda z: -z[1])
print(f"{d[\"viewport\"]}: {len(v)} screenshots, largest {max(v.values())} %, over 0.5 %: {len(over)}" + (" — " + ", ".join(f"{k} {x}" for k, x in over[:10]) if over else ""))
print(f"  images: {d[\"output\"]}")'
done
[ "$status" -eq 0 ] && ok "every size within 0.5 %" || fail "some screenshots differ by more than 0.5 %"
