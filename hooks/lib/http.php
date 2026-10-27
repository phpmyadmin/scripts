<?php

/**
 * HTTP responses of the hooks.
 *
 * Kept apart from github.php, which requires the configuration file, so that
 * the hook handlers and the tests can use them without it.
 */

if (! defined('PMAHOOKS')) {
    fail('Invalid invocation!');
}

/**
 * Issues JSON response
 *
 * @param array<array-key, mixed>|stdClass|null $data JSON data to print
 */
function json_response(array|stdClass|null $data, string $status = 'success', string|null $message = null): void
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

/**
 * Answers 204 No Content.
 *
 * The message has no content, so RFC 9110 section 8.3 forbids a Content-Type
 * header, which describes the content of the message. There is nothing for a
 * browser to sniff either, so no X-Content-Type-Options is sent.
 *
 * PHP adds its default Content-Type while it builds the response, after the
 * script is done, so header_remove() alone does not stop it: the header is
 * still on the wire. Emptying default_mimetype is what turns that automatic
 * header off, which is why both are needed. Checked with php-cgi, the same
 * header path php-fpm takes: with only header_remove() the response carries
 * "Content-type: text/html; charset=UTF-8", with both it carries none.
 */
function no_content_response(): never
{
    ini_set('default_mimetype', '');
    http_response_code(204);
    header_remove('Content-Type');

    die;
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
