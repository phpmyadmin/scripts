#!/bin/sh

# Script to generate phpdoc documentation of phpMyAdmin and publish it
# on develdocs.phpmyadmin.net

set -e

# Update scripts
BUILDER_ROOT=${BUILDER_ROOT:-"/home/builder"}
BUILDER_REPO="$BUILDER_ROOT/${BUILDER_REPO_NAME:-scripts}"
cd "$BUILDER_REPO"
git pull -q

# Update doc build environment
cd develdocs
composer update --quiet

# Generate docs
for repo in phpmyadmin sql-parser motranslator shapefile simple-math ; do
    cd "$BUILDER_ROOT/$repo"
    git pull -q
    # Clean output
    rm -rf "$BUILDER_REPO/develdocs/output/$repo/"
    # Generate config file
    nice -19 "$BUILDER_REPO/develdocs/doctum.php" \
    --root "$BUILDER_ROOT/$repo" \
    --build-dir "$BUILDER_REPO/develdocs/output/$repo/" \
    --cache-dir "$BUILDER_REPO/develdocs/tmp/$repo/" \
    --docs-branch "master" \
    --slug "phpmyadmin/$repo" \
    --output-config "$BUILDER_REPO/develdocs/doctum-$repo.php"
    # Render
    nice -19 "$BUILDER_REPO/develdocs/vendor/bin/doctum.php" update --force --ignore-parse-errors "$BUILDER_REPO/develdocs/doctum-$repo.php"
    # Delete config file
    rm "$BUILDER_REPO/develdocs/doctum-$repo.php"
done
