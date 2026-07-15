#!/usr/bin/env bash
#
# Build a clean, installable LETS WooCommerce plugin zip.
#
# Produces dist/lets-payplus-woocommerce-<version>.zip containing ONLY the runtime
# files under a top-level `lets-payplus-woocommerce/` folder (the slug WordPress
# expects). Dev/build artifacts (bin/, dist/, .git, node_modules, tests, OS cruft)
# are excluded so the package installs cleanly on a stock WordPress.
#
# The SaaS dashboard also serves an on-the-fly zip of the same directory
# (routes/web.php → woocommerce.plugin.download); this script is the reproducible
# release artifact (WordPress.org submission / direct distribution).
#
# Usage:  bash bin/build-plugin.sh
set -euo pipefail

# === CONSTANTS ===
SLUG="lets-payplus-woocommerce"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN_FILE="$PLUGIN_DIR/$SLUG.php"
DIST_DIR="$PLUGIN_DIR/dist"

# Paths excluded from the package (dev-only).
EXCLUDES=(
  "bin/*" "dist/*" "tests/*" "node_modules/*"
  ".git/*" ".github/*" ".DS_Store" "*/.DS_Store" "*.map" "*.zip"
)

# === Sync the shared upsell renderer from the SaaS canonical source ===
# public/upsell/lets-ppu.{css,js} is the SINGLE source of truth. Copy it into the plugin so the
# storefront card is byte-identical to the admin preview, then hard-fail if any copy drifted.
CANON_DIR="$(cd "$PLUGIN_DIR/../.." && pwd)/public/upsell"
declare -a SHARED=("lets-ppu.css:assets/css/lets-ppu.css" "lets-ppu.js:assets/js/lets-ppu.js")
for pair in "${SHARED[@]}"; do
  name="${pair%%:*}"; rel="${pair##*:}"
  if [[ -f "$CANON_DIR/$name" ]]; then
    mkdir -p "$(dirname "$PLUGIN_DIR/$rel")"
    cp "$CANON_DIR/$name" "$PLUGIN_DIR/$rel"
  fi
done
for pair in "${SHARED[@]}"; do
  name="${pair%%:*}"; rel="${pair##*:}"
  if [[ -f "$CANON_DIR/$name" ]] && ! cmp -s "$CANON_DIR/$name" "$PLUGIN_DIR/$rel"; then
    echo "ERROR: $rel drifted from public/upsell/$name — re-run build to re-sync" >&2
    exit 1
  fi
done

# Read the version from the plugin header (single source of truth).
VERSION="$(grep -iE '^[[:space:]]*\*?[[:space:]]*Version:' "$MAIN_FILE" | head -n1 | sed -E 's/.*Version:[[:space:]]*//; s/[[:space:]]*$//')"
if [[ -z "$VERSION" ]]; then
  echo "ERROR: could not read Version from $MAIN_FILE" >&2
  exit 1
fi

# readme.txt "Stable tag" MUST match the header version (WordPress.org rule).
STABLE_TAG="$(grep -iE '^Stable tag:' "$PLUGIN_DIR/readme.txt" | head -n1 | sed -E 's/.*Stable tag:[[:space:]]*//; s/[[:space:]]*$//' || true)"
if [[ -n "$STABLE_TAG" && "$STABLE_TAG" != "$VERSION" ]]; then
  echo "ERROR: readme.txt Stable tag ($STABLE_TAG) != header Version ($VERSION)" >&2
  exit 1
fi

ZIP_NAME="$SLUG-$VERSION.zip"
mkdir -p "$DIST_DIR"
rm -f "$DIST_DIR/$ZIP_NAME"

# Build the zip with a top-level slug folder. We archive from the PARENT so the root
# is `lets-payplus-woocommerce/...`. Prefer the `zip` binary; fall back to PHP's
# ZipArchive (the same builder the SaaS download route uses) when `zip` is absent.
PARENT_DIR="$(dirname "$PLUGIN_DIR")"

if command -v zip >/dev/null 2>&1; then
  ZIP_EXCLUDES=()
  for pattern in "${EXCLUDES[@]}"; do
    ZIP_EXCLUDES+=("-x" "$SLUG/$pattern")
  done
  ( cd "$PARENT_DIR" && zip -r -q "$DIST_DIR/$ZIP_NAME" "$SLUG" "${ZIP_EXCLUDES[@]}" )
else
  echo "NOTE: 'zip' not found — building with PHP ZipArchive." >&2
  PHP_BIN="${PHP_BIN:-php}"
  "$PHP_BIN" -r '
    $slug = $argv[1]; $src = $argv[2]; $out = $argv[3];
    $excl = ["/bin/","/dist/","/tests/","/node_modules/","/.git/","/.github/",".DS_Store",".map"];
    $zip = new ZipArchive();
    if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { fwrite(STDERR, "cannot open zip\n"); exit(1); }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
      $rel = str_replace("\\", "/", substr($f->getPathname(), strlen($src)));
      foreach ($excl as $e) { if (strpos($rel, $e) !== false) { continue 2; } }
      $zip->addFile($f->getPathname(), $slug . "/" . ltrim($rel, "/"));
    }
    $zip->close();
  ' "$SLUG" "$PLUGIN_DIR" "$DIST_DIR/$ZIP_NAME"
fi

echo "Built $DIST_DIR/$ZIP_NAME (version $VERSION)"
