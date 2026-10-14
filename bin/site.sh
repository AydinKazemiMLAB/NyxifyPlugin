#!/usr/bin/env bash
# Assembles the service's public pages (nyxify.nyxon.tech: the terms of service and the privacy policy, in English
# and Persian) into dist/site/: the pages and their stylesheet from site/, the logo and the Vazirmatn font (with its
# licence) from the plugin's assets, so nothing is loaded from elsewhere. The folder opens from disk as it is, and is
# what goes to the server's /var/www/nyxify-site. From Git Bash:
#   bash bin/site.sh
set -euo pipefail

cd "$(dirname "$0")/.."

out=dist/site
rm -rf "$out"
mkdir -p "$out/fonts"
cp -R site/. "$out/"
cp assets/images/logo.png "$out/logo.png"
cp assets/fonts/vazirmatn/Vazirmatn-Variable.woff2 assets/fonts/vazirmatn/OFL.txt "$out/fonts/"
find "$out" -type f | sort
