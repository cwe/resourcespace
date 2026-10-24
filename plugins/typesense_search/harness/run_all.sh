#!/bin/sh
# Run every battery (ab/<ID>_<slug>.php), write each output to results/ab/<name>.txt, then summarise. With
# HARNESS_DB=mysql (H9) the batteries run on MySQL and the results go to results/mysql/ instead.
# Exit 1 if any battery failed, 2 if every battery was skipped (no local Typesense), 0 when all green.
# The private Typesense is started by ab/boot.php when nothing answers on HARNESS_TS_PORT (default 18208)
# and typesense-server is on the PATH; it is left running between runs.
cd "$(dirname "$0")" || exit 1
results=results
[ "$HARNESS_DB" = "mysql" ] && results=results/mysql
mkdir -p "$results/ab"
status=0
ran=0
for f in ab/[A-Z][A-Z0-9]*_*.php; do
    name=$(basename "$f" .php)
    php "$f" > "$results/ab/$name.txt" 2>&1
    code=$?
    tail -1 "$results/ab/$name.txt" | sed "s/^/$name: /"
    if [ "$code" -eq 1 ]; then
        status=1
    elif [ "$code" -eq 0 ]; then
        ran=1
    fi
done
php summarise.php "$results"
if [ "$status" -eq 0 ] && [ "$ran" -eq 0 ]; then
    exit 2
fi
exit $status
