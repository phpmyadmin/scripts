<?php
/**
 * GitHub webhook to create github releases for composer packages.
 */

error_reporting(E_ALL);

define('PMAHOOKS', true);

require_once('./lib/github.php');

github_verify_post();

/* Parse JSON */
$data = json_decode($_POST['payload'], true);

if (isset($data['zen']) && isset($data['hook']) && isset($data['hook_id'])) {
    json_response(array('pong' => true));
    die();
}

if ($data['ref_type'] != 'tag') {
    fail('Not a tag: ' . $data['ref_type']);
}

$version = $data['ref'];
$tag = $data['ref'];

/* Strip v prefix used on some repos */
if (substr($version, 0, 1) === 'v') {
    $version = substr($version, 1);
}

/* Check version name */
if (preg_match('/^[0-9.-]+$/', $version) === 0) {
    fail('Not a version tag!');
}

$result = github_make_release(
    $data['repository']['name'],
    $tag,
    $version,
    'Released version ' . $version
);

json_response($result);
