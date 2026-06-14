# Three services, one repo. Provision each as a separate Railway service that
# starts from this repo with the matching process. Workers/scheduler must NOT
# have an HTTP healthcheck.
web: /bin/sh scripts/docker-web.sh
worker: /bin/sh -c 'rm -f bootstrap/cache/config.php 2>/dev/null; exec php artisan horizon'
scheduler: /bin/sh -c 'rm -f bootstrap/cache/config.php 2>/dev/null; exec php artisan schedule:work'
