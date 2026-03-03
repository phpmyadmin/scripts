<?php
/**
 * GitHub operations layer.
 */

if (! defined('PMAHOOKS')) {
    fail('Invalid invocation!');
}

require_once __DIR__ . '/../config.php';

$curlBaseOpts = [
    CURLOPT_USERAGENT => 'phpMyAdmin-bot',
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: token ' . GITHUB_TOKEN,
        'Accept: application/vnd.github+json',
    ],
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
function github_webhook_push(stdClass $inputData): stdClass
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
    // Commits can be empty and head_commit filled in case of a tag
    $firstCommit = $inputData->commits[0] ?? $inputData->head_commit;
    $data->headCommitTitle     = explode("\n", $firstCommit->message)[0];
    $data->headCommitShortHash = substr($firstCommit->id, 0, 6);
    $data->authorName          = $firstCommit->author->name;
    $data->authorEmail         = $firstCommit->author->email;

    return $data;
}

/**
 * Verifies signature from GitHub
 */
function github_verify_post(): void
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
function github_make_release(string $repo, string $tag, string $version, string $description): array
{
    global $curlBaseOpts;

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
    curl_setopt_array($ch, $curlBaseOpts);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo . '/releases');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['tag_name' => $tag, 'name' => $version, 'body' => $description]));

    //execute post
    $response = curl_exec($ch);

    continueOrFailOnHttpCode(curl_getinfo($ch, CURLINFO_HTTP_CODE));

    //close connection
    curl_close($ch);

    $result['response'] = json_decode(json: $response, flags: JSON_THROW_ON_ERROR);

    return $result;
}

/**
 * Posts a comment on GitHub pull request
 */
function github_comment_pull(string $repo, int $pullid, string $comment): array
{
    global $curlBaseOpts;

    $ch = curl_init();

    //set the url, number of POST vars, POST data
    curl_setopt_array($ch, $curlBaseOpts);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo . '/issues/' . $pullid . '/comments');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['body' => $comment]));

    //execute post
    $result = curl_exec($ch);

    continueOrFailOnHttpCode(curl_getinfo($ch, CURLINFO_HTTP_CODE));

    //close connection
    curl_close($ch);

    return json_decode(json: $result, associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Posts a comment on GitHub commit request
 */
function github_comment_commit(string $repo, string $sha, string $comment): array
{
    global $curlBaseOpts;

    $ch = curl_init();

    //set the url, number of POST vars, POST data
    curl_setopt_array($ch, $curlBaseOpts);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo . '/commits/' . $sha . '/comments');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['body' => $comment]));

    //execute post
    $result = curl_exec($ch);

    continueOrFailOnHttpCode(curl_getinfo($ch, CURLINFO_HTTP_CODE));

    //close connection
    curl_close($ch);

    return json_decode(json: $result, associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Returns list of pull request commits.
 */
function github_pull_commits(string $repo, int $pullid): array
{
    global $curlBaseOpts;

    $ch = curl_init();
    curl_setopt_array($ch, $curlBaseOpts);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo . '/pulls/' . $pullid . '/commits');

    //execute post
    $result = curl_exec($ch);

    continueOrFailOnHttpCode(curl_getinfo($ch, CURLINFO_HTTP_CODE));

    //close connection
    curl_close($ch);

    return json_decode(json: $result, associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Returns diff of pull request commit detail.
 */
function github_commit_detail(string $repo, string $commit): array
{
    global $curlBaseOpts;

    $ch = curl_init();
    curl_setopt_array($ch, $curlBaseOpts);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo . '/commits/' . $commit);

    //execute post
    $result = curl_exec($ch);

    continueOrFailOnHttpCode(curl_getinfo($ch, CURLINFO_HTTP_CODE));

    //close connection
    curl_close($ch);

    return json_decode(json: $result, associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Returns github issue/pull-request comments
 */
function github_issue_comments(string $repo, int $issueNumber): array
{
    global $curlBaseOpts;

    $ch = curl_init();
    curl_setopt_array($ch, $curlBaseOpts);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo . '/issues/' . $issueNumber . '/comments');

    //execute post
    $result = curl_exec($ch);

    continueOrFailOnHttpCode(curl_getinfo($ch, CURLINFO_HTTP_CODE));

    //close connection
    curl_close($ch);

    return json_decode(json: $result, associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Returns github commit comments
 */
function github_commit_comments(string $repo, string $sha): array
{
    global $curlBaseOpts;

    $ch = curl_init();
    curl_setopt_array($ch, $curlBaseOpts);
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/' . $repo . '/commits/' . $sha . '/comments');

    //execute post
    $result = curl_exec($ch);

    continueOrFailOnHttpCode(curl_getinfo($ch, CURLINFO_HTTP_CODE));

    //close connection
    curl_close($ch);

    return json_decode(json: $result, associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Trigger website rendering.
 */
function trigger_website_render(): void
{
    $file = fopen(WEBSITE_HOOK, 'w');
    fclose($file);
}

/**
 * Trigger website rendering.
 */
function trigger_docs_render(): void
{
    $file = fopen(DOCS_HOOK, 'w');
    fclose($file);
}

/**
 * Issues JSON response
 *
 * @param array|null $data JSON data to print
 */
function json_response(array|null $data, string $status = 'success', string|null $message = null): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');

    $response = ['status' => $status];
    if ($data !== null) {
        $response['data'] = $data;
    }
    if ($message !== null) {
        $response['message'] = $message;
    }

    echo json_encode($response, JSON_PRETTY_PRINT);
}

function continueOrFailOnHttpCode(int $httpCode): void
{
    if ($httpCode > 204) {
        fail('Request failed with a code ' . $httpCode);
    }
}

/**
 * Terminates request with error
 */
function fail(string $message, int $code = 500): never
{
    http_response_code($code);
    json_response(null, 'error', $message);

    die;
}
