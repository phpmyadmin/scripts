Web hooks for phpMyAdmin
========================

This directory contains various scripts used for development.

Configuration
+++++++++++++

The scripts accessing GitHub need configuration to be able to authorize against
it. Please create file ``config.php`` in this directory with GitHub
credentials. For example:: 

    <?php
    define('GITHUB_USERNAME', 'phpmyadmin-bot');
    define('GITHUB_PASSWORD', 'password');

Scripts
+++++++

Signed-Off-By checker
---------------------

The ``sob.php`` is GitHub hook to check that all commits in a pull request have
valid Signed-Off-By line.
