#!/usr/bin/env bash
set -euo pipefail

git rev-parse --show-toplevel >/dev/null
state="$(git status --porcelain=v1 --untracked-files=all)"
if [ -n "$state" ]; then
    echo 'Refusing deployment: source tree contains changes outside Git.' >&2
    printf '%s\n' "$state" >&2
    echo 'Preserve and reconcile the changes locally; commit and push before deploying.' >&2
    exit 1
fi

if [ -n "${1:-}" ] && [ "$(git rev-parse HEAD)" != "$1" ]; then
    echo 'Refusing deployment: checked-out commit differs from the requested release.' >&2
    exit 1
fi
echo "Source tree clean: $(git rev-parse HEAD)"
