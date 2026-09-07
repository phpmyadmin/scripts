<?php

if (! defined('PMAHOOKS')) {
    fail('Invalid invocation!');
}

/**
 * Tells whether a push targets a personal branch of the member who pushed it.
 *
 * Members push their work in progress to `<username>/<topic>` branches on the
 * main repository, those branches are ignored and get no notification email.
 *
 * @param string          $ref            The pushed reference, ie `refs/heads/williamdes/some-fix`
 * @param string|null     $pusherUsername The GitHub login of the pusher
 */
function is_member_branch(string $ref, string|null $pusherUsername): bool
{
    if ($pusherUsername === null || $pusherUsername === '') {
        return false;
    }

    /* Tags and other references are never personal branches */
    if (strpos($ref, 'refs/heads/') !== 0) {
        return false;
    }

    $branch = substr($ref, strlen('refs/heads/'));

    return stripos($branch, $pusherUsername . '/') === 0;
}
