<?php
/**
 * GitHub webhook to check Signed-Off-By in pull request commits.
 */

error_reporting(E_ALL);

define('PMAHOOKS', True);

require_once('./lib/github.php');

$message_sob = "This commit is missing Signed-Off-By line to indicate "
    . "that you agree with phpMyAdmin Developer's Certificate of Origin. "
    . "Please check [contributing documentation]("
    . "https://github.com/phpmyadmin/phpmyadmin/blob/master/CONTRIBUTING.md"
    . ") for more information.";

$message_tab = "This commit is using tab character for indentation instead "
    . "of spaces, what is mandated by phpMyAdmin. Please check our "
    . "[Developer guidelines]"
    . "(http://wiki.phpmyadmin.net/pma/Developer_guidelines#Indentation)"
    . " for more information.";

/* Parse JSON */
$data = json_decode($_POST['payload'], true);

/* Check request data */
if (! isset($data['pull_request']) || ! isset($data['action'])) {
    die('No pull request data!');
}

/* We don't care about closed requests */
if ($data['action'] == 'closed') {
    die();
}

/* Parse repository name */
$repo_name = $data['pull_request']['head']['repo']['full_name'];

/* List commits in the pull request */
$commits = github_pull_commits($data['pull_request']['number']);

/* Process commits in the pull request */
foreach ($commits as $commit) {
    /* Chek for missing SOB */
    if ( ! preg_match("@\nSigned-off-by:@i", $commit['commit']['message'])) {
        github_comment_commit($repo_name, $commit['sha'], $message_sob);
        echo 'Comment (SOB) on ' . $commit['sha'] . ":\n";
        echo $commit['commit']['message'];
        echo "\n";
    }
    /* Check for tab in diff */
    foreach ($commit['files'] as $file) {
        if (strpos($file['patch'], "\t") !== false) {
            github_comment_commit($repo_name, $commit['sha'], $message_tab);
            echo 'Comment (TAB) on ' . $commit['sha'] . ":\n";
            echo $commit['commit']['message'];
            echo "\n";
            break;
        }
    }
}
