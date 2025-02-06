#!/bin/php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

if (PHP_VERSION_ID < 80100) {// 80 1 00
    echo 'You need to use PHP 8.1.0 or above to run this script.' . PHP_EOL;
    echo 'Current detected version: (' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ') (' . PHP_VERSION_ID . ').' . PHP_EOL;
    exit(1);
}

class Reports
{
    private const PROJECTS = [
        'github.com' => [
            'phpmyadmin/phpmyadmin',
            'phpmyadmin/docker',
            'phpmyadmin/website',
            'phpmyadmin/sql-parser',
            'phpmyadmin/motranslator',
            'phpmyadmin/shapefile',
            'phpmyadmin/simple-math',
            'phpmyadmin/localized_docs',
            'phpmyadmin/error-reporting-server',
            'phpmyadmin/twig-i18n-extension',
            'docker-library/docs',
            'docker-library/official-images',
            // Disable private repos
            // 'phpmyadmin/phpmyadmin-security',
            // 'phpmyadmin/private',
        ],
        'gitlab.com' => [],
        'salsa.debian.org' => [
            'phpmyadmin-team/phpmyadmin',
            'phpmyadmin-team/twig-i18n-extension',
            'phpmyadmin-team/mariadb-mysql-kbs',
            'phpmyadmin-team/google-recaptcha',
            'phpmyadmin-team/motranslator',
            'phpmyadmin-team/sql-parser',
            'phpmyadmin-team/shapefile',
            'phpmyadmin-team/tcpdf',
        ],
    ];

    private const IGNORE_WORDS = [
        'Translated using Weblate',
        'Merge branch ',
        'Merge remote-tracking branch ',
        'Update d/changelog',// Debian commits for debian/changelog
    ];

    /**
     * @var array{
     * type: "GitHub"|"GitLab", slug: string, data: array[] }
     * }[]
     */
    private array $commitsStorage = [];

    /**
     * @var array{
     * type: "GitHub"|"GitLab", slug: string, data: array[] }
     * }[]
     */
    private array $issuesStorage = [];

    private bool $quietMode = false;
    private ?DateTimeImmutable $startDate = null;
    private ?DateTimeImmutable $endDate = null;
    private ?string $outputJsonData = null;
    private mixed $outputRender = STDOUT;
    private string $monthMode = 'none';
    private string $outputMode = 'none';
    // Tilde expansion: https://unix.stackexchange.com/a/151852/155610
    private string $configFile = '~/.config/phpmyadmin';

    public function run(): void
    {
        $shortopts  = '';
        $shortopts .= 'qh'; // These options do not accept values

        $longopts  = [
            'output:',      // Required value
            'output-json:', // Required value
            'config:',      // Required value
            'month:',       // Required value
            'optional::',   // Optional value
            'start-date::', // Optional value
            'end-date::',   // Optional value
            'help',         // No value
            'quiet',        // No value
            'last-month',   // No value
            'current-month',// No value
            'next-month',   // No value
            'by-week',      // No value
        ];
        $options = getopt($shortopts, $longopts);

        $this->checkExtensions();

        if ($options === []) {
            $this->printHelp();
        }

        foreach ($options as $optionName => $optionValue) {
            if ($optionName === 'help' || $optionName === 'h') {
                $this->printHelp();
            }

            if ($optionName === 'last-month') {
                $this->monthMode = 'last';
            }

            if ($optionName === 'current-month') {
                $this->monthMode = 'current';
            }

            if ($optionName === 'next-month') {
                $this->monthMode = 'next';
            }

            if ($optionName === 'next-month') {
                $this->monthMode = 'next';
            }

            if ($optionName === 'month') {
                $this->monthMode = 'custom';
                $this->startDate = new DateTimeImmutable('@' . strtotime('first day of ' . $optionValue . ' UTC'));
                $this->endDate = new DateTimeImmutable('@' . strtotime('last day of ' . $optionValue . ' UTC'));
            }

            if ($optionName === 'start-date') {
                $this->monthMode = 'custom';
                $this->startDate = new DateTimeImmutable($optionValue . 'T00:00:00Z');
            }

            if ($optionName === 'end-date') {
                $this->monthMode = 'custom';
                $this->endDate = new DateTimeImmutable($optionValue . 'T23:59:59Z');
            }

            if ($optionName === 'quiet' || $optionName === 'q') {
                $this->quietMode = true;
            }

            if ($optionName === 'config') {
                $this->configFile = $optionValue;
            }

            if ($optionName === 'output') {
                $this->outputRender = $optionValue;
            }

            if ($optionName === 'by-week') {
                $this->outputMode = 'by-week';
            }

            if ($optionName !== 'output-json') {
                continue;
            }

            $this->outputJsonData = $optionValue;
        }

        if ($this->monthMode === 'none') {
            $this->quitError('You need to specify a month mode, using cli: --{current,last,next}-month');
        }

        $this->configFile = $this->expandTilde($this->configFile);
        $this->detectCheckConfig();
        $this->loadDates();

        $this->readConfig();
        $this->printFinalData();
        $this->renderFinalData();
    }

