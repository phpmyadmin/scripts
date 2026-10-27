--TEST--
json_response: accepts the stdClass the push hook passes, and the array shape
--FILE--
<?php

declare(strict_types=1);

define('PMAHOOKS', true);
require_once __DIR__ . '/../lib/http.php';

/*
 * push.php answers with the stdClass built by github_webhook_push(), which a
 * json_response(array|null $data) rejected with a TypeError. The CLI cannot
 * observe headers, so this only covers the body and the accepted types.
 */

$payload = new stdClass();
$payload->repoName = 'phpmyadmin/phpmyadmin';
$payload->headCommitShortHash = 'abc123';

$cases = [
    'stdClass payload'   => [$payload, 'success', 'Message has been sent'],
    'array payload'      => [['pong' => true], 'success', null],
    'null with message'  => [null, 'success', 'Ignored the push on the member branch: refs/heads/x'],
    'error status'       => [null, 'error', 'Request failed with a code 500'],
];

$results = [];
foreach ($cases as $name => [$data, $status, $message]) {
    ob_start();
    json_response($data, $status, $message);
    $output = ob_get_clean();

    $decoded = json_decode((string) $output, true);
    $ok = is_array($decoded) && ($decoded['status'] ?? null) === $status;

    if ($message === null) {
        $ok = $ok && ! array_key_exists('message', $decoded);
    } else {
        $ok = $ok && ($decoded['message'] ?? null) === $message;
    }

    if ($data === null) {
        $ok = $ok && ! array_key_exists('data', $decoded);
    } else {
        $ok = $ok && array_key_exists('data', $decoded);
    }

    if ($data instanceof stdClass) {
        $ok = $ok && ($decoded['data'] ?? null) === ['repoName' => $payload->repoName, 'headCommitShortHash' => $payload->headCommitShortHash];
    }

    $results[] = $name . ': ' . ($ok ? 'PASS' : 'FAIL');
}

/* Printed once the responses are done, so header() is not called after output */
echo implode("\n", $results), "\n";

?>
--EXPECT--
stdClass payload: PASS
array payload: PASS
null with message: PASS
error status: PASS
