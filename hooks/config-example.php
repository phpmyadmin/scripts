<?php

define('GITHUB_HOOK_SECRET', 'xxxxxxxxxxxxxxREPLACEMExxxxxxxxxxxxxxxxxx');
define('GITHUB_USERNAME', 'phpmyadmin-bot');
define('GITHUB_TOKEN', 'xxxxxxxxxxxxxxREPLACEMExxxxxxxxxxxxxxxxxx');// https://github.com/settings/tokens/new scopes: repo, workflow
define('SMTP_SEND_TO', 'maillist@example.org');
define('SMTP_SEND_FROM_EMAIL', 'mail@example.org');// Optional
define('SMTP_SEND_BACK_TO', 'maillist@example.org');// Optional
define('SMTP_HEADERS', [ 'Approved' => 'Secret' ]);// Optional
define('SMTP_HOST', 'smtp.example.org');// Optional, if empty all SMTP_* below will be skipped
define('SMTP_PORT', 587);
define('SMTP_MODE', 'tls');// ssl or tls
define('SMTP_USERNAME', 'mail@example.org');
define('SMTP_PASSWORD', 'P@ssw0rd12345');

define('WEBSITE_HOOK', 'https://example.org/replaceme-by-an-url');
define('DOCS_HOOK', 'https://example.org/replaceme-by-an-url');
