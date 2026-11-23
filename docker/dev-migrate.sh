#!/bin/sh
# Runs as www-data inside a dev or e2e Nextcloud: on every `make dev-up` and
# `make e2e-up` (dev-seed.sh) and before every e2e run (global setup). The
# mounted code may carry migrations and background jobs its version number
# does not announce yet; Nextcloud applies those only when the version goes
# up, so a stack kept from an older checkout catches up here. occ only offers
# migrations:migrate in debug mode.
set -eu

php -r 'require "/var/www/html/lib/base.php";
  $app = "synaplan_integration";
  (new \OC\DB\MigrationService($app, \OCP\Server::get(\OC\DB\Connection::class)))->migrate();
  $jobs = \OCP\Server::get(\OCP\BackgroundJob\IJobList::class);
  $info = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppInfo($app) ?? [];
  foreach ($info["background-jobs"] ?? [] as $job) {
    if (!$jobs->has($job, null)) {
      $jobs->add($job);
      fwrite(STDERR, "Registered $job\n");
    }
  }'
