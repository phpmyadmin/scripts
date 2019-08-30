<?php
/**
 * GitHub webhook to check Signed-Off-By in pull request commits.
 */

error_reporting(E_ALL);

define('PMAHOOKS', true);

require_once('./lib/github.php');

github_verify_post();

$contributing_url = 'https://github.com/phpmyadmin/phpmyadmin/blob/master/CONTRIBUTING.md';
$guidelines_url = 'https://github.com/phpmyadmin/phpmyadmin/wiki/Developer_guidelines';
$dco_link = 'https://github.com/phpmyadmin/phpmyadmin/blob/master/DCO';

$message_sob = "<!-- PMABOT:SOB -->\n"
    . "This commit is missing Signed-Off-By line to indicate "
    . "that you agree with phpMyAdmin Developer's Certificate of Origin. "
    . "Please check [contributing documentation]("
    . $contributing_url
    . ") for more information.";

$message_sob_invalid = "<!-- PMABOT:SOB --><!-- PMABOT:SOB:INVALID -->\n"
    . "This commit has an invalid Signed-Off-By line, please use "
    . "your full name for legal compliance reasons.\n"
    . "Please check [Developer's Certificate of Origin]($dco_link)\n"
    . "Here is a valid example: `Signed-off-by: Jane Smith <jane.smith@example.org>`";

$message_tab = "<!-- PMABOT:TAB -->\n"
    . "This commit is using tab character for indentation instead "
    . "of spaces, what is mandated by phpMyAdmin. Please check our "
    . "[Developer guidelines]("
    . $guidelines_url
    . "#Indentation) for more information."
    . "\n\nOffending files: ";

$message_space = "<!-- PMABOT:SPACE -->\n"
    . "This commit contains trailing whitespace, "
    . "what is prohibited in phpMyAdmin. Please check our "
    . "[Developer guidelines]("
    . $guidelines_url
    . "#Indentation) for more information."
    . "\n\nOffending files: ";

$message_eol = "<!-- PMABOT:EOL -->\n"
    . "This commit is using DOS end of line characters instead "
    . "of UNIX ones, what is mandated by phpMyAdmin. Please check our "
    . "[Developer guidelines]("
    . $guidelines_url
    . "#Indentation) for more information."
    . "\n\nOffending files: ";

$message_commits = "<!-- PMABOT:COMMITS -->\n"
    . "This pull requests contains too many commits, in most cases "
    . "it is caused by wrong merge target. In case you have forked "
    . "`master` branch you should also ask merging to `master` branch. "
    . "See [GitHub documentation]"
    . "(https://help.github.com/articles/creating-a-pull-request/) "
    . "for more details.";

/* Parse JSON */
$data = json_decode($_POST['payload'], true);

/* Check request data */
if (isset($data['zen']) && isset($data['hook']) && isset($data['hook_id'])) {
    json_response(array('pong' => true));
    die();
}

if (! isset($data['pull_request']) || ! isset($data['action'])) {
    fail('No pull request data!');
}

/* We don't care about closed requests */
if ($data['action'] == 'closed') {
    die();
}

/* Check number of commits */
if ($data['pull_request']['commits'] > 50) {
    github_comment_pull($data['repository']['full_name'], $data['pull_request']['number'], $message_commits);
    die();
}

/* Parse repository name */
$repo_name = $data['pull_request']['head']['repo']['full_name'];

/* List commits in the pull request */
$commits = github_pull_commits($data['repository']['full_name'], $data['pull_request']['number']);

$comments = array();

/* Process commits in the pull request */
foreach ($commits as $commit) {
    /* Ignore merge commits */
    if (count($commit['parents']) > 1) {
        continue;
    }

    /* Fetch current comments */
    $current_comments = github_commit_comments($repo_name, $commit['sha']);
    $comments_text = '';
    foreach ($current_comments as $comment) {
        $comments_text .= $comment['body'];
    }

    /* Check for missing SOB */
    if ( ! preg_match("@\nSigned-off-by:@i", $commit['commit']['message'])) {
        if (strpos($comments_text, '<!-- PMABOT:SOB -->') === false) {
            github_comment_commit($repo_name, $commit['sha'], $message_sob);
            $comments[] = array(
                'type' => 'SOB',
                'commit' => $commit['sha'],
                'message' => $commit['commit']['message'],
            );
        }
    } else {
        /* Check for invalid SOB */
        if ( ! preg_match("@\nSigned-off-by: +(?:[\p{L}\-\.]+\s){2,}<.*>@iu", $commit['commit']['message'])) {
            if (strpos($comments_text, '<!-- PMABOT:SOB:INVALID -->') === false) {
                github_comment_commit($repo_name, $commit['sha'], $message_sob_invalid);
                $comments[] = array(
                    'type' => 'SOB',
                    'commit' => $commit['sha'],
                    'message' => $commit['commit']['message'],
                );
            }
        }
    }

    /* Check for tab or trailing whitespace in diff */
    $detail = github_commit_detail($data['repository']['full_name'], $commit['sha']);
    $files_tab = array();
    $files_space = array();
    $files_eol = array();
    foreach ($detail['files'] as $file) {
        if (preg_match("@\n\+[^\n]*\t@", $file['patch'])) {
            if (! preg_match('^libraries/advisory_rules.*\.txt$', $file['filename'])) {
                $files_tab[] = $file['filename'];
            }
        }
        if (preg_match("@\n\+[^\n]* \n@", $file['patch'])) {
            $files_space[] = $file['filename'];
        }
        if (preg_match("@\n\+[^\n]*\r@", $file['patch'])) {
            $files_eol[] = $file['filename'];
        }
    }
    if (count($files_tab) && strpos($comments_text, 'PMABOT:TAB') === false) {
        github_comment_commit($repo_name, $commit['sha'], $message_tab . implode(', ', $files_tab));
        $comments[] = array(
            'type' => 'TAB',
            'commit' => $commit['sha'],
            'message' => $commit['commit']['message'],
        );
    }
    if (count($files_space) && strpos($comments_text, 'PMABOT:SPACE') === false) {
        github_comment_commit($repo_name, $commit['sha'], $message_space . implode(', ', $files_space));
        $comments[] = array(
            'type' => 'SPACE',
            'commit' => $commit['sha'],
            'message' => $commit['commit']['message'],
        );
    }
    if (count($files_eol) && strpos($comments_text, 'PMABOT:EOL') === false) {
        github_comment_commit($repo_name, $commit['sha'], $message_eol . implode(', ', $files_eol));
        $comments[] = array(
            'type' => 'EOL',
            'commit' => $commit['sha'],
            'message' => $commit['commit']['message'],
        );
    }
}

json_response(array('comments' => $comments));
