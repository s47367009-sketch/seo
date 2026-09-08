#!/usr/bin/env bash
#
# Build the installable package for HooshSEO.
#
#   tools/build-zip.sh            -> dist/hoosh-seo.zip
#   OUT=/tmp/x.zip tools/build-zip.sh
#
# The zip must contain exactly one top-level folder (hoosh-seo/) with the
# plugin header file inside it — that is what WordPress expects when the
# archive is uploaded from the admin. Everything that is not needed at runtime
# (.git, plans, tools, node_modules, dist) is left out, and each directory gets
# a "silence is golden" index.php so directory listing never happens.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="hoosh-seo"
OUT="${OUT:-$ROOT/dist/hoosh-seo.zip}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

say() { printf '  %s\n' "$*"; }
step() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

step "Staging $ROOT/ -> $STAGE/$NAME"
mkdir -p "$STAGE/$NAME"

include=(
	"$NAME.php"
	'uninstall.php'
	'readme.txt'
	'README.md'
	'includes'
	'assets'
	'docs'
	'languages'
)

for item in "${include[@]}"; do
	[ -e "$ROOT/$item" ] || { say "skip (missing): $item"; continue; }
	cp -R "$ROOT/$item" "$STAGE/$NAME/"
done

# Never ship VCS / build junk even if it slipped into a copied folder.
find "$STAGE" -name '.git*' -prune -exec rm -rf {} + 2>/dev/null || true
find "$STAGE" \( -name 'node_modules' -o -name '__pycache__' -o -name '*.map' -o -name '.DS_Store' \) -prune -exec rm -rf {} + 2>/dev/null || true

step "index.php guards"
guard='<?php
// Silence is golden.
'
count=0
while IFS= read -r dir; do
	if [ ! -f "$dir/index.php" ]; then
		printf '%s' "$guard" > "$dir/index.php"
		count=$((count + 1))
	fi
done < <(find "$STAGE/$NAME" -type d | sort)
say "created $count guard files"

step "Sanity checks"
if [ ! -f "$STAGE/$NAME/$NAME.php" ]; then
	echo "ERROR: $NAME.php must sit directly inside the top-level folder." >&2
	exit 1
fi
grep -q 'Plugin Name:' "$STAGE/$NAME/$NAME.php" || { echo "ERROR: missing plugin header." >&2; exit 1; }

if command -v php >/dev/null 2>&1; then
	bad=0
	while IFS= read -r f; do
		php -l "$f" >/dev/null 2>&1 || { echo "  syntax error: ${f#$STAGE/}"; bad=$((bad + 1)); }
	done < <(find "$STAGE/$NAME" -name '*.php' | sort)
	say "php -l: $(find "$STAGE/$NAME" -name '*.php' | wc -l | tr -d ' ') files, $bad with errors"
	[ "$bad" -eq 0 ] || exit 1
elif [ -f /tmp/lint/lint.js ]; then
	files=$(find "$STAGE/$NAME" -name '*.php' -print0 | tr '\0' ' ')
	# shellcheck disable=SC2086
	node /tmp/lint/lint.js $files >/dev/null && say "php-parser: ok"
else
	say "no PHP linter available — skipped syntax check"
fi

for js in "$STAGE/$NAME/assets/"*.js; do
	[ -f "$js" ] || continue
	node -e "new Function(require('fs').readFileSync(process.argv[1],'utf8'))" "$js" \
		&& say "js ok: $(basename "$js")" || { echo "  JS syntax error: $js" >&2; exit 1; }
done

missing=0
for need in "assets/app.js" "assets/app.css" "assets/admin.css" "assets/editor.js" "assets/editor.css" "assets/gutenberg.js" "assets/front.css" "includes/autoloader.php" "uninstall.php"; do
	[ -f "$STAGE/$NAME/$need" ] || { say "MISSING: $need"; missing=$((missing + 1)); }
done
[ "$missing" -eq 0 ] || { echo "ERROR: required files missing." >&2; exit 1; }

step "Zipping"
mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"
( cd "$STAGE" && zip -qr "$OUT" "$NAME" -x '*.git*' )

step "Verifying archive"
say "output: $OUT"
say "size:   $(du -h "$OUT" | cut -f1 | tr -d ' ')"
say "files:  $(unzip -Z1 "$OUT" 2>/dev/null | grep -v '/$' | wc -l | tr -d ' ')"
tops=$(unzip -Z1 "$OUT" | awk -F/ '{print $1}' | sed '/^$/d' | sort -u)
n_tops=$(printf '%s\n' "$tops" | sed '/^$/d' | wc -l | tr -d ' ')
say "top level: $(printf '%s' "$tops" | tr '\n' ' ')"
[ "$n_tops" -eq 1 ] && [ "$(printf '%s' "$tops" | head -1)" = "$NAME" ] || {
	echo "ERROR: the archive must contain exactly one folder named $NAME/ (found $n_tops)." >&2; exit 1; }
unzip -Z1 "$OUT" | grep -qx "$NAME/$NAME.php" || { echo "ERROR: $NAME/$NAME.php not found in the archive." >&2; exit 1; }
say "sha256: $( (sha256sum "$OUT" || shasum -a 256 "$OUT") 2>/dev/null | cut -c1-64)"
say "content root listing:"
unzip -Z1 "$OUT" | grep "^$NAME/[^/]*/$" | sed 's/^/    /'
printf '\n\033[1mOK\033[0m — upload %s from Plugins → Add New → Upload.\n' "$OUT"
