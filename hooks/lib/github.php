<?php
/**
 * GitHub operations layer.
 */

if (! defined('PMAHOOKS')) {
    fail('Invalid invocation!');
}

require_once './config.php';

$curl_base_opts = [
    CURLOPT_USERPWD => GITHUB_USERNAME . ':' . GITHUB_PASSWORD,
    CURLOPT_USERAGENT => 'phpMyAdmin-bot',
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
];

/**
 * Builds the body of the email to send
 *
 * @see https://developer.github.com/v3/activity/events/types/#pushevent
 *
 * @param stdClass $inputData The JSON payload
 *
 * @return stdClass {
 *       "repoName": "org/repo",
 *       "compare": "URL TO COMPARE PAGE",
 *       "emailBody": "THE RAW BODY",
 *       "headCommitTitle": "commit title",
 *       "headCommitShortHash": "abcde",
 *       "authorName": "William Desportes",
 *       "authorEmail": "williamdes@wdes.fr"
 * }
 */
function gihub_webhook_push(stdClass $inputData): stdClass
{
    $msg  = '';
    $msg .= 'Branch: ' . $inputData->ref . PHP_EOL;
    $msg .= 'Home: ' . $inputData->repository->html_url . PHP_EOL;

    foreach ($inputData->commits as $commit) {
        $msg .= 'Commit: ' . $commit->id . PHP_EOL;
        $msg .= $commit->url . PHP_EOL;
        $msg .= 'Author: ' . $commit->author->name . ' <' . $commit->author->email . '>' . PHP_EOL;

        $date = new DateTime($commit->timestamp);
        $msg .= 'Date: ' . $date->format('Y-m-d (D, m F Y) P') . PHP_EOL . PHP_EOL;

        $msg .= 'Changed paths: ' . PHP_EOL;
        foreach ($commit->added as $file) {
            $msg .= 'A ' . $file . PHP_EOL;
        }
        foreach ($commit->modified as $file) {
            $msg .= 'M ' . $file . PHP_EOL;
        }
        foreach ($commit->removed as $file) {
            $msg .= 'D ' . $file . PHP_EOL;
        }
        $msg .= PHP_EOL . 'Log Message:' . PHP_EOL . '-----------' . PHP_EOL;
        $msg .= $commit->message . PHP_EOL . PHP_EOL;
    }

    $data = new stdClass();
    $data->repoName            = $inputData->repository->full_name;
    $data->compare             = $inputData->compare;
    $data->emailBody           = $msg;
    $data->headCommitTitle     = explode("\n", $inputData->commits[0]->message)[0];
    $data->headCommitShortHash = substr($inputData->commits[0]->id, 0, 6);
    $data->authorName          = $inputData->commits[0]->author->name;
    $data->authorEmail         = $inputData->commits[0]->author->email;

    return $data;
}

/**
 * Verifies signature from GitHub
 */
function github_verify_post()
{
    if (empty(GITHUB_HOOK_SECRET)) {
        fail('Missing hook secret configuration!');
    }
    if (! isset($_SERVER['HTTP_X_HUB_SIGNATURE'])) {
        fail("HTTP header 'X-Hub-Signature' is missing.");
    } elseif (! extension_loaded('hash')) {
        fail("Missing 'hash' extension to check the secret code validity.");
    }

    [$algo, $hash] = explode('=', $_SERVER['HTTP_X_HUB_SIGNATURE'], 2) + ['', ''];
    if (! in_array($algo, ['sha1', 'sha256', 'sha512'], true)) {
        fail("Hash algorithm '$algo' is not allowed.");
    }
    if (! in_array($algo, hash_algos(), true)) {
        fail("Hash algorithm '$algo' is not supported.");
    }
    $rawPost = file_get_contents('php://input');
    $newhash = hash_hmac($algo, $rawPost, GITHUB_HOOK_SECRET);
    if (hash_equals($hash, $newhash)) {
        return;
    }

    fail('Hook secret does not match.');
}

/**
 * Creates a GitHub release
 */
function github_make_release($repo, $tag, $version, $description)
{
    $ch = curl_init();

    $result = [
        'release' => [
            'project' => $repo,
            'tag' => $tag,
            'version' => $version,
            'description' => $description,
        ],
    ];

    //set the url, number of POST vars, POST data
    curl_setopt_array($ch, $GLOBALS['curl_base_opts']);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo . '/releases');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['tag_name' => $tag, 'name' => $version, 'body' => $description]));

    //execute post
    $response = curl_exec($ch);

    //close connection
    curl_close($ch);

    $result['response'] = json_decode($response);

    return $result;
}

/**
 * Posts a comment on GitHub pull request
 */
function github_comment_pull($repo, $pullid, $comment)
{
    $ch = curl_init();

    //set the url, number of POST vars, POST data
    curl_setopt_array($ch, $GLOBALS['curl_base_opts']);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo . '/issues/' . $pullid . '/comments');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['body' => $comment]));

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
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo . '/commits/' . $sha . '/comments');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['body' => $comment]));

    //execute post
    $result = curl_exec($ch);

    //close connection
    curl_close($ch);

    return json_decode($result, true);
}

/**
 * Returns list of pull request commits.
 */
function github_pull_commits($repo, $pullid)
{
    $ch = curl_init();
    curl_setopt_array($ch, $GLOBALS['curl_base_opts']);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo . '/pulls/' . $pullid . '/commits');

    //execute post
    $result = curl_exec($ch);

    //close connection
    curl_close($ch);

    return json_decode($result, true);
}

/**
 * Returns diff of pull request commit detal.
 */
function github_commit_detail($repo, $commit)
{
    $ch = curl_init();
    curl_setopt_array($ch, $GLOBALS['curl_base_opts']);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo . '/commits/' . $commit);

    //execute post
    $result = curl_exec($ch);

    //close connection
    curl_close($ch);

    return json_decode($result, true);
}

/**
 * Returns diff of pull request commit detal.
 */
function github_commit_comments($repo, $sha)
{
    $ch = curl_init();
    curl_setopt_array($ch, $GLOBALS['curl_base_opts']);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo . '/commits/' . $sha . '/comments');

    //execute post
    $result = curl_exec($ch);

    //close connection
    curl_close($ch);

    return json_decode($result, true);
}

/**
 * Returns diff of pull request commits.
 */
function github_pull_diff($repo, $pullid)
{
    $ch = curl_init();
    curl_setopt_array($ch, $GLOBALS['curl_base_opts']);
    curl_setopt($ch, CURLOPT_URL, 'https://github.com/' . $repo . '/pull/' . $pullid . '.patch');

    //execute post
    $result = curl_exec($ch);

    //close connection
    curl_close($ch);

    return $result;
}

/**
 * Trigger website rendering.
 */
function trigger_website_render()
{
    $file = fopen(WEBSITE_HOOK, 'w');
    fclose($file);
}

/**
 * Trigger website rendering.
 */
function trigger_docs_render()
{
    $file = fopen(DOCS_HOOK, 'w');
    fclose($file);
}

/**
 * Issues JSON response
 *
 * @param mixed $data JSON data to print
 */
function json_response($data, $status = 'success', $message = null)
{
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');

    $response = ['status' => $status];
    if (isset($data)) {
        $response['data'] = $data;
    }
    if (isset($message)) {
        $response['message'] = $message;
    }

    echo json_encode($response, JSON_PRETTY_PRINT);
}

/**
 * Terminates request with error
 */
function fail($message, $code = 500)
{
    http_response_code($code);
    json_response(null, 'error', $message);

    die;
}
