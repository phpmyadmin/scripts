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

Commits checker
---------------

The ``commits.php`` is GitHub hook to check that all commits in a pull request
are in a good shape:

* all have valid Signed-Off-By line
* the patch does not introduce some of coding style violations
    * tab indentation
    * DOS end of lines
    * trailing whitespace