    /**
     * @source https://compwright.com/2013-09-03/tilde-expansion-in-php/
     */
    private function expandTilde(string $path): string
    {
        if (function_exists('posix_getuid') && strpos($path, '~') !== false) {
            $info = posix_getpwuid(posix_getuid());

            return str_replace('~', $info['dir'], $path);
        }

        return $path;
    }

    private function printHelp(): void
    {
        fwrite(STDOUT, 'Usage:' . "\n");
        fwrite(STDOUT, '  Help: ./phpmyadmin-report.php -h' . "\n");
        fwrite(STDOUT, '  Help: ./phpmyadmin-report.php --help' . "\n");
        fwrite(STDOUT, '  Turn off debug: ./phpmyadmin-report.php --quiet' . "\n");
        fwrite(STDOUT, '  Turn off debug: ./phpmyadmin-report.php -q' . "\n");
        fwrite(STDOUT, '  Custom config: ./phpmyadmin-report.php --config /home/user/report-config.conf' . "\n");
        fwrite(STDOUT, '  Custom output: ./phpmyadmin-report.php --output /home/user/report-output.md' . "\n");
        fwrite(STDOUT, '  Store json data: ./phpmyadmin-report.php --output-json /home/user/report-data.json' . "\n");
        fwrite(STDOUT, '  Last month: ./phpmyadmin-report.php --last-month' . "\n");
        fwrite(STDOUT, '  Current month: ./phpmyadmin-report.php --current-month' . "\n");
        fwrite(STDOUT, '  Next month: ./phpmyadmin-report.php --next-month' . "\n");
        fwrite(STDOUT, '  Specific month: ./phpmyadmin-report.php --month October' . "\n");
        fwrite(STDOUT, '  Custom dates: ./phpmyadmin-report.php --start-date 2021-05-03 --end-date 2021-06-27' . "\n");
        exit(0);
    }

    private function checkExt(string $extName): void
    {
        if (extension_loaded($extName)) {
            return;
        }

        $this->quitError($extName . ' could not be found');
    }

    private function quitError(string $message, int $exitCode = 1): void
    {
        fwrite(STDERR, "\033[0;31m[ERROR] " . $message . "\033[0m" . "\n");
        exit($exitCode);
    }

    private function logDebug(string $message): void
    {
        if ($this->quietMode) {
            return;
        }

        fwrite(STDERR, "\033[1;35m[DEBUG] " . $message . "\033[0m" . "\n");
    }

    private function logInfo(string $message): void
    {
        if ($this->quietMode) {
            return;
        }

        fwrite(STDERR, "\033[1;35m[INFO] ${message}\033[0m" . "\n");
    }

    private function checkExtensions(): void
    {
        $this->checkExt('curl');
    }

    private function detectCheckConfig(): void
    {
        if (file_exists($this->configFile)) {
            return;
        }

        $this->quitError('Missing config file at: ' . $this->configFile);
    }

