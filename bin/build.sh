#!/usr/bin/env bash
# Build a distributable zip: password-reset-guard-<version>.zip
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=$(grep -m1 "Version:" password-reset-guard.php | sed 's/[^0-9.]//g')
NAME="password-reset-guard"
OUT="build/${NAME}-${VERSION}.zip"

mkdir -p build
rm -f "$OUT"

# Stage a clean copy so junk and dev files never reach the zip.
STAGE=$(mktemp -d)
mkdir -p "${STAGE}/${NAME}"
rsync -a \
  --exclude 'build' \
  --exclude 'bin' \
  --exclude 'tests' \
  --exclude '.git' \
  --exclude '.gitignore' \
  --exclude 'node_modules' \
  --exclude '.DS_Store' \
  --exclude '*.zip' \
  ./ "${STAGE}/${NAME}/"

( cd "$STAGE" && zip -rq "$OLDPWD/$OUT" "$NAME" -x '*.DS_Store' )
rm -rf "$STAGE"

echo "Built $OUT"
unzip -Z1 "$OUT"
