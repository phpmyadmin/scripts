<?php

error_reporting(E_ALL);
define('PMAHOOKS', True);


$message = "This commit is missing Signed-Off-By line to indicate "
    . "that you agree with phpMyAdmin Developer's Certificate of Origin. "
    . "Please check [contributing documentation]("
    . "https://github.com/phpmyadmin/phpmyadmin/blob/master/CONTRIBUTING.md"
    . ") for more information.";

require_once('./lib/github.php');

$data = json_decode($_POST['payload'], true);

if (! isset($data['pull_request'])) {
    die('No pull request data!');
}

$repo_name = $data['pull_request']['repo']['full_name'];

$commits = github_pull_commits($data['pull_request']['number']);

$missing = array();

foreach($commits as $commit) {
    if (strpos("\nSigned-Off-By:", $commit['commit']['message']) === false) {
        github_comment_commit($repo_name, $commit['sha'], $message)
        $missing[] = $commit['sha'];
    }
}

if (count($missing) > 0) {
//    $message = "Following commits are missing Signed-Off-By line to indicate that you agree with phpMyAdmin Developer's Certificate of Origin:\n\n";
//    $message .= implode(', ', $missing);
//    $message .= "\n\nPlease check [contributing documentation](https://github.com/phpmyadmin/phpmyadmin/blob/master/CONTRIBUTING.md) for more information.";
//    github_comment($data['pull_request']['number'], $message);
    die('Comment posted.');
}
