--TEST--
is_member_branch: pushes on `<pusher>/` branches are ignored
--FILE--
<?php

declare(strict_types=1);

define('PMAHOOKS', true);
require_once __DIR__ . '/../lib/push_handler.php';

$scenarios = [
    'member branch'                  => ['refs/heads/williamdes/some-fix', 'williamdes', true],
    'nested member branch'           => ['refs/heads/williamdes/fix/tests', 'williamdes', true],
    'member branch, other casing'    => ['refs/heads/WilliamDes/some-fix', 'williamdes', true],
    'master'                         => ['refs/heads/master',              'williamdes', false],
    'branch of another member'       => ['refs/heads/ibennetch/some-fix',  'williamdes', false],
    'username as a plain branch'     => ['refs/heads/williamdes',          'williamdes', false],
    'username only a prefix'         => ['refs/heads/williamdes2/fix',     'williamdes', false],
    'username not at the beginning'  => ['refs/heads/fix/williamdes/foo',  'williamdes', false],
    'tag named like a member branch' => ['refs/tags/williamdes/1.0',       'williamdes', false],
    'unknown pusher'                 => ['refs/heads/williamdes/some-fix', null,         false],
];

foreach ($scenarios as $name => [$ref, $pusher, $expected]) {
    $result = is_member_branch($ref, $pusher);
    echo $name, ': ', ($result === $expected ? 'PASS' : 'FAIL'), "\n";
}
?>
--EXPECT--
member branch: PASS
nested member branch: PASS
member branch, other casing: PASS
master: PASS
branch of another member: PASS
username as a plain branch: PASS
username only a prefix: PASS
username not at the beginning: PASS
tag named like a member branch: PASS
unknown pusher: PASS
