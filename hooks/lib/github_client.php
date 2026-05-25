<?php

if (! defined('PMAHOOKS')) {
    fail('Invalid invocation!');
}

interface GithubClient
{
    /** @return array<int, array{body: string}> */
    public function issueComments(string $repo, int $issueNumber): array;

    /** @return array<int, array{sha: string, parents: array, commit: array{message: string}}> */
    public function pullCommits(string $repo, int $pullId): array;

    /** @return array<int, array{body: string}> */
    public function commitComments(string $repo, string $sha): array;

    /** @return array{files: array<int, array{filename: string, patch: string}>} */
    public function commitDetail(string $repo, string $sha): array;

    public function commentPull(string $repo, int $pullId, string $body): array;

    public function commentCommit(string $repo, string $sha, string $body): array;
}

final class GithubApiClient implements GithubClient
{
    public function issueComments(string $repo, int $issueNumber): array
    {
        return github_issue_comments($repo, $issueNumber);
    }

    public function pullCommits(string $repo, int $pullId): array
    {
        return github_pull_commits($repo, $pullId);
    }

    public function commitComments(string $repo, string $sha): array
    {
        return github_commit_comments($repo, $sha);
    }

    public function commitDetail(string $repo, string $sha): array
    {
        return github_commit_detail($repo, $sha);
    }

    public function commentPull(string $repo, int $pullId, string $body): array
    {
        return github_comment_pull($repo, $pullId, $body);
    }

    public function commentCommit(string $repo, string $sha, string $body): array
    {
        return github_comment_commit($repo, $sha, $body);
    }
}
