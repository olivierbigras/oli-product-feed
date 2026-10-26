#!/usr/bin/env bash
# Build an installable WordPress.org-style ZIP of Oli Product Feed.
#
# Usage:
#   bin/build-zip.sh [output.zip]
#
# Default output: dist/oli-product-feed-<version>.zip
#
# Archive layout (same as a wp.org version ZIP):
#   oli-product-feed/
#     oli-product-feed.php
#     uninstall.php
#     readme.txt
#     CHANGELOG.md
#     LICENSE
#     assets/…
#     languages/…   (*.pot only; WordPress.org serves translations)
#     src/…
#
# Development files are omitted: tests, .git, node_modules, vendor,
# tools, bin, dist, composer/phpunit/phpcs/npm manifests, editor junk.

set -euo pipefail

export TZ=UTC
export LC_ALL=C

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="oli-product-feed"
PLUGIN_FILE="${ROOT}/${SLUG}.php"
README_FILE="${ROOT}/readme.txt"

if [[ ! -f "$PLUGIN_FILE" ]]; then
	echo "error: missing ${PLUGIN_FILE}" >&2
	exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
	echo "error: zip is required" >&2
	exit 1
fi

VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$PLUGIN_FILE" | head -n 1 | tr -d '\r')"
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$README_FILE" | head -n 1 | tr -d '\r')"

if [[ -z "$VERSION" ]]; then
	echo "error: could not read Version from plugin header" >&2
	exit 1
fi

if [[ -z "$STABLE" ]]; then
	echo "error: could not read Stable tag from readme.txt" >&2
	exit 1
fi

if [[ "$VERSION" != "$STABLE" ]]; then
	echo "error: plugin header Version (${VERSION}) != readme.txt Stable tag (${STABLE})" >&2
	exit 1
fi

DEFAULT_OUT="${ROOT}/dist/${SLUG}-${VERSION}.zip"
OUT="${1:-$DEFAULT_OUT}"
mkdir -p "$(dirname "$OUT")"
OUT="$(cd "$(dirname "$OUT")" && pwd)/$(basename "$OUT")"

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}-zip.XXXXXX")"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

DEST="${STAGE}/${SLUG}"
mkdir -p "$DEST"

copy_paths=(
	"${SLUG}.php"
	uninstall.php
	readme.txt
	CHANGELOG.md
	LICENSE
	assets
	languages
	src
)

for rel in "${copy_paths[@]}"; do
	if [[ ! -e "${ROOT}/${rel}" ]]; then
		echo "error: missing ${rel}" >&2
		exit 1
	fi
	cp -a "${ROOT}/${rel}" "${DEST}/${rel}"
done

while IFS= read -r -d '' path; do
	rm -rf "$path"
done < <(find "$DEST" \( \
	-name '.git' -o -name '.github' -o -name 'node_modules' -o -name 'vendor' \
	-o -name 'tests' -o -name 'tools' -o -name 'bin' -o -name 'dist' \
	-o -name '.gitignore' -o -name '.gitattributes' -o -name '.editorconfig' \
	-o -name 'composer.json' -o -name 'composer.lock' \
	-o -name 'phpunit.xml' -o -name 'phpunit.xml.dist' \
	-o -name 'phpcs.xml' -o -name 'phpcs.xml.dist' \
	-o 	-name 'package.json' -o -name 'package-lock.json' \
	-o -name '.DS_Store' -o -name '*.po' -o -name '*.mo' \
	\) -print0)

if git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	# Timestamp from packaged paths only, so build-script-only commits
	# do not change the ZIP hash.
	STAMP="$(git -C "$ROOT" log -1 --format=%ct -- "${copy_paths[@]}")"
else
	STAMP="$(date +%s)"
fi
find "$DEST" -exec touch -d "@${STAMP}" {} +

rm -f "$OUT"
(
	cd "$STAGE"
	find "$SLUG" -print | LC_ALL=C sort | zip -X -q "$OUT" -@
)

echo "Built ${OUT}"
echo "Version ${VERSION}"
if command -v md5sum >/dev/null 2>&1; then
	md5sum "$OUT"
else
	md5 -q "$OUT"
fi
unzip -l "$OUT"
