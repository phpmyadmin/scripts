<?php
/**
 * GitHub webhook to create github releases for tags.
 */

error_reporting(E_ALL);

define('PMAHOOKS', True);

require_once('./lib/github.php');

/* Parse JSON */
$data = json_decode($_POST['payload'], true);

if ($data['ref_type'] != 'tag') {
    die('Not a tag');
}

$parts = explode('_', $data['ref']);

if ($parts[0] != 'RELEASE') {
    die('Not a RELEASE');
}

if (! ctype_digit($parts[count($parts) - 1])) {
    die('Not a final release');
}

foreach ($parts as $part) {
    if (! ctype_digit($part)) {
        die('Not a release!');
    }
}

$version = implode('.', array_slice($parts, 1));

echo github_make_release(
    $data['ref'],
    $version,
    'phpMyAdmin release ' . $version
);
