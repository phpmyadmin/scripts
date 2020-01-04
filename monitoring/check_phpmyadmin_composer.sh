#!/bin/bash

supported_versions=$(curl -fsSL 'https://www.phpmyadmin.net/home_page/version.json'|jq -r '.releases|.[]|.version')
working_dir="$HOME/tmp/composer-test"

mkdir -p "$working_dir"
cd "$working_dir" || exit 1

test_version() {
	ver=$1
	echo "Processing $ver"

  composer create-project -q phpmyadmin/phpmyadmin "$ver" "$ver" > /dev/null
  grep "$ver" "$ver/ChangeLog" > /dev/null || echo "Failed to find version $ver in $ver/ChangeLog" 
  rm -rf "$ver"
}

while read -r full_version
# https://unix.stackexchange.com/questions/9784/how-can-i-read-line-by-line-from-a-variable-in-bash
do
  test_version "$full_version"
done < <(printf '%s\n' "$supported_versions")