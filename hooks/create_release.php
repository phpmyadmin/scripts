<?php
/**
 * GitHub webhook to create github releases for composer packages.
 */

error_reporting(E_ALL);

define('PMAHOOKS', True);

require_once('./lib/github.php');

github_verify_post();

/* Parse JSON */
$data = json_decode($_POST['payload'], true);

if ($data['ref_type'] != 'tag') {
    die('Not a tag: ' . $data['ref_type']);
}

$version = $data['ref'];
$tag = $data['ref'];

/* Strip v prefix used on some repos */
if (substr($version, 0, 1) === 'v') {
    $version = substr($version, 1);
}

/* Check tag name */
if (preg_match('/^[0-9.]+$/', $tag) === 0) {
    die('Not a version tag!');
}



$version = implode('.', $parts);

echo github_make_release(
    $data['repository']['name'],
    $tag,
    $version,
    'release ' . $version
);
