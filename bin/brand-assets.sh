#!/usr/bin/env bash
# Builds every logo image from the owner's original (docs/brand/nyxify-logo.webp, transparent background):
#   assets/images/logo.png                  the settings page's header (shown at 40px, 96px for sharp screens)
#   .wordpress-org/icon-128x128.png         the directory's icon (SVN assets/, not in the zip)
#   .wordpress-org/icon-256x256.png
#   .wordpress-org/banner-772x250.png       the directory's banner
#   .wordpress-org/banner-1544x500.png
# Needs ffmpeg (with the gradients and drawtext filters) and the Segoe UI fonts of Windows; run from Git Bash:
#   bash bin/brand-assets.sh
set -euo pipefail

cd "$(dirname "$0")/.."

src=docs/brand/nyxify-logo.webp
fonts=${NYXIFY_FONTS:-C\\:/Windows/Fonts}
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

ff() { ffmpeg -v error -y "$@"; }

# Resizing with premultiplied alpha, so the transparent (black) pixels don't darken the edges.
resize() { echo "format=gbrap,premultiply=inplace=1,scale=$1:$2:flags=lanczos,unpremultiply=inplace=1,format=rgba"; }

# The mark cropped to its content (1125x834 at 160,176, plus the glow) and centred on a transparent square.
ff -i "$src" -vf "format=rgba,crop=1145:854:150:166,pad=1280:1280:(ow-iw)/2:(oh-ih)/2:color=black@0" \
	-frames:v 1 "$tmp/square.png"

mkdir -p assets/images .wordpress-org
ff -i "$tmp/square.png" -vf "$(resize 96 96)" -frames:v 1 assets/images/logo.png
ff -i "$tmp/square.png" -vf "$(resize 128 128)" -frames:v 1 .wordpress-org/icon-128x128.png
ff -i "$tmp/square.png" -vf "$(resize 256 256)" -frames:v 1 .wordpress-org/icon-256x256.png

# The banner: a dark blue-to-violet background, the mark with a soft glow behind it, the name and the tagline.
ff -f lavfi -i "gradients=s=1544x500:c0=0x070b24:c1=0x1f1150:x0=0:y0=0:x1=1544:y1=500:nb_colors=2:speed=0" \
	-i "$tmp/square.png" \
	-filter_complex "
		[1]$(resize 400 400),split[mark][halo];
		[halo]colorchannelmixer=aa=0.55,gblur=sigma=45[glow];
		[0][glow]overlay=70:50[bg];
		[bg][mark]overlay=70:50,
		drawtext=fontfile='$fonts/seguisb.ttf':text='Nyxify':fontsize=132:fontcolor=white:x=520:y=118,
		drawtext=fontfile='$fonts/segoeui.ttf':text='Shop bots for WooCommerce':fontsize=44:fontcolor=0xc9c6ff:x=526:y=290,
		drawtext=fontfile='$fonts/segoeui.ttf':text='on Telegram, Bale and Rubika':fontsize=44:fontcolor=0xc9c6ff:x=526:y=348,
		format=rgb24" \
	-frames:v 1 .wordpress-org/banner-1544x500.png
ff -i .wordpress-org/banner-1544x500.png -vf "scale=772:250:flags=lanczos" -frames:v 1 .wordpress-org/banner-772x250.png

ls -l assets/images/logo.png .wordpress-org/*.png
