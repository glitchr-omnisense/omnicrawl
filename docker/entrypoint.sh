#!/bin/sh
# Composes the harness (this core + every provider), installs it when it changed, then runs the
# command: "test" for PHPUnit, "bare" for the script that uses omnicrawl with no framework at all,
# "live" for the checks against the local test site, "phpstan" for PHPStan, "composer ...", "sh" or
# "php" as they are.
set -e
php /omnicrawl/core/docker/harness/setup.php
cd /harness
if [ ! -f vendor/autoload.php ]; then
    composer install --no-progress
elif [ composer.json -nt composer.lock ]; then
    composer update --no-progress
fi
case "${1:-test}" in
    test) shift || true; exec vendor/bin/phpunit --exclude-group live "$@" ;;
    live) shift || true; exec vendor/bin/phpunit --group live "$@" ;;
    bare) shift || true; exec php /omnicrawl/core/docker/harness/bin/bare "$@" ;;
    phpstan)
        status=0
        for dir in /omnicrawl/core /harness/packages/*; do
            [ -f "$dir/phpstan.neon" ] || continue
            echo "== $dir"
            vendor/bin/phpstan analyse --no-progress --memory-limit=1G -c "$dir/phpstan.neon" --autoload-file=/harness/vendor/autoload.php "$dir" || status=1
        done
        exit $status ;;
    composer|sh|php) exec "$@" ;;
    *) echo "test, live, bare, phpstan, composer, sh or php" >&2; exit 2 ;;
esac
