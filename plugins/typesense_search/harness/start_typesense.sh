#!/bin/sh
# Start a private, throwaway Typesense for the A/B harness (ab/). Runs in the foreground; Ctrl-C stops it.
# Needs typesense-server on the PATH. Never point the harness at a Typesense that holds real data: it drops
# and rebuilds the harness_* collections on every run.
set -e
PORT="${HARNESS_TS_PORT:-18108}"
KEY="${HARNESS_TS_KEY:-local-harness-key}"
SCRATCH="${HARNESS_SCRATCH:-${TMPDIR:-/tmp}/rs_typesense_harness}"
mkdir -p "$SCRATCH/typesense-data"
exec typesense-server --data-dir="$SCRATCH/typesense-data" --api-key="$KEY" \
    --api-address=127.0.0.1 --api-port="$PORT" --peering-address=127.0.0.1 --peering-port="$((PORT - 1))"
