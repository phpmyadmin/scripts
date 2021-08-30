#!/bin/bash

# This script will get a list of currently supported versions from phpmyadmin.net.
# For each version, it pulls the Docker image for each flavor, including major and minor
# version shortcuts (5, 5.0, and 5.0.0). We check that the expected version is listed in ChangeLog.
#
# Future versions could use jq to parse package.json for the "version" field
#
# Written by Isaac Bennetch <bennetch@gmail.com>

supported_versions=$(curl -fsSL 'https://www.phpmyadmin.net/home_page/version.json'|jq -r '.releases|.[]|.version')

if [ "$1" == "-h" ] || [ "$1" == "--help" ]
then
  echo "Checks whether the docker builds are current to the versions presented in the version.json file from phpmyadmin.net"
  echo ""
  echo "Possible arguments:"
  echo "  -v --verbose    Prints some progress output (usually runs silently)"
  echo "  -h --help       Prints this help message"
  exit ""
fi

if [ "$1" == "-v" ] || [ "$1" == "--verbose" ]
then
  verbose=true
else
  verbose=false
fi

test_version() {
	ver=$1
  nodots=${ver//[-.]/}
  container=ptest-$nodots
  for flavor in "$ver" "$ver-fpm" "$ver-fpm-alpine"
  do
    if [ "$verbose" == true ]
    then
      echo "Processing $flavor"
    fi

    docker pull -q phpmyadmin/phpmyadmin:"$flavor" > /dev/null
    docker run --name "$container" -d phpmyadmin/phpmyadmin:"$flavor" > /dev/null
    docker exec -t "$container" grep "$ver" ChangeLog > /dev/null || echo "Failed to find version $ver in $container"
    docker container stop "$container" > /dev/null
    docker container rm "$container" > /dev/null
  done
}

while read -r full_version
# https://unix.stackexchange.com/questions/9784/how-can-i-read-line-by-line-from-a-variable-in-bash
do
	major="${full_version:0:1}"
	minor="${full_version:0:3}"
	test_version "$full_version"
	test_version "$minor"
	test_version "$major"
done < <(printf '%s\n' "$supported_versions")
