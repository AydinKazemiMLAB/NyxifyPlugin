#!/usr/bin/env bash
# Takes the directory's four screenshots (the captions of readme.txt's "Screenshots") from the admin page's mockup,
# so they show sample data only: no real store, token, PIN, license key or person.
#   .wordpress-org/screenshot-1.png   activating the shop with a license key
#   .wordpress-org/screenshot-2.png   the shop's status
#   .wordpress-org/screenshot-3.png   the bots
#   .wordpress-org/screenshot-4.png   the admin PIN and the bot admins
# Needs Chrome (CHROME, by default Windows' install path) and a current mockup (composer mockup); from Git Bash:
#   bash bin/screenshots.sh
# Each shot takes two passes: the first measures the frame (bin/screenshots.js writes its height to the title), the
# second captures a window of exactly that height. Both hide the scrollbar, or the page is narrower when measured.
set -euo pipefail

cd "$(dirname "$0")/.."

chrome=${CHROME:-"/c/Program Files/Google/Chrome/Application/chrome.exe"}
width=1440
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

{ cat docs/mockups/admin-page.html; echo '<script>'; cat bin/screenshots.js; echo '</script>'; } > "$tmp/shots.html"
page="file:///$(cygpath -m "$tmp/shots.html" 2>/dev/null || echo "$tmp/shots.html")"
profile=$(cygpath -w "$tmp/profile" 2>/dev/null || echo "$tmp/profile")
out=$(cygpath -w "$PWD/.wordpress-org" 2>/dev/null || echo "$PWD/.wordpress-org")

chrome() { "$chrome" --headless=new --disable-gpu --hide-scrollbars --user-data-dir="$profile" --virtual-time-budget=4000 "$@" 2>/dev/null; }

states=(setup-en active-en active-en active-en)
for n in 1 2 3 4; do
	url="$page?shot=$n#${states[$((n - 1))]}"
	height=$(chrome --window-size=$width,900 --dump-dom "$url" | grep -o 'FRAME:[0-9]*' | head -1 | cut -d: -f2)
	chrome --window-size=$width,"$height" --screenshot="$out/screenshot-$n.png" "$url" >/dev/null
	echo "screenshot-$n.png: ${width}x$height"
done
