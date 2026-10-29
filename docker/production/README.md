# Production image

PHP 8.4 + Apache image for running KumuliCP. The sibling `docker/8.x` directories are the Sail development images; this one is for deployment.

```bash
docker build -t kumulicp:main docker/production
```

The `Dockerfile` fetches the app from GitHub (`APP_REPO_URL`, branch or tag `APP_REF`, default `main`), runs `composer install` and the JS build, and produces the runtime image. Only this directory is the build context, so a local checkout never leaks into the image. While the project has no release cadence the image tracks `main`; a commented-out `release` stage in the `Dockerfile` shows how to build from a [GitHub release](https://github.com/kumulicp/kumulicp/releases) instead. The commit that was built is in `/etc/kumulicp-revision`.

| File | What it is |
|---|---|
| `Dockerfile` | base (PHP, Apache, extensions) → source → vendor → assets → runtime |
| `entrypoint.sh` | Start-up: builds `.env`, merges the image's `storage/app` files into the storage volume, runs migrations |
| `supervisord.conf` | Apache, 8 + 2 queue workers, the scheduler (all log to stdout/stderr) |
| `env.defaults` | Non-secret defaults for `.env` |

## What the image requires at runtime

- Runs as `www-data` (33:33). Nothing needs root. Apache listens on **8080** (`APACHE_PORT`), not 80.
- The root filesystem can be read-only. These paths must be writable: `/tmp`, `/var/run/apache2`, `/var/lock/apache2`, `/var/www/html/bootstrap/cache`, and the volume for `/var/www/html/storage` (writable by uid/gid 33; on Kubernetes `fsGroup: 33`).
- `/var/www/html/.env` is a symlink to `/tmp/.env`. `entrypoint.sh` rebuilds it on every start from the non-secret defaults in the image (`.env.defaults`) plus the container's environment. Secrets reach the app only through the environment, never through the image.
- `storage/` is a volume, so the image keeps its tracked `storage/app` files (card images, `cities.json`) in `/usr/local/share/kumulicp/storage-app` and the entrypoint copies them in on start, without overwriting newer files. `modules/` is empty and baked into the image; it is not a volume.
- Only what the app needs to run is in the image (no tests, docs, scripts, `resources/js`, `node_modules`).
- The scheduler is `php artisan schedule:work`, started by supervisord. There is no crond.

Smoke test (needs a database):

```bash
docker run --rm --read-only --user 33:33 --cap-drop ALL --security-opt no-new-privileges \
  --tmpfs /tmp:uid=33,gid=33 --tmpfs /var/run/apache2:uid=33,gid=33 --tmpfs /var/lock/apache2:uid=33,gid=33 \
  --tmpfs /var/www/html/bootstrap/cache:uid=33,gid=33 \
  --tmpfs /var/www/html/storage:uid=33,gid=33,exec \
  -e APP_ENV=production -e APP_KEY=base64:CHANGE_ME -e APP_URL=http://localhost \
  -e DB_HOST=... -e DB_PORT=3306 -e DB_DATABASE=... -e DB_USERNAME=... -e DB_PASSWORD=... \
  kumulicp:main
# then: curl http://<container>:8080/up
```
