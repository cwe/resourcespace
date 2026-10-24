#!/bin/sh
# Every battery through Docker on a POSIX host: builds the php image when missing, starts the private Typesense,
# runs run_all.sh inside the php service (output and exit code are run_all.sh's). Usage: ./run.sh [--rebuild]
# One battery: ./run.sh A3_schema. The code checks: ./run.sh --check (composer install once, phpcs, phpstan).
# HARNESS_DB=mysql ./run.sh [A3_schema] (H9) starts the mysql service and runs core's SQL on it instead of SQLite.
cd "$(dirname "$0")" || exit 1
compose="docker compose -f docker/compose.yaml"
if ! docker version --format '{{.Server.Version}}' > /dev/null 2>&1; then
    echo "Docker is not running (docker version got no answer from the daemon). Start it and retry."
    exit 2
fi
if [ "$1" = "--rebuild" ] || ! docker image inspect rs_typesense_harness/php > /dev/null 2>&1; then
    echo "== building the php image (first run; a few minutes)"
    $compose build php || exit 2
    [ "$1" = "--rebuild" ] && shift
fi
mode=""
if [ "$HARNESS_DB" = "mysql" ] && [ "$1" != "--check" ]; then
    echo "== starting mysql (first start initialises the data volume; a minute)"
    $compose --profile mysql up -d --wait mysql || { echo "mysql did not become healthy (docker compose logs mysql)"; exit 2; }
    mode="-e HARNESS_DB=mysql"
fi
case "$1" in
    "")
        exec $compose run --rm $mode php sh run_all.sh
        ;;
    --check)
        if ! $compose run --rm php test -f /app/vendor/autoload.php; then
            echo "== composer install (first run; a few minutes)"
            $compose run --rm -w /app php composer install --no-interaction --no-progress || exit 2
        fi
        echo "== phpcs plugins/typesense_search"
        $compose run --rm -w /app php phpcs --standard=phpcs.xml.dist --runtime-set ignore_warnings_on_exit 1 plugins/typesense_search
        phpcs=$?
        echo "== phpstan (composer dev:phpstan)"
        $compose run --rm -w /app php composer dev:phpstan -- --memory-limit=2G --no-progress
        phpstan=$?
        echo
        echo "phpcs:   $([ "$phpcs" -eq 0 ] && echo 'no errors' || echo "exit $phpcs")"
        echo "phpstan: $([ "$phpstan" -eq 0 ] && echo 'no errors' || echo "exit $phpstan")"
        [ "$phpcs" -eq 0 ] && [ "$phpstan" -eq 0 ]
        ;;
    *)
        name=$(basename "$1" .php)
        [ -f "ab/$name.php" ] || { echo "no battery ab/$name.php"; exit 2; }
        exec $compose run --rm $mode php php "ab/$name.php"
        ;;
esac
