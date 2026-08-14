#!/usr/bin/env bash

set -eu
. .env

if [ -z "$DEPLOY_PVID" ]; then
    DEPLOY_PVID=$(
        ssh -q "$DEPLOY_HOST" "php -r 'echo PHP_VERSION_ID;'")
fi

inc_vendor="vendor/"
exc_composer=""
if [[ "$DEPLOY_PVID" < 80200 ]]; then
    inc_vendor=""
    exc_composer="composer*"
    echo "[WARN] Server PHP version is incompatible " \
        "with current dependencies, ignoring package files."
fi

echo "[NOTE] rsync begin."
rsync --compress --recursive --verbose \
    --human-readable --progress \
    --include="$inc_vendor" \
    --exclude=".git*" \
    --exclude="deploy.sh" \
    --exclude="$exc_composer" \
    --exclude-from=".gitignore" \
    --times --delete ./ "$DEPLOY_HOST:$DEPLOY_PATH/"

echo "[NOTE] Restarting server."
ssh -q "$DEPLOY_HOST" "php $DEPLOY_PATH/main.php restart -d;" \
    "php $DEPLOY_PATH/main.php status"