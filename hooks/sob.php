<?php

error_reporting(E_ALL);
define('PMAHOOKS', True);


require_once('./lib/github.php');

$data = json_decode($_POST['payload']);

if (! isset($data['pull_request'])) {
    die('No pull request data!');
}

$commits = github_pull_commits($data['pull_request']['number']);

$missing = array();

foreach($commits as $commit) {
    if (strpos("\nSigned-Off-By:", $commit['commit']['message']) === false) {
        $missing[] = $commit['commit']['sha'];
    }
}

if (count($missing) > 0) {
    $message = "Following commits are missing Signed-Off-By:\n\n";
    $message .= implode(', ', $missing);
    $message .= "\n\nSee https://github.com/phpmyadmin/phpmyadmin/blob/master/CONTRIBUTING.md for more information.";
    github_comment($data['pull_request']['number'], $message);
    die('Comment posted.');
}
