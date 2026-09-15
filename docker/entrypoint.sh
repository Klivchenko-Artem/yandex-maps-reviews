#!/bin/sh
set -e

cd /var/www/html
KEY_FILE=storage/app/app.key

mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

# Ключ приложения берём из окружения, а если его нет, генерируем один раз и храним в общем томе storage,
# чтобы стенд поднимался одной командой и сессии переживали перезапуск.
# Генерирует только app (RUN_MIGRATIONS=true); воркер и планировщик ждут готовый ключ, а не пишут свой наперегонки.
if [ -z "$APP_KEY" ]; then
    if [ "${RUN_MIGRATIONS:-false}" = "true" ] && [ ! -s "$KEY_FILE" ]; then
        umask 077
        echo "base64:$(head -c 32 /dev/urandom | base64)" > "$KEY_FILE.tmp"
        mv "$KEY_FILE.tmp" "$KEY_FILE"
        chown www-data:www-data "$KEY_FILE"
    fi
    i=0
    until [ -s "$KEY_FILE" ]; do
        i=$((i + 1))
        [ "$i" -gt 60 ] && echo "Нет ключа приложения в $KEY_FILE" >&2 && exit 1
        sleep 1
    done
    export APP_KEY="$(cat "$KEY_FILE")"
fi

# Всё, что пишет в storage, работает от www-data: иначе лог, созданный воркером от root,
# становится недоступен php-fpm и веб молча перестаёт логировать.
run_as_app() {
    su-exec www-data "$@"
}

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    run_as_app php artisan migrate --force
    run_as_app php artisan db:seed --force
else
    # Воркер не должен стартовать раньше схемы: ждём, пока app накатит миграции.
    i=0
    until run_as_app php artisan migrate:status >/dev/null 2>&1 && ! run_as_app php artisan migrate:status 2>/dev/null | grep -q Pending; do
        i=$((i + 1))
        [ "$i" -gt 90 ] && echo "Миграции так и не накатились" >&2 && exit 1
        sleep 2
    done
fi

run_as_app php artisan config:cache >/dev/null
run_as_app php artisan route:cache >/dev/null

# Мастер php-fpm стартует от root и сам опускает пул до www-data; остальные команды сразу от www-data.
if [ "$1" = "php-fpm" ]; then
    exec "$@"
fi
exec su-exec www-data "$@"
