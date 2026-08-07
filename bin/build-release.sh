#!/usr/bin/env bash
#
# Builds the release artefacts for the Illdy Companion plugin.
#
# Produces:
#   dist/illdy-companion.zip   installable zip, also the GitHub release asset
#   dist/svn/trunk/            what to copy into the WordPress.org SVN trunk
#   dist/svn/tags/<version>/   what to copy into the matching SVN tag
#
# The WordPress.org `assets/` directory (banner, icon, screenshots) is deliberately
# not produced here: it lives beside trunk/ in SVN, is never part of the plugin, and
# its contents are artwork rather than build output.
#
# Usage:  bin/build-release.sh [output-dir]
#
set -euo pipefail

PLUGIN_SLUG="illdy-companion"
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT="${1:-$ROOT/dist}"
STAGE="$( mktemp -d )"
trap 'rm -rf "$STAGE"' EXIT

header_version() { sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$ROOT/$PLUGIN_SLUG.php" | head -1 | tr -d '\r'; }
readme_version() { sed -n 's/^Stable tag:[[:space:]]*//p' "$ROOT/readme.txt" | head -1 | tr -d '\r'; }
const_version()  { sed -n "s/^define( 'ILLDY_COMPANION', '\\([^']*\\)'.*/\\1/p" "$ROOT/$PLUGIN_SLUG.php" | head -1 | tr -d '\r'; }

V_HEADER="$( header_version )"
V_README="$( readme_version )"
V_CONST="$( const_version )"

echo "Building $PLUGIN_SLUG"
echo "  plugin header : $V_HEADER"
echo "  readme stable : $V_README"
echo "  ILLDY_COMPANION: $V_CONST"

# WordPress.org serves whatever "Stable tag" points at. If these three disagree the
# wrong code goes live, so the build refuses rather than guessing.
if [ "$V_HEADER" != "$V_README" ] || [ "$V_HEADER" != "$V_CONST" ]; then
	echo "Version mismatch - refusing to build." >&2
	exit 1
fi

VERSION="$V_HEADER"
mkdir -p "$OUT" "$STAGE/$PLUGIN_SLUG"

rsync -a \
	--exclude '.git' \
	--exclude '.gitignore' \
	--exclude '.github' \
	--exclude 'node_modules' \
	--exclude 'bin' \
	--exclude 'dist' \
	--exclude 'CLAUDE.md' \
	--exclude '.claude' \
	--exclude '*.map' \
	--exclude '.DS_Store' \
	"$ROOT/" "$STAGE/$PLUGIN_SLUG/"

# WordPress.org's plugin checker flags dotfiles; none should survive the rsync above.
if find "$STAGE/$PLUGIN_SLUG" -name '.*' -not -name '.' | grep -q .; then
	echo "Hidden files present in the build:" >&2
	find "$STAGE/$PLUGIN_SLUG" -name '.*' -not -name '.' >&2
	exit 1
fi

ZIP="$OUT/$PLUGIN_SLUG.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -rq "$ZIP" "$PLUGIN_SLUG" -x '*.DS_Store' )

# SVN payload. trunk/ and the tag hold identical files; WordPress.org expects the tag
# to be a copy rather than a reference.
rm -rf "$OUT/svn"
mkdir -p "$OUT/svn/trunk" "$OUT/svn/tags/$VERSION"
rsync -a "$STAGE/$PLUGIN_SLUG/" "$OUT/svn/trunk/"
rsync -a "$STAGE/$PLUGIN_SLUG/" "$OUT/svn/tags/$VERSION/"

echo
echo "  $ZIP"
echo "  $( du -h "$ZIP" | cut -f1 )  $( unzip -l "$ZIP" | tail -1 | awk '{print $2}' ) files"
echo "  $OUT/svn/trunk/"
echo "  $OUT/svn/tags/$VERSION/"
