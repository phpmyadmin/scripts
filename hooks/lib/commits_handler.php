<?php

if (! defined('PMAHOOKS')) {
    fail('Invalid invocation!');
}

/**
 * Posts the "too many commits" warning on a pull request, unless one is
 * already present. Returns true if a comment was posted.
 */
function maybe_post_commits_warning(array $data, string $message, GithubClient $client): bool
{
    $comments = $client->issueComments($data['repository']['full_name'], $data['pull_request']['number']);
    foreach ($comments as $comment) {
        if (strpos($comment['body'], '<!-- PMABOT:COMMITS -->') !== false) {
            return false;
        }
    }

    $client->commentPull($data['repository']['full_name'], $data['pull_request']['number'], $message);

    return true;
}
