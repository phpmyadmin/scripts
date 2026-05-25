--TEST--
maybe_post_commits_warning: the warning is posted at most once per PR
--FILE--
<?php

declare(strict_types=1);

define('PMAHOOKS', true);
require_once __DIR__ . '/../lib/github_client.php';
require_once __DIR__ . '/../lib/commits_handler.php';

/*
 * Implements GithubClient directly instead of extending GithubApiClient,
 * so that a forgotten method is a parse-time error rather than a live
 * call to the real GitHub API. Unused methods throw to surface any
 * unexpected call.
 */
final class FakeGithubClient implements GithubClient
{
    /** @var array<int, array{body: string}> */
    public array $existing = [];

    /** @var array<int, string> */
    public array $postedPulls = [];

    public function issueComments(string $repo, int $issueNumber): array
    {
        return $this->existing;
    }

    public function commentPull(string $repo, int $pullId, string $body): array
    {
        $this->postedPulls[] = $body;

        return [];
    }

    public function pullCommits(string $repo, int $pullId): array
    {
        throw new RuntimeException('unexpected pullCommits()');
    }

    public function commitComments(string $repo, string $sha): array
    {
        throw new RuntimeException('unexpected commitComments()');
    }

    public function commitDetail(string $repo, string $sha): array
    {
        throw new RuntimeException('unexpected commitDetail()');
    }

    public function commentCommit(string $repo, string $sha, string $body): array
    {
        throw new RuntimeException('unexpected commentCommit()');
    }
}

$data = [
    'repository'   => ['full_name' => 'phpmyadmin/phpmyadmin'],
    'pull_request' => ['number' => 1],
];
$marker = "<!-- PMABOT:COMMITS -->\nThis pull requests contains too many commits...";

$scenarios = [
    'no comments yet'                        => [[],                                                              true],
    'only the bot warning'                   => [[['body' => $marker]],                                           false],
    'one user comment, bot has not posted'   => [[['body' => 'looks good!']],                                     true],
    'user comment + existing bot warning'    => [[['body' => 'looks good!'], ['body' => $marker]],                false],
    'multiple user comments, no bot warning' => [[['body' => 'first'], ['body' => 'second']],                     true],
];

foreach ($scenarios as $name => [$existing, $expected_post]) {
    $client = new FakeGithubClient();
    $client->existing = $existing;
    maybe_post_commits_warning($data, 'too many commits', $client);
    $posted = count($client->postedPulls) > 0;
    echo $name, ': ', ($posted === $expected_post ? 'PASS' : 'FAIL'), "\n";
}
?>
--EXPECT--
no comments yet: PASS
only the bot warning: PASS
one user comment, bot has not posted: PASS
user comment + existing bot warning: PASS
multiple user comments, no bot warning: PASS
