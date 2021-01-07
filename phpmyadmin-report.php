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
            'phpmyadmin/phpmyadmin-security',
            'phpmyadmin/docker',
            'phpmyadmin/website',
            'phpmyadmin/sql-parser',
            'phpmyadmin/motranslator',
            'phpmyadmin/private',
            'phpmyadmin/shapefile',
            'phpmyadmin/simple-math',
            'phpmyadmin/localized_docs',
            'phpmyadmin/error-reporting-server',
            'phpmyadmin/twig-i18n-extension',
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

    /**
     * @var array{
     * type: "GitHub"|"GitLab", slug: string, data: array[] }
     * }[]
     */
    private array $commitsStorage = [];

    private bool $quietMode = false;
    private ?DateTimeImmutable $startDate = null;
    private ?DateTimeImmutable $endDate = null;
    private ?string $outputJsonData = null;
    private mixed $outputRender = STDOUT;
    private string $monthMode = 'none';
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
            'optional::',   // Optional value
            'start-date::', // Optional value
            'end-date::',   // Optional value
            'help',         // No value
            'quiet',        // No value
            'last-month',   // No value
            'current-month',// No value
            'next-month',   // No value
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

            if ($optionName !== 'output-json') {
                continue;
            }

            $this->outputJsonData = $optionValue;
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
        fwrite(STDOUT, '  Help: ./phpmyadmin-report.sh -h' . "\n");
        fwrite(STDOUT, '  Help: ./phpmyadmin-report.sh --help' . "\n");
        fwrite(STDOUT, '  Turn off debug: ./phpmyadmin-report.sh --quiet' . "\n");
        fwrite(STDOUT, '  Turn off debug: ./phpmyadmin-report.sh -q' . "\n");
        fwrite(STDOUT, '  Custom config: ./phpmyadmin-report.sh --config /home/user/report-config.conf' . "\n");
        fwrite(STDOUT, '  Custom output: ./phpmyadmin-report.sh --output /home/user/report-output.md' . "\n");
        fwrite(STDOUT, '  Store json data: ./phpmyadmin-report.sh --output-json /home/user/report-data.json' . "\n");
        fwrite(STDOUT, '  Last month: ./phpmyadmin-report.sh --last-month' . "\n");
        fwrite(STDOUT, '  Current month: ./phpmyadmin-report.sh --current-month' . "\n");
        fwrite(STDOUT, '  Next month: ./phpmyadmin-report.sh --next-month' . "\n");
        fwrite(STDOUT, '  Custom dates: ./phpmyadmin-report.sh --start-date 2021-05-03 --end-date 2021-06-27' . "\n");
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

        return $this->callApi(
            'https://api.github.com/' . $path,
            [
                'Authorization: token ' . $token,
                'Accept: application/vnd.github+json',
            ]
        );
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
        $startDate = $this->startDate->format('Y-m-d');
        $endDate = $this->endDate->format('Y-m-d');
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
        $startDate = $this->startDate->format('Y-m-d');
        $endDate = $this->endDate->format('Y-m-d');
        $commits = $this->callGitHubApi(
            $configBlockIn,
            "repos/${projectSlug}/commits?author=${username}&per_page=100&since=${startDate}&until=${endDate}"
        );
        if ($commits['message'] ?? false) {
            $this->quitError('GitHub API error: ' . $commits['message']);
        }

        $this->gitHubCommitsToStorage($commits, $projectSlug);
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
                    'cdate' => $commit['commit']['committer']['date'],
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
                    'cdate' => $commit['committed_date'],
                ];
            }, $commits),
        ];
    }

    private function printFinalData(): void
    {
        if ($this->outputJsonData === null) {
            return;
        }

        $this->logDebug('Data count: ' . count($this->commitsStorage));
        file_put_contents(
            $this->outputJsonData,
            json_encode($this->commitsStorage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
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

            $this->printData(sprintf("\n## %s (%s)\n\n", $storageEntry['slug'], $storageEntry['type']));

            foreach ($storageEntry['commits'] as $commit) {
                $message = $commit['message'];
                if (str_contains($message, 'Translated using Weblate')) {
                    continue;
                }

                $this->printData(
                    sprintf(
                        '- [%s - %s](%s)' . "\n",
                        substr($commit['sha'], 0, 10),
                        $message,
                        $commit['html_url']
                    )
                );
            }
        }
    }

    private function loadDates(): void
    {
        if ($this->monthMode === 'none') {
            $this->quitError('You need to specify a month mode, using cli: --{current,last,next}-month');
        }

        $this->logDebug('Using month mode: ' . $this->monthMode);

        // Source: http://databobjr.blogspot.com/2011/06/get-first-and-last-day-of-month-in-bash.html

        // Dates use format: YYYY-MM-DDTHH:MM:SSZ

        if ($this->monthMode === 'last') {
            // Example (current date: 04/july(07)/2021 23:27): 2021-06-01T00:00:00Z
            $this->startDate = new DateTimeImmutable('@' . strtotime('first day of last month'));
            // Example (current date: 04/july(07)/2021 23:27): 2021-06-30T00:00:00Z
            $this->endDate = new DateTimeImmutable('@' . strtotime('last day of last month'));
        }

        if ($this->monthMode === 'current') {
            // Example (current date: 04/july(07)/2021 23:27): 2021-07-01T00:00:00Z
            $this->startDate = new DateTimeImmutable('@' . strtotime('first day of this month'));
            // Example (current date: 04/july(07)/2021 23:27): 2021-07-31T00:00:00Z
            $this->endDate = new DateTimeImmutable('@' . strtotime('last day of this month'));
        }

        if ($this->monthMode === 'next') {
            // Example (current date: 04/july(07)/2021 23:27): 2021-08-01T00:00:00Z
            $this->startDate = new DateTimeImmutable('@' . strtotime('first day of next month'));
            // Example (current date: 04/july(07)/2021 23:27): 2021-08-31T00:00:00Z
            $this->endDate = new DateTimeImmutable('@' . strtotime('last day of next month'));
        }

        $this->logDebug('Start date (Y-m-d): ' . $this->startDate->format('Y-m-d'));
        $this->logDebug('End date (Y-m-d): ' . $this->endDate->format('Y-m-d'));
    }
}

(new Reports())->run();
