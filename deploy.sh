#!/usr/bin/env bash

set -eu
. .env

if [ -z "$DEPLOY_PVID" ]; then
    DEPLOY_PVID=$(
        ssh -q "$DEPLOY_HOST" "php -r 'echo PHP_VERSION_ID;'")
    sed -i "s/DEPLOY_PVID=.*/DEPLOY_PVID=$DEPLOY_PVID/" .env
    echo "[NOTE] Server PHP version: $DEPLOY_PVID, .env updated"
fi

inc_vendor="vendor/"
exc_composer=""
if [[ "$DEPLOY_PVID" < 80200 ]]; then
    inc_vendor=""
    exc_composer="composer*"
    echo "[WARN] Server PHP version is incompatible with current dependencies, ignoring package files."
    read -p $'[WARN] You must manually update packages (if any), enter the command:\n' pkg_update
    [ -n "$pkg_update" ] && ssh -q "$DEPLOY_HOST" "cd $DEPLOY_PATH; $pkg_update"
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
ssh -q "$DEPLOY_HOST" "php $DEPLOY_PATH/main.php restart -d; php $DEPLOY_PATH/main.php status"