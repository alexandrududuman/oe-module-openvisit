#!/bin/sh
# Jibri runs this after each recording with the recording's folder as $1. It makes the mp4
# streamable, notes its duration and hands the folder to OpenVisit through a .ready marker.
set -eu

dir="$1"
inbox="$(dirname "$dir")"

# Folders OpenVisit has filed but could not remove, since Jibri owns the inbox.
for imported in "$inbox"/*/.imported; do
    [ -e "$imported" ] && rm -rf "$(dirname "$imported")"
done

duration=""
for video in "$dir"/*.mp4; do
    [ -e "$video" ] || continue
    if ffmpeg -nostdin -loglevel error -y -i "$video" -c copy -movflags +faststart "$dir/.faststart.mp4"; then
        mv "$dir/.faststart.mp4" "$video"
    fi
    duration="$(ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "$video" || true)"
done

# OpenEMR runs as another user; it moves the file out and marks the folder.
chmod -R a+rwX "$dir"
printf '%s\n' "$duration" > "$dir/.ready.tmp"
chmod a+rw "$dir/.ready.tmp"
mv "$dir/.ready.tmp" "$dir/.ready"
