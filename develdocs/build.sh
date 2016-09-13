#!/bin/sh

# Script to generate phpdoc documentation of phpMyAdmin and publish it
# on develdocs.phpmyadmin.net

# Update scripts
cd /home/builder/scripts/
git pull -q

# Update doc build environment
cd develdocs
composer update --quiet

# Generate docs
for repo in phpmyadmin sql-parser motranslator shapefile ; do
    cd /home/builder/$repo
    git pull -q
    if [ -d './src' ] ; then
        SOURCE='./src'
    else
        SOURCE='./libraries'
    fi
    nice -19 /home/builder/develdocs/vendor/bin/apigen generate --quiet --source $SOURCE --destination /home/builder/scripts/output/$repo/
done
