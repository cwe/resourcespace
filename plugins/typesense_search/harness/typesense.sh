#!/bin/sh
# The harness's private Typesense: status, start or stop. ab/boot.php starts it on demand, so this is only
# needed to stop it or to check it. Never point the harness at a Typesense that holds real data: every run
# drops and recreates the harness_* collections and aliases.
#
# Usage: ./typesense.sh status|start|stop
PORT="${HARNESS_TS_PORT:-18208}"
KEY="${HARNESS_TS_KEY:-local-harness-key}"
SCRATCH="${HARNESS_SCRATCH:-${TMPDIR:-/tmp}/rs_typesense_search_harness}"
case "${1:-status}" in
    status)
        if curl -s -m 2 "http://127.0.0.1:$PORT/health" | grep -q '"ok":true'; then
            echo "running on 127.0.0.1:$PORT"
        else
            echo "not running on 127.0.0.1:$PORT"
            exit 1
        fi
        ;;
    start)
        mkdir -p "$SCRATCH/typesense-data"
        exec typesense-server --data-dir="$SCRATCH/typesense-data" --api-key="$KEY" \
            --api-address=127.0.0.1 --api-port="$PORT" --peering-address=127.0.0.1 --peering-port="$((PORT - 1))"
        ;;
    stop)
        PID=$(lsof -t -i "tcp:$PORT" -s tcp:LISTEN 2>/dev/null | head -1)
        if [ -z "$PID" ]; then
            echo "nothing listening on $PORT"
            exit 1
        fi
        kill "$PID" && echo "stopped $PID"
        ;;
    *)
        echo "Usage: $0 status|start|stop" >&2
        exit 2
        ;;
esac
