#!/usr/bin/env bash
# bereal-archive — start the local web viewer.
#
# Usage:
#   ./run.sh           # serve on http://127.0.0.1:8123
#   ./run.sh 9000      # serve on a custom port
#
# The script discovers the BeReal export root by looking for a folder
# containing both `Photos/` and `user.json`, starting from where this
# script lives and walking upward.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

find_root() {
  local cur="$1"
  for _ in 1 2 3 4 5; do
    if [[ -d "$cur/Photos" && -f "$cur/user.json" ]]; then
      printf '%s\n' "$cur"
      return 0
    fi
    local parent
    parent="$(dirname "$cur")"
    [[ "$parent" == "$cur" ]] && break
    cur="$parent"
  done
  return 1
}

if ! ROOT="$(find_root "$HERE")"; then
  echo "error: could not find your BeReal export."
  echo "       place this folder inside the unzipped export (it must contain Photos/ and user.json)."
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "error: 'php' is not installed. Install PHP 8+ first (https://www.php.net)."
  exit 1
fi

PORT="${1:-8123}"
echo "BeReal export : $ROOT"
echo "Serving       : http://127.0.0.1:$PORT  (Ctrl-C to quit)"
exec php -S "127.0.0.1:$PORT" -t "$HERE"
