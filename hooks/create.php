<?php
/**
 * GitHub webhook to create github releases for tags.
 */

error_reporting(E_ALL);

define('PMAHOOKS', True);

require_once('./lib/github.php');

github_verify_post();

/* Parse JSON */
$data = json_decode($_POST['payload'], true);

if ($data['ref_type'] != 'tag') {
    fail('Not a tag', 204);
}

$parts = explode('_', $data['ref']);

if ($parts[0] != 'RELEASE') {
    fail('Not a RELEASE', 204);
}

if (! ctype_digit($parts[count($parts) - 1])) {
    fail('Not a final release', 204);
}
// Remove RELEASE prefix
$parts = array_slice($parts, 1);

foreach ($parts as $part) {
    if (! ctype_digit($part)) {
        fail('Not a release!', 204);
    }
}

$version = implode('.', $parts);

$major_version = implode('.', array_slice($parts, 0, 3));

$result = github_make_release(
    'phpmyadmin',
    $data['ref'],
    $version,
    'phpMyAdmin release ' . $version . "\n\n" .
    '* [Download](https://www.phpmyadmin.net/files/' . $version . '/)' . "\n" .
    '* [Release notes](https://www.phpmyadmin.net/files/' . $version . '/)' . "\n" .
    '* [Fixed issues](https://github.com/phpmyadmin/phpmyadmin/issues?q=is%3Aclosed+is%3Aissue+milestone%3A' . $major_version . ")\n"
);

json_response($result);
