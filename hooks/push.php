<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * GitHub webhook to send emails using push event
 */

error_reporting(E_ALL);

define('PMAHOOKS', True);

require_once(__DIR__.'/lib/github.php');

if ( !is_file(__DIR__.'/vendor/autoload.php')) {
    fail("Run composer install");
    exit;
}

//Load Composer's autoloader
require_once(__DIR__.'/vendor/autoload.php');

github_verify_post();


$inputData = json_decode(file_get_contents('php://input'));

if (json_last_error() !== 0) {
    fail("JSON body was not well formed.");
    exit;
}


$data = gihub_webhook_push($inputData);


$mail = new PHPMailer(true);
try {

    $mail->SMTPDebug  = 0;
    $mail->CharSet = 'UTF-8';

    if (defined('SMTP_HOST') && !empty(SMTP_HOST)) {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_MODE;
        $mail->Port       = SMTP_PORT;
    }

    if (defined('SMTP_SEND_FROM_EMAIL')) {
        $mail->setFrom(SMTP_SEND_FROM_EMAIL, $data->authorName);
    } else {
        $mail->setFrom($data->authorEmail, $data->authorName);
    }

    $mail->addAddress(SMTP_SEND_TO);

    if (defined('SMTP_SEND_BACK_TO')) {
        $mail->addReplyTo(SMTP_SEND_BACK_TO);
    }

    if (defined('SMTP_HEADERS') && is_array(SMTP_HEADERS)) {
        foreach (SMTP_HEADERS as $name => $value) {
            $mail->addCustomHeader($name, $value);
        }
    }

    $mail->Subject  = '[phpMyAdmin Git] [' . $data->repoName. '] ';
    $mail->Subject .= $data->headCommitShortHash . ': ' . $data->headCommitTitle;
    $mail->Body     = $data->emailBody;

    $mail->send();
    json_response($data, 'success', 'Message has been sent');
} catch (Exception $e) {
    fail('Message could not be sent. Mailer Error: ' . $mail->ErrorInfo);
}
