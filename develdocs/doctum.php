#!/usr/bin/php
<?php
$_CLI = getopt('', ['slug:', 'root:', 'base-url:', 'build-dir:', 'cache-dir:', 'docs-branch:', 'output-config:', 'title::', 'title-of-composer']);

$SOURCE = 'src';

if (is_dir($_CLI['root'] . '/src') === false) {
    $SOURCE = 'libraries';
}

$output = <<<'EOT'
<?php
/**
 * This file has been generated by phpmyadmin/scripts/develdocs/build.sh
 * @see https://github.com/phpmyadmin/scripts/blob/master/develdocs/doctum.php
 * @see https://github.com/phpmyadmin/scripts/blob/master/develdocs/build.sh
 */
use Doctum\Doctum;
use Symfony\Component\Finder\Finder;
use Doctum\RemoteRepository\GitHubRemoteRepository;

$iterator = Finder::create()
    ->files()
    ->name('*.php')
    ->in('%s');

return new Doctum($iterator, [
    'title'                => json_decode(file_get_contents('%s'))->description,
    'build_dir'            => '%s',
    'cache_dir'            => '%s',
    'version'              => '%s',
    'remote_repository'    => new GitHubRemoteRepository('%s', '%s'),
    'base_url'             => '%s',
]);
EOT;

$root = rtrim($_CLI['root'], '/') . '/';

$output = sprintf(
    $output,
    $root . $SOURCE,
    $root . 'composer.json',
    $_CLI['build-dir'],
    $_CLI['cache-dir'],
    $_CLI['docs-branch'],
    $_CLI['slug'],
    $root,
    $_CLI['base-url']
);

file_put_contents($_CLI['output-config'], $output);
