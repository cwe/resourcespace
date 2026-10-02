#!/bin/sh
# Run every battery and write its output to results/. The ab/ batteries need the private Typesense
# (./start_typesense.sh); the trace/ ones and callscan.php need nothing.
cd "$(dirname "$0")" || exit 1
PORT="${HARNESS_TS_PORT:-18108}"
if ! curl -s -m 2 "http://127.0.0.1:$PORT/health" | grep -q '"ok":true'; then
    echo "Typesense is not answering on 127.0.0.1:$PORT - run ./start_typesense.sh first" >&2
    exit 1
fi
mkdir -p results/ab results/trace
for f in ab/[0-9]*.php; do
    name=$(basename "$f" .php)
    echo "ab/$name"
    php "$f" > "results/ab/$name.txt" 2>&1
done
for f in trace/[0-9]*.php; do
    name=$(basename "$f" .php)
    echo "trace/$name"
    php "$f" > "results/trace/$name.txt" 2>&1
done
echo "callscan"
php callscan.php > results/callscan.txt 2>&1
