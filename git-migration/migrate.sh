#!/bin/sh

# Verbose
set -x

# Exit on failure
set -e

if [ ! -d pma-svn-git ] ; then
    # Init repository on first run
    mkdir pma-svn-git
    cd pma-svn-git
    git svn init -s --no-metadata https://phpmyadmin.svn.sourceforge.net/svnroot/phpmyadmin
    git config svn.authorsfile ../svn2git-authors
else
    cd pma-svn-git
fi

# Fetch revisions
git svn fetch --fetch-all

# Back to top level directory
cd ..

# Create working copy of the clone (we will modify it)
rm -rf pma-svn-git-work
cp -a pma-svn-git pma-svn-git-work

# Make local tags and branches
cd pma-svn-git-work

# Proper email address
git config --add user.email michal@cihar.com

# Tags
git branch -r | sed -rne 's, *tags/([^@]+)$,\1,p' | grep 'RELEASE\|STABLE\|TESTING' | while read tag ; do
    git tag -a $tag -m "Imported SVN tag for $tag" "tags/${tag}^"
done

# Branches
git branch -r | grep -v ' *tags/' | grep 'QA_[0-9_]*$\|MAINT_[0-9_]*$' | while read branch ; do
    git branch $branch $branch
done

# First connect orphan MAINT to QA
for branch in `git branch | grep MAINT_` ; do
    masterbranch=`echo $branch | sed 's/MAINT_/QA_/; s/_[0-9]*$//'`
    olddate="`git log $branch | grep '^Date' | tail -n 1 | sed 's/Date: *//'`"
    masterdate="`git log $masterbranch | grep '^Date' | tail -n 1 | sed 's/Date: *//'`"
    echo "$branch: $olddate / $masterdate"
    if [ "$masterdate" = "$olddate" ] ; then
        continue
    fi
    echo "Branch $branch is outdated!"
    oldtail="`git log $branch | grep '^commit' | tail -n 1 | sed 's/commit *//'`"
    echo $masterbranch
    masterpoint="`git log --before="$olddate" $masterbranch | grep '^commit' | head -n 1 | sed 's/commit *//'`"
    echo "Will point $oldtail to $masterpoint"
    git checkout $branch
    git filter-branch -f --tag-name-filter cat --parent-filter "test \$GIT_COMMIT = $oldtail && echo '-p $masterpoint' || cat" HEAD
    git checkout master
done

# Now connect orphan QA to master
for branch in `git branch | grep QA_` ; do
    olddate="`git log $branch | grep '^Date' | tail -n 1 | sed 's/Date: *//'`"
    echo "$branch: $olddate"
    if [ "Thu May 3 17:25:09 2001 +0000" = "$olddate" ] ; then
        continue
    fi
    echo "Branch is outdated!"
    oldtail="`git log $branch | grep '^commit' | tail -n 1 | sed 's/commit *//'`"
    masterpoint="`git log --before="$olddate" master | grep '^commit' | head -n 1 | sed 's/commit *//'`"
    echo "Will point $oldtail to $masterpoint"
    childfilter=`echo $branch | sed 's/QA_/MAINT_/'`
    git filter-branch -f --tag-name-filter cat --parent-filter "test \$GIT_COMMIT = $oldtail && echo '-p $masterpoint' || cat" -- --all
done

# Back to top level directory
cd ..

# Prepare separate repositories
rm -rf repos
mkdir repos
cd repos

# Clone and filter all top level dirs
for repo in data  history  localized_docs  phpMyAdmin  planet  scripts  themes  website ; do
    git clone ../pma-svn-git-work $repo
    cd $repo
    if [ $repo = phpMyAdmin -o $repo = themes ] ; then
        for branch in `git branch -r | grep 'QA_[0-9_]*$\|MAINT_[0-9_]*$'` ; do
            git branch `basename $branch` $branch
        done
    fi
    git config --add user.email michal@cihar.com
    git filter-branch --subdirectory-filter $repo --tag-name-filter cat -- --all
    cd ..
done