    private function readConfig(): void
    {
        $config = parse_ini_file(
            $this->configFile,
            true,
            INI_SCANNER_NORMAL
        );

        foreach ($config as $configBlockName => $configBlock) {
            $this->processConfigBlock($configBlock, $configBlockName);
        }
    }

    private function getGitLabHost(?string $host): string
    {
        if ($host === null || $host === '') {
            return 'gitlab.com';
        }

        return $host;
    }

    private function processConfigBlock(array $configBlockIn, string $configBlockName): void
    {
        $typeOrName = $configBlockIn['type'] ?? $configBlockName ?? '?';

        if ($typeOrName === 'gitlab') {
            $this->processGitLab($configBlockIn);
        } elseif ($typeOrName === 'github') {
            $this->processGitHub($configBlockIn);
        }
    }

    private function processGitLab(array $configBlockIn): void
    {
        $this->logDebug('Processing GitLab projects...');

        $host = $this->getGitLabHost($configBlockIn['host'] ?? null);

        foreach (self::PROJECTS[$host] ?? [] as $projectSlug) {
            $this->processGitLabProject($configBlockIn, $projectSlug);
        }

        $this->logDebug('Processing GitLab projects done.');
    }

    private function processGitHub(array $configBlockIn): void
    {
        $this->logDebug('Processing GitHub projects...');

        $host = 'github.com';
        foreach (self::PROJECTS[$host] as $projectSlug) {
            $this->processGitHubProject($configBlockIn, $projectSlug);
        }

        $this->logDebug('Processing GitHub projects done.');
    }

