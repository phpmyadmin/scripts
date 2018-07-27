Web hooks for phpMyAdmin
========================

This directory contains various scripts used for development.

Configuration
+++++++++++++

The scripts accessing GitHub need configuration to be able to authorize against
it.

Please create file ``config.php`` in this directory with GitHub
credentials.

All variables that begin with ``SMTP_`` are used by the ``push.php`` script, they are not required if you no not use the ``push.php`` script.

For example:

.. code-block:: php

    <?php
    define('GITHUB_USERNAME', 'phpmyadmin-bot');
    define('GITHUB_PASSWORD', 'password');
    define('SMTP_SEND_TO', 'maillist@example.org');
    define('SMTP_SEND_FROM_EMAIL', 'mail@example.org');// Optional
    define('SMTP_SEND_BACK_TO', 'maillist@example.org');// Optional
    define('SMTP_HEADERS', [ 'Approved' => 'Secret' ]);// Optional
    define('SMTP_HOST', 'smtp.example.org');// Optional, if empty all SMTP_* below will be skipped
    define('SMTP_PORT', 587);
    define('SMTP_MODE', 'tls');// ssl or tls
    define('SMTP_USERNAME', 'mail@example.org');
    define('SMTP_PASSWORD', 'P@ssw0rd12345');


Scripts
+++++++

Commits checker
---------------

The ``commits.php`` is GitHub hook to check that all commits in a pull request
are in a good shape:

* all have valid Signed-Off-By line
* the patch does not introduce some of coding style violations
    * tab indentation
    * DOS end of lines
    * trailing whitespace
