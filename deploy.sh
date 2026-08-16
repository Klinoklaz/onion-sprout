#!/usr/bin/env bash

set -eu
. .env

# colored prefix
INFO="[$(tput setaf 2)INFO$(tput sgr0)]"
NOTE="[$(tput setaf 4)NOTE$(tput sgr0)]"
WARN="[$(tput setaf 3)WARN$(tput sgr0)]"

if [ -z "$DEPLOY_PVID" ]; then
    DEPLOY_PVID=$(
        ssh -q "$DEPLOY_HOST" "php -r 'echo PHP_VERSION_ID;'")
    sed -i "s/DEPLOY_PVID=.*/DEPLOY_PVID=$DEPLOY_PVID/" .env
    echo "$INFO Server PHP version: $DEPLOY_PVID, .env updated"
fi

# interactive mode
MODE_I=
[ "${1:-}" = "i" ] && MODE_I=1

INCLUDE="vendor/"
EXCLUDE=
if [[ "$DEPLOY_PVID" < 80200 ]]; then
    INCLUDE=
    EXCLUDE="composer*"
    echo "$WARN Server PHP version is incompatible with" \
        "current dependencies, ignoring package files."
    if [ -n "$MODE_I" ]; then
        echo "$NOTE Enter the command to manually " \
            "update packages, if any:"
        read PKG_UPDATE
        [ -n "$PKG_UPDATE" ] && \
            ssh -q "$DEPLOY_HOST" "cd $DEPLOY_PATH; $PKG_UPDATE"
    fi
fi

if [ -n "$MODE_I" ]; then
    read -r -n 1 -p "$NOTE Edit server .env file? (y=yes)" \
        EDIT_ENV
    if [ "$EDIT_ENV" = "y" ]; then
        echo
        echo "$INFO Opening server .env:"
        ssh -q "$DEPLOY_HOST" "vim $DEPLOY_PATH/.env"
    fi
fi

echo "$INFO rsync begins."
rsync --compress --recursive --verbose \
    --human-readable --progress \
    --include="$INCLUDE" \
    --exclude=".git*" \
    --exclude="deploy.sh" \
    --exclude="$EXCLUDE" \
    --exclude-from=".gitignore" \
    --times --delete ./ "$DEPLOY_HOST:$DEPLOY_PATH/"

echo "$INFO Restarting server."
ssh -q "$DEPLOY_HOST" "cd $DEPLOY_PATH; php main.php restart -d;" \
    "php main.php status"