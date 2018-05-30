#!/usr/bin/php
<?php
$_CLI = getopt("", array("root:", "build-dir:", "cache-dir:", "output-config:", "title::", "title-of-composer"));

$SOURCE = 'src';

if (is_dir($_CLI['root'].'/src') == false) {
    $SOURCE = 'libraries';
}

if (isset($_CLI['title-of-composer'])) {
    $contents = file_get_contents($_CLI['root'].'/composer.json');
    $_CLI['title'] = json_decode($contents)->description;
}

$output = '
<?php
use Sami\Sami;
use Symfony\Component\Finder\Finder;

$iterator = Finder::create()
    ->files()
    ->name("*.php")
    ->in("'.$_CLI['root'].'/'.$SOURCE.'")
;

return new Sami($iterator, array(
    "title"                => "'.$_CLI["title"].'",
    "build_dir"            => "'.$_CLI["build-dir"].'",
    "cache_dir"            => "'.$_CLI["cache-dir"].'"
));

';

file_put_contents($_CLI['output-config'], $output);
