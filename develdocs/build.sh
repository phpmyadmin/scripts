#!/bin/sh

# Script to generate phpdoc documentation of phpMyAdmin and publish it
# on develdocs.phpmyadmin.net

# Update scripts
BUILDER_ROOT=${BUILDER_ROOT:-"/home/builder"}
cd "$BUILDER_ROOT/scripts/"
git pull -q

# Update doc build environment
cd develdocs
composer update --quiet

# Generate docs
for repo in phpmyadmin sql-parser motranslator shapefile simple-math ; do
    cd "$BUILDER_ROOT/$repo"
    git pull -q
    # Clean output
    rm -rf "$BUILDER_ROOT/scripts/develdocs/output/$repo/"
    # Generate config file
    nice -19 "$BUILDER_ROOT/scripts/develdocs/sami.php" \
    --root "$BUILDER_ROOT/$repo" \
    --build-dir "$BUILDER_ROOT/scripts/develdocs/output/$repo/" \
    --cache-dir "$BUILDER_ROOT/scripts/develdocs/tmp/$repo/" \
    --output-config "$BUILDER_ROOT/scripts/develdocs/sami-$repo.php" \
    --title-of-composer
    # Render
    nice -19 "$BUILDER_ROOT/scripts/develdocs/vendor/bin/sami.php" update  "$BUILDER_ROOT/scripts/develdocs/sami-$repo.php"
    # Delete config file
    rm "$BUILDER_ROOT/scripts/develdocs/sami-$repo.php"
done
