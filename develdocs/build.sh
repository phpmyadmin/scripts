#!/bin/sh

# Script to generate phpdoc documentation of phpMyAdmin and publish it
# on develdocs.phpmyadmin.net

# Update scripts
cd /home/builder/scripts/
git pull -q

# Update doc build environment
cd develdocs
~/bin/composer update --quiet

# Generate docs
for repo in phpmyadmin sql-parser motranslator shapefile simple-math ; do
    cd /home/builder/$repo
    git pull -q
    if [ -d './src' ] ; then
        SOURCE='./src'
    else
        SOURCE='./libraries'
    fi
    rm -rf /home/builder/scripts/develdocs/output/$repo/
    nice -19 /home/builder/scripts/develdocs/vendor/bin/apigen generate --todo --quiet --source $SOURCE --destination /home/builder/scripts/develdocs/output/$repo/
done
