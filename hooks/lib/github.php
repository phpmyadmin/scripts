<?php
/**
 * GitHub operations layer.
 */

if (!defined('PMAHOOKS')) {
    die();
}

require_once('../../.pmahook.php');

$curl_base_opts = array(
    CURLOPT_USERPWD => GITHUB_USERNAME . ':' . GITHUB_PASSWORD,
    CURLOPT_USERAGENT => 'phpMyAdmin-bot',
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
);

/**
 * Posts a comment on GitHub pull request
 */
function github_comment_pull($pullid, $comment)
{
    $ch = curl_init();

    //set the url, number of POST vars, POST data
    curl_setopt_array($ch, $GLOBALS['curl_base_opts']);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/phpmyadmin/phpmyadmin/issues/' . $pullid . '/comments');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('body' => $comment)));

    //execute post
    $result = curl_exec($ch);

    //close connection
    curl_close($ch);

    return json_decode($result, true);
}

/**
 * Posts a comment on GitHub commit request
 */
function github_comment_commit($repo, $sha, $comment)
{
    $ch = curl_init();

    //set the url, number of POST vars, POST data
    curl_setopt_array($ch, $GLOBALS['curl_base_opts']);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo, '/commits/' . $sha . '/comments');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('body' => $comment)));

    //execute post
    $result = curl_exec($ch);

    //close connection
    curl_close($ch);

    return json_decode($result, true);
}

/**
 * Returns list of pull request commits.
 */
function github_pull_commits($pullid)
{
    $ch = curl_init();
    curl_setopt_array($ch, $GLOBALS['curl_base_opts']);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/phpmyadmin/phpmyadmin/pulls/' . $pullid . '/commits');

    //execute post
    $result = curl_exec($ch);

    //close connection
    curl_close($ch);

    return json_decode($result, true);
}