    private function callApi(string $url, array $requestHeaders): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'phpMyAdmin/reporting-script');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);

        $data = curl_exec($ch);
        if ($data === false) {
            $this->quitError(
                'Error while fetching: ' . $url . ' (' . curl_error($ch) . ')'
            );
        }

        curl_close($ch);

        return json_decode($data, true);
    }

    private function callGitHubApi(array $configBlockIn, string $path): array
    {
        $token = $configBlockIn['token'];

        $data = $this->callApi(
            'https://api.github.com/' . $path,
            [
                'Authorization: token ' . $token,
                'Accept: application/vnd.github+json',
            ]
        );

        if ($data['message'] ?? false) {
            $this->quitError('GitHub API (' . $path . ') error: ' . $data['message']);
        }

        return $data;
    }

    private function callGitLabApi(array $configBlockIn, string $path): array
    {
        $token = $configBlockIn['token'];
        $host = $configBlockIn['host'];

        return $this->callApi('https://' . $host . '/api/' . $path, ['Authorization: Bearer ' . $token]);
    }

    private function processGitLabProject(array $configBlockIn, string $projectSlug): void
    {
        $projectSlugUrl = urlencode($projectSlug);
        $authorEmail = $configBlockIn['authorEmail'];
        $this->logDebug('Processing GitLab project: ' . $projectSlug);
        $startDate = $this->startDate->format('Y-m-d\TH:i:sp');
        $endDate = $this->endDate->format('Y-m-d\TH:i:sp');
        $commits = $this->callGitLabApi(
            $configBlockIn,
            "v4/projects/${projectSlugUrl}/repository/commits?since=${startDate}&until=${endDate}"
        );

        $commits = array_filter($commits, static function (array $commit) use ($authorEmail): bool {
            return $commit['author_email'] === $authorEmail;
        });
        $this->gitLabCommitsToStorage($commits, $projectSlug);
    }

    private function processGitHubProject(array $configBlockIn, string $projectSlug): void
    {
        $username = $configBlockIn['user'];
        $this->logDebug('Processing GitHub project: ' . $projectSlug);
        $startDate = $this->startDate->format('Y-m-d\TH:i:sp');
        $endDate = $this->endDate->format('Y-m-d\TH:i:sp');
        $commits = $this->callGitHubApi(
            $configBlockIn,
            "repos/${projectSlug}/commits?author=${username}&per_page=100&since=${startDate}&until=${endDate}"
        );

        $this->gitHubCommitsToStorage($commits, $projectSlug);

        $issues = $this->callGitHubApi(
            $configBlockIn,
            "repos/${projectSlug}/issues?assignee=${username}&per_page=100&since=${startDate}&sort=updated&direction=asc&state=closed"
        );

        $this->gitHubIssuesToStorage($issues, $projectSlug);
    }

    private function gitHubIssuesToStorage(array $issues, string $projectSlug): void
    {
        $issues = array_filter($issues, static function (array $issue): bool {
            return isset($issue['pull_request']) === false;
        });

        $issues = array_filter($issues, function (array $issue): bool {
            $cat = new DateTimeImmutable($issue['closed_at']);

            return $cat >= $this->startDate && $cat <= $this->endDate;
        });

        $this->issuesStorage[] = [
            'slug' => $projectSlug,
            'type' => 'GitHub',
            'issues' => array_map(static function (array $issue): array {
                return [
                    'number' => $issue['number'],
                    'title' => $issue['title'],
                    'html_url' => $issue['html_url'],
                    'closed_at' => new DateTimeImmutable($issue['closed_at']),
                ];
            }, $issues),
        ];
    }

    private function gitHubCommitsToStorage(array $commits, string $projectSlug): void
    {
        $this->commitsStorage[] = [
            'slug' => $projectSlug,
            'type' => 'GitHub',
            'commits' => array_map(static function (array $commit): array {
                return [
                    'sha' => $commit['sha'],
                    'message' => explode("\n", $commit['commit']['message'], 2)[0] ?? $commit['commit']['message'],
                    'html_url' => $commit['html_url'],
                    'cdate' => new DateTimeImmutable($commit['commit']['committer']['date']),
                ];
            }, $commits),
        ];
    }

    private function gitLabCommitsToStorage(array $commits, string $projectSlug): void
    {
        $this->commitsStorage[] = [
            'slug' => $projectSlug,
            'type' => 'GitLab',
            'commits' => array_map(static function (array $commit): array {
                return [
                    'sha' => $commit['id'],
                    'message' => $commit['title'],
                    'html_url' => $commit['web_url'],
                    'cdate' => new DateTimeImmutable($commit['committed_date']),
                ];
            }, $commits),
        ];
    }

    private function printFinalData(): void
    {
        if ($this->outputJsonData === null) {
            return;
        }

        $this->logDebug('Commits data count: ' . count($this->commitsStorage));
        $this->logDebug('Issues data count: ' . count($this->issuesStorage));
        file_put_contents(
            $this->outputJsonData,
            json_encode([
                'commits' => $this->commitsStorage,
                'issues' => $this->issuesStorage,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function printData(string $data): void
    {
        fwrite($this->outputRender, $data);
    }

    private function renderFinalData(): void
    {
        $this->printData('# Commit list' . "\n");

        foreach ($this->commitsStorage as $storageEntry) {
            if ($storageEntry['commits'] === []) {
                continue;
            }

            // remove some commits base on commit body
            foreach (self::IGNORE_WORDS as $ignoreWord) {
                $storageEntry['commits'] = array_filter(
                    $storageEntry['commits'],
                    static fn ($commit) => str_contains($commit['message'], $ignoreWord) === false
                );
            }

            if ($storageEntry['commits'] === []) {
                continue;
            }

            if ($this->outputMode === 'by-week') {
                $commitsGroup = array_reduce($storageEntry['commits'], static function (array $accumulator, array $element) {
                    $accumulator[$element['cdate']->format('W')][] = $element;

                    return $accumulator;
                }, []);
                ksort($commitsGroup);// newer weeks first

                $this->printData(sprintf("\n## %s (%s)\n", $storageEntry['slug'], $storageEntry['type']));

                foreach ($commitsGroup as $monthNumber => $commits) {
                    $this->printData(sprintf("\n### Week %s\n\n", $monthNumber));
                    foreach ($commits as $commit) {
                        $this->processCommit($commit);
                    }
                }

                continue;
            }

            $this->printData(sprintf("\n## %s (%s)\n\n", $storageEntry['slug'], $storageEntry['type']));

            foreach ($storageEntry['commits'] as $commit) {
                $this->processCommit($commit);
            }
        }

        $this->printData("\n" . '# Handled issues' . "\n");

        foreach ($this->issuesStorage as $storageEntry) {
            if ($storageEntry['issues'] === []) {
                continue;
            }

            if ($this->outputMode === 'by-week') {
                $issuesGroup = array_reduce($storageEntry['issues'], static function (array $accumulator, array $element) {
                    $accumulator[$element['closed_at']->format('W')][] = $element;

                    return $accumulator;
                }, []);
                ksort($issuesGroup);// newer weeks first

                $this->printData(sprintf("\n## %s (%s)\n", $storageEntry['slug'], $storageEntry['type']));

                foreach ($issuesGroup as $monthNumber => $issues) {
                    $this->printData(sprintf("\n### Week %s\n\n", $monthNumber));
                    foreach ($issues as $commit) {
                        $this->processIssue($commit);
                    }
                }

                continue;
            }

            $this->printData(sprintf("\n## %s (%s)\n\n", $storageEntry['slug'], $storageEntry['type']));

            foreach ($storageEntry['issues'] as $commit) {
                $this->processIssue($commit);
            }
        }
    }

    private function processIssue(array $issue): void
    {
        $this->printData(
            sprintf(
                '- [%s - %s](%s)' . "\n",
                $issue['number'],
                $issue['title'],
                $issue['html_url']
            )
        );
    }

    private function processCommit(array $commit): void
    {
        $this->printData(
            sprintf(
                '- [%s - %s](%s)' . "\n",
                substr($commit['sha'], 0, 10),
                $commit['message'],
                $commit['html_url']
            )
        );
    }

    private function loadDates(): void
    {
        $this->logDebug('Using month mode: ' . $this->monthMode);

        // Source: http://databobjr.blogspot.com/2011/06/get-first-and-last-day-of-month-in-bash.html

        // Dates use format: YYYY-MM-DDTHH:MM:SSZ

        if ($this->monthMode === 'last') {
            // Example (current date: 04/july(07)/2021 23:27): 2021-06-01T00:00:00Z
            $this->startDate = new DateTimeImmutable('@' . strtotime('first day of last month UTC'));
            // Example (current date: 04/july(07)/2021 23:27): 2021-06-30T00:00:00Z
            $this->endDate = new DateTimeImmutable('@' . strtotime('last day of last month UTC'));
        }

        if ($this->monthMode === 'current') {
            // Example (current date: 04/july(07)/2021 23:27): 2021-07-01T00:00:00Z
            $this->startDate = new DateTimeImmutable('@' . strtotime('first day of this month UTC'));
            // Example (current date: 04/july(07)/2021 23:27): 2021-07-31T00:00:00Z
            $this->endDate = new DateTimeImmutable('@' . strtotime('last day of this month UTC'));
        }

        if ($this->monthMode === 'next') {
            // Example (current date: 04/july(07)/2021 23:27): 2021-08-01T00:00:00Z
            $this->startDate = new DateTimeImmutable('@' . strtotime('first day of next month UTC'));
            // Example (current date: 04/july(07)/2021 23:27): 2021-08-31T00:00:00Z
            $this->endDate = new DateTimeImmutable('@' . strtotime('last day of next month UTC'));
        }

        $this->startDate = $this->startDate->setTimezone(new DateTimeZone('UTC'));
        $this->startDate = $this->startDate->setTime(00, 00, 00);
        $this->endDate = $this->endDate->setTimezone(new DateTimeZone('UTC'));
        $this->endDate = $this->endDate->setTime(23, 59, 59);

        $this->logDebug('Start date (Y-m-d H:i:s): ' . $this->startDate->format('Y-m-d H:i:s'));
        $this->logDebug('End date (Y-m-d H:i:s): ' . $this->endDate->format('Y-m-d H:i:s'));
    }
}

(new Reports())->run();
