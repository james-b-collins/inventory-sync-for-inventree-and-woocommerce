# build the plugin zip from the current working tree

set -euo pipefail

SLUG="inventory-sync-for-inventree-and-woocommerce"
MAIN_FILE="${SLUG}.php"
OUT_DIR="dist"

cd "$(dirname "$0")/.."

# Warn if there are uncommitted changes
if [ -n "$(git status --porcelain)" ]; then
  echo "warning: uncommitted changes present" >&2
fi

# Pull the version from the plugin header
header_version="$(sed -n 's/^ \* Version: *//p' "$MAIN_FILE" | head -1 | tr -d '[:space:]')"
if [ -z "$header_version" ]; then
  echo "error: could not read Version from $MAIN_FILE" >&2
  exit 1
fi

# Catch any mismatch between the header and the constant in the main plugin file
const_version="$(sed -n "s/^define( 'INVENTREE_SYNC_VERSION', '\([^']*\)'.*/\1/p" "$MAIN_FILE" | head -1)"
if [ "$header_version" != "$const_version" ]; then
  echo "error: version mismatch - header is '$header_version', INVENTREE_SYNC_VERSION is '$const_version'" >&2
  exit 1
fi

# Catch any mismatch with the tag version
if [ "$#" -ge 1 ]; then
  tag_version="${1#v}"
  if [ "$tag_version" != "$header_version" ]; then
    echo "error: tag '$1' does not match plugin version '$header_version'" >&2
    exit 1
  fi
fi

# Warn if the version is a -dev version
if printf '%s' "$header_version" | grep -q -- '-dev'; then
  echo "warning: building a -dev version ($header_version); set a release version before tagging" >&2
fi

# Build the zip
mkdir -p "$OUT_DIR"
zip_name="${SLUG}-${header_version}.zip"
zip_path="${OUT_DIR}/${zip_name}"
rm -f "$zip_path"

# Create a temporary staging directory, and clean it up on exit
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "${stage}/${SLUG}"
git archive --format=tar HEAD | tar -x -C "${stage}/${SLUG}"
find "${stage}/${SLUG}" -type d -empty -delete

# Zip the staging directory into the output zip file
( cd "$stage" && zip -qr "$zip_name" "$SLUG" )
mv "${stage}/${zip_name}" "$zip_path"

echo "built $zip_path"
echo
echo "contents:"
if command -v unzip >/dev/null 2>&1; then
  # sed rather than tail | head: head exits early, which sends SIGPIPE to tail,
  # and pipefail then fails the whole script after the zip was built fine.
  unzip -l "$zip_path" | sed -n '4,43p'
else
  echo "  (install unzip to list contents)"
fi
