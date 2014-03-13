<?php
/**
 * GitHub operations layer.
 */

if (!defined('PMAHOOKS')) {
    die();
}

require_once('../../.pmahook.php');

/**
 * Posts a comment on GitHub pull request
 */
function github_comment($pullid, $comment)
{
    $ch = curl_init();

    //set the url, number of POST vars, POST data
    curl_setopt($ch, CURLOPT_USERPWD, GITHUB_USERNAME . ':' . GITHUB_PASSWORD);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/phpmyadmin/phpmyadmin/issues/' . $pullid . '/comments');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'phpMyAdmin-bot');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('body' => $comment)));

    //execute post
    $result = curl_exec($ch);

    //close connection
    curl_close($ch);

    return json_decode($result);
}
