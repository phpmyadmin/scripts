#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

if (PHP_VERSION_ID < 80401) {// 80 4 01
    echo 'You need to use PHP 8.4.1 or above to run this script (symfony/mime requires it).' . PHP_EOL;
    echo 'Current detected: ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' (' . PHP_VERSION_ID . ')' . PHP_EOL;
    exit(1);
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, 'Missing dependencies, run "composer install" in this directory.' . PHP_EOL);
    exit(1);
}
require $autoload;

use DirectoryTree\ImapEngine\FileMessage;

/**
 * Triage and moderate the phpMyAdmin mailing-list moderation queues.
 *
 * Read-only unless --apply is given; --apply requires --request-id or --action
 * naming exactly which held messages to act on.
 *
 * Endpoints used:
 *   GET  /accounts/login/
 *   GET  /postorius/lists/<list>/held_messages
 *   POST /postorius/lists/<list>/held_messages/moderate   msgid + reason + reject|discard
 *   GET  /postorius/lists/<list>/bans/
 *   POST /postorius/lists/<list>/bans/                    add=1|del=1 + email
 *   GET  /postorius/api/list/<list>/held_message/<id>/
 *
 * Configuration, in ~/.config/phpmyadmin:
 *
 *   [lists.phpmyadmin.net]
 *   user = <login>
 *   password = <password>
 *   ; optional
 *   url = https://lists.phpmyadmin.net/mailman3
 *   list = security@phpmyadmin.net
 *   reputation = path/to/mailman-moderation-reputation.md
 *   ledger = path/to/mailman-moderation-ledger.jsonl
 */

/**
 * A request that never produced an HTTP answer.
 *
 * Distinct from a 4xx/5xx, which IS an answer. This is thrown rather than exiting
 * so that act() can record the failure and continue with the remaining messages;
 * outside act() it propagates and ends the run, which is the same outcome as
 * before.
 */
class HttpFailure extends RuntimeException
{
}

/**
 * A ban value the tool refuses to issue.
 *
 * Thrown rather than exiting so that a single unusable address cannot abandon a
 * batch; outside a batch it ends the run with the same message as before.
 */
class BanRefused extends RuntimeException
{
}

class MailmanModeration
{
    private const DEFAULT_BASE = 'https://lists.phpmyadmin.net/mailman3';
    private const DEFAULT_LIST = 'security@phpmyadmin.net';

    /** Verdicts used in the plan and in --json; see verdictLabel() for output. */
    private const VERDICT_BAN = 'BAN';
    /** Phishing: no judgement needed, so it is acted on rather than reviewed. */
    private const VERDICT_DISCARD = 'DISCARD';
    private const VERDICT_REVIEW = 'REVIEW';
    private const VERDICT_BANNED = 'ALREADY-BANNED';
    /** A trusted domain with nothing found against it: let it through. */
    private const VERDICT_ACCEPT = 'ACCEPT';

    /**
     * Which verdict wins when several apply. Higher wins.
     *
     * RANK_EMPTY and RANK_LANGUAGE sit above RANK_BAN because neither leaves
     * anything to judge: an empty message and one written in another script are
     * dropped, not banned. RANK_BAN sits above RANK_BULK so that fraud --
     * impersonating a bank, an invoice or a payment process -- is banned even
     * when the message also carries newsletter plumbing.
     */
    private const RANK_EMPTY = 4;
    private const RANK_LANGUAGE = 3;
    private const RANK_BAN = 2;
    private const RANK_BULK = 1;

    /** Site-wide bans are read page by page, independent of --limit. */
    private const BANS_PER_PAGE = 200;
    private const BANS_MAX_PAGES = 20;
    /** Held queue is read in full: the per-domain counts depend on it. */
    private const HELD_MAX = 1000;
    private const PRIVATE_DIR = __DIR__ . '/../../private';
    private const DEFAULT_LEDGER = self::PRIVATE_DIR . '/mailman-moderation-ledger.jsonl';
    private const DEFAULT_REPUTATION = self::PRIVATE_DIR . '/mailman-moderation-reputation.md';

    private string $configFile = '~/.config/phpmyadmin';
    private string $base = self::DEFAULT_BASE;
    private string $list = self::DEFAULT_LIST;
    /** Set when --list was passed, so the config cannot override it. */
    private bool $listGiven = false;
    /** Empty means "derive the list owner address"; set by config to override. */
    private string $ledger = self::DEFAULT_LEDGER;
    private string $reputationFile = self::DEFAULT_REPUTATION;
    private string $user = '';
    private string $password = '';
    private string $cookieJar = '';
    /** @var list<string> */
    private array $temporaryFiles = [];
    private string $userAgent = 'phpMyAdmin/mailing-list-moderation';

    /**
     * Upstream feeds, each refreshed at most once a day.
     *
     * DISPOSABLE_URL: throw-away mail domains.
     * FIREHOL_URL:    firehol_level1, a firewall blocklist built from dshield,
     *                 feodo, fullbogons and spamhaus drop, curated for maximum
     *                 protection with minimum false positives. IPv4 only.
     */
    // phpcs:ignore Generic.Files.LineLength.TooLong
    private const DISPOSABLE_URL = 'https://raw.githubusercontent.com/crowdsecurity/sec-lists/master/appsec/disposable_email_domains.data';
    private const FIREHOL_URL = 'https://iplists.firehol.org/files/firehol_level1.netset';
    private const FEED_MAX_AGE = 86400;
    /** Transport retries per request; a dropped connection is not an answer. */
    private const HTTP_ATTEMPTS = 4;
    /**
     * Gap between requests. The server starts dropping connections when its
     * moderation queue is polled quickly, and this runs a request per message.
     */
    private const REQUEST_DELAY_US = 700_000;

    /**
     * Signal labels that are noise rather than evidence on a domain we trust.
     *
     * A known correspondent writes from an allowlisted domain, so "allowlisted"
     * itself proves nothing; the rest are weak infrastructure notes. Anything not
     * listed here counts, and keeps the message for a human.
     */
    private const NOISE_SIGNALS = [
        'allowlisted domain',
        'no Message-ID',
        'generic security wording',
        'bulk-mailer header',
    ];

    /**
     * Signals that argue FOR the sender.
     *
     * A signed or encrypted attachment is evidence of care, not abuse, so it must
     * not push an allow-listed domain to review. Matched by name: the old test was
     * "does the signal start with a minus", dead once the point signs were removed.
     */
    private const EVIDENCE_FOR_SENDER = ['signed or encrypted attachment'];

    /** How wide a line of REASON text may get before it wraps. */
    private const REASON_WIDTH = 38;
    /**
     * Display columns before the REASON column.
     *
     * Sum of ID, VERDICT, SENDER and SUBJECT plus their separators. Kept as a
     * constant because the continuation lines of a wrapped reason align to it.
     */
    private const REASON_INDENT = 5 + 1 + 24 + 1 + 28 + 1 + 28 + 1;
    private string $disposableCache = self::PRIVATE_DIR . '/cache/disposable_email_domains.data';
    private string $fireholCache = self::PRIVATE_DIR . '/cache/firehol_level1.netset';
    private bool $offline = false;
    /** When the last outbound request finished, for throttling. */
    private float $lastRequestAt = 0.0;
    /**
     * Held ids whose body could not be read, reported in the summary.
     *
     * @var list<string>
     */
    private array $unscorable = [];

    /** @var array<string, mixed> */
    private array $rep = [];
    /** @var array<string, true> */
    private array $disposableSet = [];
    /** @var list<array{0: int, 1: int}> sorted [first, last] IPv4 ranges */
    private array $blockedRanges = [];
    /** Domain-wide ban needs this many held messages from one domain. */
    private int $domainBanMinMessages = 3;

    /**
     * Term registry: one entry per policy line, each carrying the short reason
     * that line declares and the verdict it produces.
     *
     * @var list<array{section: string, label: string, verdict: string, terms: list<string>}>
     */
    private array $termGroups = [];

    /**
     * Protective vocabularies. A conditional one only protects when the message
     * matched no phishing, scam or bulk line.
     *
     * @var list<array{label: string, conditional: bool, terms: list<string>}>
     */
    private array $vetoGroups = [];

    /**
     * Brand names matched against the From display name only.
     *
     * A section whose verdict is Ban, applied to a different FIELD than the body:
     * "we found a PayPal phishing kit" is ordinary content, while a display name
     * that *is* the brand, from a domain that does not own it, is impersonation.
     *
     * @var list<array{label: string, brands: list<array{brand: string, owned: list<string>}>}>
     */
    private array $displayNameGroups = [];

    /**
     * Sender-facing wording, from the policy file's Messages section.
     *
     * @var array<string, string>
     */
    private array $messages = [];

    /**
     * Policy label => table flag, taken from the section the label was written in.
     *
     * @var array<string, string>
     */
    private array $labelFlags = [];

    private bool $applyChanges = false;
    /** Force read-only: report what would happen and change nothing. */
    private bool $dryRun = false;
    private bool $json = false;
    private bool $silent = false;
    /** '', 'list', 'global' or 'all'. */
    private string $showBans = '';
    private int $limit = 200;
    /** @var list<int> */
    private array $requestIds = [];
    /**
     * Verdicts --action names, e.g. "ban,accept".
     *
     * Naming a whole class is a deliberate, explicit choice: it is never implied by
     * --action alone, so a run can still require --request-id for everything.
     *
     * @var list<string>
     */
    private array $actions = [];
    /**
     * Decisions for rows the triage left for a human, e.g. ["accept"].
     *
     * A Needs review row carries no verdict, so the tool has nothing to act on
     * until the operator states the decision. Naming one here is that statement.
     *
     * @var list<string>
     */
    private array $reviewActions = [];
    /**
     * Whether a repeated domain may be banned as a whole.
     *
     * Off by default. A From header is trivially forged: three held messages
     * claiming to be from a domain would otherwise ban every address at it,
     * including a researcher's employer. Per-address bans do not have that failure
     * mode, so widening needs to be asked for.
     */
    private bool $banDomain = false;
    private string |null $banNow = null;
    private string |null $unbanNow = null;

    public function __construct()
    {
        $this->parseArguments();
        $this->configFile = $this->expandTilde($this->configFile);

        // Settle the ban-list intents before anything else: a dry run of
        // --ban/--unban needs no configuration, no network and no session, and a
        // missing --apply must be reported before any credentials are used.
        $banChange = null;
        if ($this->unbanNow !== null) {
            $banChange = [$this->requireCommit('unban', $this->unbanNow), false];
        } elseif ($this->banNow !== null) {
            $banChange = [$this->requireCommit('ban', $this->banNow), true];
        }

        foreach (['curl', 'mbstring', 'ctype', 'json'] as $extension) {
            $this->checkExt($extension);
        }
        $this->readConfig();
        $this->ledger = $this->expandTilde($this->ledger);
        $this->loadReputation();
        // Now the allow list exists, so a hand-written ban can be checked against
        // it. Doing this during argument parsing made the guard dead code.
        if ($banChange !== null) {
            $this->refuseIfBanningTrustedDomain($banChange[0]);
        }
        $this->disposableSet = $this->loadDisposableSet();
        $this->blockedRanges = $this->loadBlockedRanges();
        $this->login();

        if ($this->showBans !== '') {
            $this->printBans();

            return;
        }
        if ($banChange !== null) {
            exit($this->applyBanChange($banChange[0], $banChange[1]) ? 0 : 1);
        }

        $this->triage();
    }

    /**
     * Changing the ban list is an outward-facing action, so it needs --apply
     * like every other write. Without it, print the intended change and stop.
     */
    private function requireCommit(string $what, string $target): string
    {
        if ($this->applyChanges) {
            return $target;
        }
        echo 'DRY-RUN: would ' . $what . ' ' . $target . ' on ' . $this->list . PHP_EOL;
        echo 'nothing was changed; add --apply to perform it.' . PHP_EOL;
        // 0: nothing is wrong, there is simply nothing to commit to. Exit 2 means
        // "spam was found", which a ban preview is not.
        exit(0);
    }

    /**
     * @source https://compwright.com/2013-09-03/tilde-expansion-in-php/
     */
    private function expandTilde(string $path): string
    {
        // Only a LEADING tilde is a home reference. str_replace() would rewrite
        // "~" anywhere in the path, quietly mangling it.
        if (
            function_exists('posix_getuid')
            && ($path === '~' || str_starts_with($path, '~/'))
        ) {
            /** @var array{dir: string} $info */
            $info = posix_getpwuid(posix_getuid());

            return $info['dir'] . substr($path, 1);
        }

        return $path;
    }

    private function checkExt(string $ext): void
    {
        if (extension_loaded($ext)) {
            return;
        }

        $this->quitError('PHP extension "' . $ext . '" is required.');
    }

    private function parseArguments(): void
    {
        foreach (array_slice($GLOBALS['argv'], 1) as $arg) {
            $arg = (string) $arg;
            if ($arg === '-h' || $arg === '--help') {
                $this->printHelp();
                exit(0);
            }

            if ($arg === '--apply' || $arg === '-apply') {
                $this->applyChanges = true;
            } elseif ($arg === '--dry-run') {
                $this->dryRun = true;
            } elseif ($arg === '--json') {
                $this->json = true;
            } elseif ($arg === '--ban-domain') {
                $this->banDomain = true;
            } elseif (str_starts_with($arg, '--action=')) {
                foreach (explode(',', substr($arg, 9)) as $name) {
                    $name = strtolower(trim($name));
                    $map = [
                        'ban' => self::VERDICT_BAN,
                        // A sender already on the ban list whose message is still
                        // held, because an earlier run's moderation failed. Named
                        // after the verdict it selects, which is also what the
                        // table calls such a row.
                        'already-banned' => self::VERDICT_BANNED,
                        'discard' => self::VERDICT_DISCARD,
                        'accept' => self::VERDICT_ACCEPT,
                    ];
                    if (! isset($map[$name])) {
                        $this->quitError('--action takes ban, already-banned, discard or accept; got "' . $name . '".');
                    }
                    $this->actions[] = $map[$name];
                }
            } elseif ($arg === '--silent') {
                $this->silent = true;
            } elseif ($arg === '--offline') {
                $this->offline = true;
            } elseif ($arg === '--show-bans') {
                // Bare flag: the whole picture, list and site-wide.
                $this->showBans = 'all';
            } elseif (str_starts_with($arg, '--show-bans=')) {
                $scope = strtolower(trim(substr($arg, 12)));
                if (! in_array($scope, ['list', 'global', 'all'], true)) {
                    $this->quitError('--show-bans takes list, global or all; got "' . $scope . '".');
                }
                $this->showBans = $scope;
            } elseif (str_starts_with($arg, '--config=')) {
                $this->configFile = substr($arg, 9);
            } elseif (str_starts_with($arg, '--list=')) {
                $name = trim(substr($arg, 7));
                if ($name === '' || str_contains($name, '@') !== true) {
                    $this->quitError('--list takes a list address, got "' . $name . '".');
                }
                $this->list = $name;
                $this->listGiven = true;
            } elseif (str_starts_with($arg, '--limit=')) {
                $raw = substr($arg, 8);
                if (preg_match('/^[1-9]\d*$/', $raw) !== 1) {
                    $this->quitError('--limit takes a positive integer, got "' . $raw . '".');
                }
                $this->limit = (int) $raw;
            } elseif (str_starts_with($arg, '--request-id=') || str_starts_with($arg, '--request-ids=')) {
                $value = str_starts_with($arg, '--request-id=')
                    ? substr($arg, 13)
                    : substr($arg, 14);
                foreach (explode(',', $value) as $token) {
                    $token = trim($token);
                    if ($token === '') {
                        continue;
                    }
                    // A range keeps a long list readable: 412-415 is four messages.
                    if (preg_match('/^(\d+)-(\d+)$/', $token, $range) === 1) {
                        [$from, $to] = [(int) $range[1], (int) $range[2]];
                        if ($to < $from) {
                            $this->quitError('--request-id range is backwards: ' . $token);
                        }
                        if ($to - $from > 500) {
                            $this->quitError('--request-id range is too wide: ' . $token);
                        }
                        for ($id = $from; $id <= $to; $id++) {
                            $this->requestIds[] = $id;
                        }
                        continue;
                    }
                    // Silently dropping "12x" would act on a different set than the
                    // operator typed.
                    if (ctype_digit($token) !== true) {
                        $this->quitError('--request-id takes ids and ranges such as 412,415-418; got "'
                            . $token . '".');
                    }
                    $this->requestIds[] = (int) $token;
                }
            } elseif (str_starts_with($arg, '--review-action=')) {
                foreach (explode(',', substr($arg, 16)) as $decision) {
                    $decision = strtolower(trim($decision));
                    if ($decision === '') {
                        continue;
                    }
                    if (! in_array($decision, ['accept', 'discard'], true)) {
                        $this->quitError('--review-action takes accept or discard; got "' . $decision . '".');
                    }
                    $this->reviewActions[] = $decision;
                }
            } elseif (str_starts_with($arg, '--ban=')) {
                $this->banNow = $this->validBanValue(substr($arg, 6));
            } elseif (str_starts_with($arg, '--unban=')) {
                // Removing a ban is not creating one: the narrowness rules exist to
                // stop a too-broad ban being issued, and applying them here is what
                // left the tool unable to undo its own accidents.
                $this->unbanNow = trim(substr($arg, 8));
                if ($this->unbanNow === '') {
                    $this->quitError('--unban needs a value.');
                }
            } else {
                $this->quitError('Unknown option: ' . $arg);
            }
        }

        // Naming an action is not performing it. --action and --review-action select what
        // would be done; --apply is the only thing that does it. An earlier version
        // let --apply imply the write, which turned a preview into 66 live actions.
        if ($this->dryRun) {
            $this->applyChanges = false;
        }

        // --review-action decides rows that carry no verdict, so the set must be named.
        if ($this->reviewActions === []) {
            return;
        }

        if ($this->requestIds === []) {
            $this->quitError('--review-action needs --request-id=<ids> naming the rows to decide.');
        }
        if (count($this->reviewActions) <= 1) {
            return;
        }

        $this->quitError('--review-action takes one decision per run: accept or discard. Got "'
            . implode(',', $this->reviewActions) . '".');
    }

    /**
     * Refuse a ban pattern that is not narrow enough, by testing it.
     *
     * Spelling checks are unreliable: "^.*@[a-z0-9.-]+$" contains no "*" in the
     * domain yet matches every address, and the tool's own domain-ban form
     * "^.*@phpmyadmin\.net$" defeats a substring search for "phpmyadmin.net"
     * because of the escaped dot. So the pattern is compiled and run against
     * canaries: any trusted address it matches, or any set of unrelated addresses
     * it matches, means it is too broad to issue.
     */
    private function assertBanIsNarrowEnough(string $pattern): void
    {
        $compiled = '#' . str_replace('#', '\#', $pattern) . '#i';
        if (preg_match($compiled, '') === false) {
            throw new BanRefused('Invalid ban pattern: ' . $pattern);
        }

        // Every trusted domain must be unreachable by this pattern. Shared
        // providers count: banning "^.*@gmail\.com$" would cut off every
        // researcher who reports from a personal mailbox.
        $protected = array_merge(
            $this->repList('allow_domains'),
            $this->repList('shared_domains')
        );
        foreach ($protected as $trusted) {
            $probe = 'someone@' . $trusted;
            if (preg_match($compiled, $probe) === 1) {
                throw new BanRefused('Ban pattern would block ' . $probe
                    . ' (a domain we never ban whole): ' . $pattern);
            }
        }

        // Unrelated canaries: a pattern matching all of them matches everyone.
        $unrelated = [
            'victim@example.org',
            'researcher@unrelated-research.test',
            'team@another-company.test',
        ];
        $hits = 0;
        foreach ($unrelated as $probe) {
            if (preg_match($compiled, $probe) !== 1) {
                continue;
            }

            $hits++;
        }
        if ($hits >= 2) {
            throw new BanRefused('Ban pattern matches unrelated senders: ' . $pattern);
        }
    }

    /**
     * Refuse a hand-written ban that covers a domain we trust.
     *
     * This cannot live in validBanValue(): that runs during argument parsing,
     * before loadReputation() fills the allow list, so the check read an empty
     * array and never fired. "Never let a ban cover phpmyadmin.net" needs the
     * policy loaded first.
     */
    private function refuseIfBanningTrustedDomain(string $value): void
    {
        // Delegates to the canary test: a substring search for the domain name is
        // defeated by the escaped form the tool itself generates.
        $this->assertBanIsNarrowEnough($value);
    }

    /**
     * A ban is a bare address or a regexp starting with "^", matched against the
     * whole address. Validate here: the ban list is applied server-side before we
     * ever see it, so a pattern like "^.*@.*$" would silently ban every sender.
     */
    private function validBanValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new BanRefused('Empty ban value.');
        }

        if ($value[0] === '^') {
            // A pattern cannot be judged by its spelling: "^.*@[a-z0-9.-]+$" has
            // no "*" in the domain and matches every address, and
            // "^.*@phpmyadmin\.net$" is the tool's own domain-ban format while
            // containing an escaped dot. So the pattern is TESTED instead: it must
            // not match any address at a domain we trust, and must not match an
            // address at a domain unrelated to the one it names.
            $this->assertBanIsNarrowEnough($value);

            return $value;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new BanRefused('Ban value is not an email address: ' . $value);
        }

        return strtolower($value);
    }

    private function readConfig(): void
    {
        if (! file_exists($this->configFile)) {
            $this->quitError('Missing config file: ' . $this->configFile . PHP_EOL
                . 'It needs a [' . (string) parse_url(self::DEFAULT_BASE, PHP_URL_HOST) . '] section'
                . ' with "user" and "password".');
        }
        $config = parse_ini_file($this->configFile, true, INI_SCANNER_RAW);
        if ($config === false) {
            $this->quitError('Could not parse the INI file ' . $this->configFile
                . PHP_EOL . ini_get('error_log'));
        }

        $host = (string) parse_url(self::DEFAULT_BASE, PHP_URL_HOST);
        $section = $host;
        /** @var array<string, string>|null $block */
        $block = $config[$host] ?? $config['mailman'] ?? null;
        if ($block === null) {
            $this->quitError('No [' . $host . '] (or [mailman]) section in ' . $this->configFile);
        }
        if (isset($config['mailman']) && ! isset($config[$host])) {
            $section = 'mailman';
        }

        // RAW keeps backslashes and '$' intact, which a password needs, but it
        // also keeps surrounding quotes: strip one matching pair.
        $unquote = static function (string $v): string {
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
                return substr($v, 1, -1);
            }

            return $v;
        };
        $this->user = $unquote((string) ($block['user'] ?? ''));
        $this->password = $unquote((string) ($block['password'] ?? ''));
        $missing = [];
        if ($this->user === '') {
            $missing[] = 'user';
        }
        if ($this->password === '') {
            $missing[] = 'password';
        }
        if ($missing !== []) {
            $this->quitError('Missing "' . implode('" and "', $missing) . '" in the [' . $section
                . '] section of ' . $this->configFile . PHP_EOL
                . 'user and password are the Postorius login used to read the moderation queue.');
        }
        if (isset($block['url']) && $block['url'] !== '') {
            $this->base = rtrim((string) $block['url'], '/');
        }
        // --list wins: the config only supplies a default. Overwriting the
        // command-line value retargets every URL, so ids would be acted on in a
        // different list than the operator named.
        if (isset($block['list']) && $block['list'] !== '' && ! $this->listGiven) {
            $this->list = (string) $block['list'];
        }
        if (isset($block['ledger']) && $block['ledger'] !== '') {
            $this->ledger = (string) $block['ledger'];
        }
        if (! isset($block['reputation']) || $block['reputation'] === '') {
            return;
        }

        $this->reputationFile = $this->expandTilde((string) $block['reputation']);
    }

    /**
     * Domain lists, patterns and thresholds; kept out of this repository.
     *
     * The policy file is markdown: "## Section" headings and "- " items. A
     * pattern item is a backtick-quoted regex optionally followed by a reason.
     * Unknown sections are an error so a typo cannot silently drop rules.
     */
    private function loadReputation(): void
    {
        $path = $this->reputationFile;
        if (! is_file($path)) {
            $hint = 'Set "reputation = <path>" in ' . $this->configFile;
            $this->quitError('Missing reputation file: ' . $path . PHP_EOL . $hint);
        }

        $lists = [
            'allow_domains' => 'allow_domains',
            'shared_domains' => 'shared_domains',
            'suspicious_tlds' => 'suspicious_tlds',
            'shorteners' => 'shorteners',
        ];

        $contents = (string) file_get_contents($path);
        $this->parseMessageBlocks($contents, $path);

        $section = '';
        $verdict = '';
        foreach (explode("\n", $contents) as $number => $raw) {
            $line = trim(str_replace(["\r", "\u{00a0}"], '', $raw));
            $where = $path . ':' . ($number + 1);
            // Items must start at column 0: an indented "- " is documentation,
            // such as the format example at the top of the file.
            $isItem = str_starts_with($raw, '- ');

            if ($line === '' || str_starts_with($line, '#')) {
                if (preg_match('/^##\s+(.+?)(?:\s*->\s*(.+))?$/', $line, $heading) === 1) {
                    $section = strtolower(trim(preg_replace('/\s+/', '_', $heading[1]) ?? $heading[1]));
                    $verdict = $this->verdictFromHeading(trim($heading[2] ?? ''), $where);
                }
                continue;
            }
            if (! $isItem) {
                continue; // prose or an indented example
            }
            if ($section === 'messages') {
                continue; // parsed as blocks, above
            }
            $item = trim(substr($line, 2));

            if ($section === 'thresholds') {
                if (preg_match('/^([a-z_]+)\s*:\s*(\d+)$/', $item, $parts) !== 1) {
                    $this->quitError('Cannot read threshold "' . $item . '" at ' . $where);
                }
                // ban_score and domain_ban_score are gone: a verdict comes from
                // the section a policy line is written in, not from a tally.
                if ($parts[1] === 'ban_score' || $parts[1] === 'domain_ban_score') {
                    continue;
                }
                if ($parts[1] === 'domain_ban_min_messages') {
                    $this->domainBanMinMessages = (int) $parts[2];
                } else {
                    $this->quitError('Unknown threshold "' . $parts[1] . '" at ' . $where);
                }
                continue;
            }

            if (isset($lists[$section])) {
                $this->rep[$lists[$section]][] = strtolower($item);
                continue;
            }

            if (str_contains($section, 'display_name')) {
                $this->addDisplayNameLine($item, $where);
                continue;
            }

            if ($verdict !== '') {
                $this->addTermLine($section, $verdict, $item, $where);
                continue;
            }

            $this->quitError('Item under a section with no verdict "## ' . $section . '" at ' . $where
                . PHP_EOL . 'Add "-> Ban", "-> Discard and notify" or "-> Needs review" to the heading.');
        }

        if ($this->termGroups === []) {
            $this->quitError('Reputation file defines no patterns: ' . $path);
        }
        foreach (['discard', 'banned', 'already_banned', 'foreign', 'void'] as $name) {
            if (isset($this->messages[$name]) && $this->messages[$name] !== '') {
                continue;
            }

            $this->quitError('Reputation file is missing the "' . $name
                . '" message in its Messages section: ' . $path);
        }
        if ($this->vetoGroups !== []) {
            return;
        }

        $this->quitError('Reputation file defines no protective vocabulary: ' . $path);
    }

    /**
     * Sender-facing messages, in the policy file's block form:
     *
     *     - discard:
     *     ---
     *     text, with real line breaks
     *     ---
     *
     * Blocks rather than one line: these are paragraphs a stranger reads, and a
     * single line with \n escapes is unreadable and easy to break.
     */
    private function parseMessageBlocks(string $contents, string $path): void
    {
        // The section runs from "## Messages" to the next heading.
        if (preg_match('/^##\s+Messages\s*$(.*?)(?=^##\s|\z)/ms', $contents, $sectionMatch) !== 1) {
            return;
        }

        $offset = (int) strpos($contents, $sectionMatch[0]);
        $pattern = '/^-\s+([a-z_]{3,20}):[ \t]*\n-{3}[ \t]*\n(.*?)\n-{3}[ \t]*$/ms';
        if (preg_match_all($pattern, $sectionMatch[1], $blocks, PREG_SET_ORDER) === 0) {
            return;
        }

        foreach ($blocks as $block) {
            $name = (string) $block[1];
            $text = (string) $block[2];
            if (trim($text) === '') {
                continue;
            }
            // Line breaks are kept exactly as written: they are paragraphs.
            $this->messages[$name] = trim($text);
        }
    }

    /** "Ban" -> the verdict constant; empty string when the heading has no verdict. */
    private function verdictFromHeading(string $text, string $where): string
    {
        if ($text === '') {
            return '';
        }
        $key = strtolower(preg_replace('/\s+/', ' ', $text) ?? $text);
        $map = [
            'ban' => self::VERDICT_BAN,
            'discard and notify' => self::VERDICT_DISCARD,
            'discard' => self::VERDICT_DISCARD,
            'needs review' => self::VERDICT_REVIEW,
            'review' => self::VERDICT_REVIEW,
        ];
        if (! isset($map[$key])) {
            $this->quitError('Unknown verdict "' . $text . '" at ' . $where
                . PHP_EOL . 'Use Ban, Discard and notify or Needs review.');
        }

        return $map[$key];
    }

    /**
     * Split and validate a comma-separated term list.
     *
     * @return list<string>
     */
    private function cleanTerms(string $raw, string $where): array
    {
        $terms = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $term): bool => $term !== ''
        ));

        if (count($terms) > 5) {
            $this->quitError('At most five terms per line, got ' . count($terms) . ' at ' . $where
                . PHP_EOL . 'Split the line instead of making it hard to read.');
        }
        if ($terms === []) {
            // An empty list compiles to "/(?:)/i", which matches every string. One
            // such line under a Ban heading would ban the whole queue.
            $this->quitError('A term line needs at least one term, at ' . $where);
        }
        foreach ($terms as $term) {
            if (preg_match('/^[\x20-\x3a\x3c-\x7e]+$/', $term) === 1) {
                continue;
            }

            $this->quitError('Term must be printable ASCII without a colon: "'
                . $term . '" at ' . $where);
        }

        return $terms;
    }

    /** One display-name line: "- wallet brands: paypal, klarna". */
    private function addDisplayNameLine(string $item, string $where): void
    {
        if (preg_match('/^([^:]{1,28}):\s*(.+)$/', $item, $parts) !== 1) {
            $this->quitError('Expected "reason: brand, brand" at ' . $where);
        }
        // Every entry must name the domain that owns the brand. Without it the
        // rule cannot tell impersonation from the brand's own mail.
        $brands = [];
        foreach ($this->cleanTerms($parts[2], $where) as $term) {
            $bits = explode('=', $term, 2);
            if (count($bits) !== 2 || $bits[1] === '') {
                $this->quitError('Display-name entry must be "brand=owned.domain", got "'
                    . $term . '" at ' . $where);
            }
            $brands[] = ['brand' => trim($bits[0]), 'owned' => [strtolower(trim($bits[1]))]];
        }
        $this->displayNameGroups[] = ['label' => trim($parts[1]), 'brands' => $brands];
    }

    /** One policy line: "- short reason: term, term, term". */
    private function addTermLine(string $section, string $verdict, string $item, string $where): void
    {
        if (preg_match('/^([^:]{1,28}):\s*(.+)$/', $item, $parts) !== 1) {
            $this->quitError('Expected "reason: term, term" with a reason of at most 28'
                . ' characters, got "' . $item . '" at ' . $where);
        }
        $label = trim($parts[1]);
        $terms = $this->cleanTerms($parts[2], $where);

        if ($verdict === self::VERDICT_REVIEW) {
            $this->vetoGroups[] = [
                'label' => $label,
                // "generic security" protects only when nothing worse matched.
                'conditional' => str_contains($section, 'generic'),
                'terms' => $terms,
            ];

            return;
        }

        if (str_contains($section, 'scam')) {
            $this->labelFlags[$label] = 'SCAM';
        } elseif (str_contains($section, 'phish')) {
            $this->labelFlags[$label] = 'PHISHING';
        } elseif ($verdict === self::VERDICT_DISCARD) {
            $this->labelFlags[$label] = 'BULK';
        } else {
            $this->labelFlags[$label] = 'BAN-RULE';
        }
        $this->termGroups[] = [
            'section' => $section,
            'label' => $label,
            'verdict' => $verdict,
            'terms' => $terms,
        ];
    }

    /**
     * A group's terms as one whole-word, case-insensitive alternation. Terms are
     * plain text, never regex, so nothing in the policy can be malformed.
     *
     * @param list<string> $terms
     */
    private function groupPattern(array $terms): string
    {
        if ($terms === []) {
            // "/(?:)/i" matches every string. Never let an empty list mean "all".
            $this->quitError('Internal error: a term group with no terms.');
        }
        $parts = [];
        foreach ($terms as $term) {
            $quoted = preg_quote($term, '/');
            if (preg_match('/^\w/', $term) === 1) {
                $quoted = '\b' . $quoted;
            }
            if (preg_match('/\w$/', $term) === 1) {
                // Tolerate the plural: "failed login attempt" must also catch
                // "failed login attempts", which is how most of them are phrased.
                $quoted .= '(?:s|es)?\b';
            }
            $parts[] = $quoted;
        }

        return '/(?:' . implode('|', $parts) . ')/i';
    }

    /**
     * Fold accents so ASCII terms match accented text.
     *
     * The policy file is ASCII by design, but the queue is full of Portuguese and
     * German: "Currículo", "pendência", "Geschäftsbedingungen". Without folding,
     * a term can never match those.
     */
    private function foldAccents(string $text): string
    {
        /** @var array<string, string> $map */
        $map = [
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'ã' => 'a',
            'å' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'ô' => 'o',
            'ö' => 'o',
            'õ' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ç' => 'c',
            'ñ' => 'n',
            'ý' => 'y',
            'ÿ' => 'y',
            'Á' => 'A',
            'À' => 'A',
            'Â' => 'A',
            'Ä' => 'A',
            'Ã' => 'A',
            'É' => 'E',
            'È' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'Í' => 'I',
            'Ì' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'Ó' => 'O',
            'Ò' => 'O',
            'Ô' => 'O',
            'Ö' => 'O',
            'Õ' => 'O',
            'Ú' => 'U',
            'Ù' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'Ç' => 'C',
            'Ñ' => 'N',
        ];

        // Senders write "&" where the policy file writes "and": "INVOICE & SOA"
        // must match the term "invoice and soa". Folding it here means every
        // multi-word term works without listing both spellings.
        $text = strtr($text, $map);

        // Typographic apostrophes are folded to ASCII: phishing and outreach mail
        // uses U+2019, so "let's connect" would only match the ASCII spelling.
        $text = strtr($text, ["\u{2019}" => "'", "\u{2018}" => "'", "\u{02BC}" => "'"]);
        $text = (string) preg_replace('/\s*&\s*/', ' and ', $text);

        // Cyrillic and Greek letters that render like Latin ones. "РауРаl" from a
        // throw-away domain is a PayPal impersonation, but no ASCII term can match
        // it until the lookalikes are folded. This is why 310 escaped the phishing
        // rules that catch eight ASCII PayPal impersonations.
        /** @var array<string, string> $confusables */
        $confusables = [
            'а' => 'a',
            'е' => 'e',
            'о' => 'o',
            'р' => 'p',
            'с' => 'c',
            'у' => 'y',
            'х' => 'x',
            'і' => 'i',
            'ѕ' => 's',
            'һ' => 'h',
            'ј' => 'j',
            'ԁ' => 'd',
            'ԛ' => 'q',
            'ԝ' => 'w',
            'ɡ' => 'g',
            'ո' => 'n',
            'ս' => 'u',
            'ν' => 'v',
            'А' => 'A',
            'В' => 'B',
            'Е' => 'E',
            'К' => 'K',
            'М' => 'M',
            'Н' => 'H',
            'О' => 'O',
            'Р' => 'P',
            'С' => 'C',
            'Т' => 'T',
            'Х' => 'X',
            'У' => 'Y',
        ];

        return strtr($text, $confusables);
    }

    /**
     * Whether a subject is written in a CJK script (Chinese, Japanese, Korean).
     *
     * Accented Latin is deliberately not matched: "Vulnerabilite" written in
     * French is a correspondent we want. Hangul, Han, Kana and full-width forms
     * are, because this list works in English and a CJK subject has never been a
     * genuine report here.
     */
    private function hasCjkScript(string $text): bool
    {
        return preg_match(
            '/[\x{1100}-\x{11FF}\x{2E80}-\x{2FDF}\x{3000}-\x{303F}\x{3040}-\x{30FF}'
            . '\x{3130}-\x{318F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}'
            . '\x{F900}-\x{FAFF}\x{FF00}-\x{FFEF}]/u',
            $text
        ) === 1;
    }

    /**
     * Domains in the text that imitate a brand we own without being it.
     *
     * "phpmyadmin.cn", "phpmyadmin.com.cn", "phpmyadmin-net.com": a host whose
     * label carries one of our brand names but whose full name is not one we
     * hold. A legitimate sender writes the real domain, so the mention is
     * evidence of a squatting or credibility-farming pitch.
     *
     * The shape has to be a host, with a real TLD: a report naming
     * "phpmyadmin-pma-01-08-disclosures-2026-09" is a file or a ticket id, not a
     * domain, and treating it as one would ban the people we most want to hear
     * from.
     *
     * @return array<string, string> lookalike domain => the brand it imitates
     */
    private function lookalikeDomains(string $haystack): array
    {
        $brands = [];
        foreach ($this->repList('allow_domains') as $allowed) {
            $label = strtok($allowed, '.');
            if ($label === false || strlen($label) < 5) {
                continue;
            }

            $brands[$label][] = $allowed;
        }

        $found = [];
        foreach ($brands as $brand => $owned) {
            // Brand, then a dot and a TLD somewhere ahead in the same token.
            $pattern = '/\b' . preg_quote((string) $brand, '/') . '(?:[.\-][a-z0-9\-]+)*\.[a-z]{2,12}\b/i';
            preg_match_all($pattern, $haystack, $matches);
            foreach (array_unique($matches[0] ?? []) as $candidate) {
                $candidate = strtolower(rtrim($candidate, '.'));
                if ($this->matchesDomain($candidate, $owned)) {
                    continue; // the real thing
                }
                // Not a host: a file name. Reports routinely name attachments and
                // tickets this way ("phpmyadmin-pma-01-08-....tar.gz"), and those
                // are the senders we least want to ban.
                if (preg_match(
                    '/\.(?:tar|gz|tgz|bz2|xz|zip|7z|rar|pgp|gpg|sig|asc|p7s|pem|'
                    . 'png|jpe?g|gif|svg|webp|ico|css|js|mjs|json|xml|php|py|sh|rb|pl|'
                    . 'html?|txt|md|pdf|docx?|xlsx?|pptx?|csv|log|patch|diff|eml)$/i',
                    $candidate
                ) === 1) {
                    continue;
                }
                if (strlen($candidate) <= strlen((string) $brand) + 2) {
                    continue; // no TLD to speak of
                }
                $found[$candidate] = (string) $brand;
            }
        }

        return array_slice($found, 0, 3, true);
    }

    /** @return list<string> */
    private function repList(string $key): array
    {
        $value = $this->rep[$key] ?? [];

        /** @var list<string> $out */
        $out = is_array($value) ? array_values(array_map(self::stringify(...), $value)) : [];

        return $out;
    }

    private static function stringify(mixed $value): string
    {
        return (string) $value;
    }

    /**
     * Download a feed at most once a day, returning the text to parse.
     *
     * A stale cache beats no cache: if the refresh fails the last known copy is
     * used with a warning, and only having neither is fatal. $isValid guards
     * against caching an error page that answered 200.
     *
     * @param callable(string): bool $isValid
     */
    private function cachedFeed(string $url, string $cache, string $label, callable $isValid): string
    {
        $cached = is_file($cache) ? (string) file_get_contents($cache) : '';
        if ($this->offline) {
            if ($cached === '') {
                $this->quitError('--offline was given but there is no cached ' . $label
                    . ' at ' . $cache);
            }

            return $cached;
        }

        // An empty or unparseable cache counts as missing, whatever its mtime.
        // Trusting the timestamp alone lets a truncated write pin an empty feed
        // for a day, silently disabling the check.
        $usable = $cached !== '' && $isValid($cached);
        $age = $usable && is_file($cache) ? time() - (int) filemtime($cache) : PHP_INT_MAX;
        if ($age < self::FEED_MAX_AGE) {
            return $cached;
        }

        $result = $this->request('GET', $url, null, false);
        $fresh = $result['http'] === 200 && $result['error'] === '' && $isValid($result['body'])
            ? $result['body']
            : null;

        if ($fresh === null) {
            if (! $usable) {
                $this->quitError('No ' . $label . ': download failed and no cache exists at ' . $cache);
            }
            fwrite(STDERR, 'warning: could not refresh the ' . $label . ', using the cache' . PHP_EOL);

            return $cached;
        }

        if ($fresh !== $cached) {
            fwrite(STDERR, $label . ' updated: ' . $this->countEntries($cached) . ' -> '
                . $this->countEntries($fresh) . ' entries' . PHP_EOL);
        }
        $dir = dirname($cache);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($cache, $fresh);

        return $fresh;
    }

    /**
     * Non-comment, non-blank lines of a feed.
     *
     * @return list<string>
     */
    private function feedEntries(string $feed): array
    {
        $out = [];
        foreach (explode("\n", $feed) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    private function countEntries(string $feed): int
    {
        return count($this->feedEntries($feed));
    }

    /** @return array<string, true> */
    private function loadDisposableSet(): array
    {
        $feed = $this->cachedFeed(
            self::DISPOSABLE_URL,
            $this->disposableCache,
            'disposable domain list',
            fn (string $body): bool => $this->countEntries($body) > 100
        );

        $set = [];
        foreach ($this->feedEntries($feed) as $domain) {
            $set[strtolower($domain)] = true;
        }

        return $set;
    }

    /** @return list<array{0: int, 1: int}> sorted [first, last] IPv4 ranges */
    private function loadBlockedRanges(): array
    {
        $feed = $this->cachedFeed(
            self::FIREHOL_URL,
            $this->fireholCache,
            'firewall blocklist',
            fn (string $body): bool => $this->countEntries($body) > 100
        );

        $ranges = [];
        foreach ($this->feedEntries($feed) as $entry) {
            $parts = explode('/', $entry, 2);
            $first = ip2long($parts[0]);
            if ($first === false) {
                continue; // IPv6 or malformed: the level1 set is IPv4 only.
            }
            $bits = isset($parts[1]) ? (int) $parts[1] : 32;
            if ($bits < 0 || $bits > 32) {
                continue;
            }
            $size = 1 << 32 - $bits;
            $ranges[] = [$first, $first + $size - 1];
        }
        sort($ranges);

        return $ranges;
    }

    /** Is this IPv4 address inside the firewall blocklist? */
    private function isBlockedIp(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false || $this->blockedRanges === []) {
            return false;
        }
        // Ranges are sorted and non-overlapping enough for a linear scan; a run
        // checks a handful of addresses, not thousands.
        foreach ($this->blockedRanges as [$first, $last]) {
            if ($long < $first) {
                return false; // sorted: nothing further can match
            }
            if ($long <= $last) {
                return true;
            }
        }

        return false;
    }

    private function isDisposable(string $domain): bool
    {
        if ($this->disposableSet === []) {
            return false;
        }
        // Exact match, then walk parent labels so a.b.example.com hits example.com.
        $candidate = $domain;
        while (str_contains($candidate, '.')) {
            if (isset($this->disposableSet[$candidate])) {
                return true;
            }
            $candidate = substr($candidate, (int) strpos($candidate, '.') + 1);
        }

        return isset($this->disposableSet[$candidate]);
    }

    private function listId(): string
    {
        return str_replace('@', '.', $this->list);
    }

    /**
     * Every HTTP call goes through here: Postorius requests and the upstream
     * feed alike. Session requests share the cookie jar; the feed does not.
     *
     * Redirects are followed for GET only. With CURLOPT_CUSTOMREQUEST the method
     * is reused for every hop, so following a redirect after a POST would re-send
     * that POST — a ban added twice, or a moderation submitted twice. Writes
     * therefore see the endpoint's own status, and 302 is the success signal.
     *
     * @param array<string, string>|null $form
     *
     * @return array{http: int, body: string, url: string, error: string, location: string}
     */
    private function request(
        string $method,
        string $url,
        array |null $form = null,
        bool $withSession = true,
        bool $idempotent = false
    ): array {
        // A POST is a write. If the reply is lost we cannot tell whether the ban
        // was added or the message moderated, so retrying could double-submit.
        // Only GETs retry, unless the caller says the request is safe to repeat.
        $attempts = $method === 'GET' || $idempotent ? self::HTTP_ATTEMPTS : 1;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->throttle();
            $result = $this->attempt($method, $url, $form, $withSession);
            // Retry transport failures, and a 200 that arrived with no body: a
            // truncated transfer looks exactly like that, and it is what the
            // large /?raw responses do. A 4xx/5xx is an answer and is never
            // retried, so a write can never be repeated by mistake.
            $truncated = $idempotent && $result['error'] === '' && $result['http'] === 200
                && trim($result['body']) === '';
            if ($result['error'] === '' && ! $truncated) {
                return $result;
            }
            if ($attempt === $attempts) {
                return $result;
            }
            usleep(500_000 * $attempt);
        }

        return $result;
    }

    /**
     * The header set every request sends.
     *
     * A browser navigation's headers, not a bare script's: the server drops
     * requests that look automated, and the raw-message endpoint is the most
     * sensitive of them. Accept-Encoding is deliberately absent so that
     * CURLOPT_ENCODING decides it.
     *
     * @return list<string>
     */
    private function browserHeaders(): array
    {
        return [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ];
    }

    /** Whether the progress counter can rewrite itself, i.e. stderr is a TTY. */
    private function stderrIsTerminal(): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDERR);
        }
        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDERR);
        }

        return false;
    }

    /** Whether a signal argues for the sender; see EVIDENCE_FOR_SENDER. */
    private function isEvidenceForSender(string $signal): bool
    {
        foreach (self::EVIDENCE_FOR_SENDER as $name) {
            if (str_starts_with($signal, $name)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a signal label is noise rather than evidence; see NOISE_SIGNALS. */
    private function isNoiseLabel(string $signal): bool
    {
        foreach (self::NOISE_SIGNALS as $noise) {
            if (str_starts_with($signal, $noise)) {
                return true;
            }
        }

        return false;
    }

    /** Keeps the gap between requests, shared by both request paths. */
    private function throttle(): void
    {
        if ($this->lastRequestAt > 0) {
            $wait = self::REQUEST_DELAY_US - (int) ((microtime(true) - $this->lastRequestAt) * 1e6);
            if ($wait > 0) {
                usleep($wait);
            }
        }
        $this->lastRequestAt = microtime(true);
    }

    /**
     * One HTTP exchange, no retries.
     *
     * @param array<string, string>|null $form
     *
     * @return array{http: int, body: string, url: string, error: string, location: string}
     */
    private function attempt(string $method, string $url, array |null $form = null, bool $withSession = true): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            $this->quitError('Could not initialise curl.');
        }
        $headers = $this->browserHeaders();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $method === 'GET',
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERAGENT => $this->userAgent,
            // Let curl negotiate and decode compression itself.
            CURLOPT_ENCODING => '',
        ];

        if ($withSession && $this->cookieJar !== '') {
            $options[CURLOPT_COOKIEJAR] = $this->cookieJar;
            $options[CURLOPT_COOKIEFILE] = $this->cookieJar;
        }
        if ($form !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Referer: ' . $url;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        // No curl_close(): the handle is a CurlHandle object since PHP 8.0 and is
        // freed by refcounting. curl_close() became a no-op then and is deprecated
        // in PHP 8.5, so calling it would only add a notice.

        return [
            'http' => $http,
            'body' => is_string($body) ? $body : '',
            'url' => $effective,
            'error' => $error,
            'location' => $location,
        ];
    }

    /**
     * @param array<string, string>|null $form
     *
     * @return array{http: int, body: string, url: string, location: string}
     */
    private function http(string $method, string $path, array |null $form = null): array
    {
        $result = $this->request($method, $this->base . $path, $form);
        if ($result['error'] !== '') {
            throw new HttpFailure('HTTP error for ' . $path . ': ' . $result['error']);
        }

        return [
            'http' => $result['http'],
            'body' => $result['body'],
            'url' => $result['url'],
            'location' => $result['location'],
        ];
    }

    /**
     * True when a write was accepted. Postorius answers 302 on success; a 200 is
     * a re-rendered form (validation error) or the login page after a dead
     * session, so it must not be read as success.
     *
     * @param array{http: int, location: string} $result
     */
    private function writeSucceeded(array $result, string $what): bool
    {
        // 302 back to the login page means the session died mid-run.
        if ($result['http'] === 302 && ! str_contains($result['location'], '/accounts/login')) {
            return true;
        }
        // Any other status is a failure of THIS write, not of the run: one 403 or
        // 500 must not abandon the remaining messages with no summary and no ledger.
        if ($result['http'] !== 0) {
            fwrite(STDERR, 'ERROR: ' . $what . ' failed (HTTP ' . $result['http'] . ').' . PHP_EOL);

            return false;
        }

        return false;
    }

    private function csrfFrom(string $html): string
    {
        if (preg_match('/name="csrfmiddlewaretoken"\s+value="([^"]+)"/', $html, $matches)) {
            return $matches[1];
        }

        throw new HttpFailure('No CSRF token in the page; the session is probably not authenticated.');
    }

    /**
     * A private temporary file for this run, deleted when the process ends.
     *
     * tempnam() creates the file with 0600 permissions; it is registered for
     * cleanup so a run leaves nothing behind.
     */
    private function temporaryFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            $this->quitError('Could not create a temporary file in ' . sys_get_temp_dir());
        }
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /** Removes the temporary files, including after quitError() exits. */
    public function __destruct()
    {
        foreach ($this->temporaryFiles as $path) {
            if (! is_file($path)) {
                continue;
            }

            unlink($path);
        }
    }

    private function login(): void
    {
        $this->cookieJar = $this->temporaryFile('mailman-mod');

        $page = $this->http('GET', '/accounts/login/?next=/postorius/lists/');
        if ($page['http'] !== 200) {
            $this->quitError('Could not reach the login page (HTTP ' . $page['http'] . ').');
        }
        $result = $this->http('POST', '/accounts/login/?next=/postorius/lists/', [
            'csrfmiddlewaretoken' => $this->csrfFrom($page['body']),
            'login' => $this->user,
            'password' => $this->password,
            'remember' => 'on',
        ]);

        // allauth redirects on success and re-renders the form (200) on failure.
        if ($result['http'] === 302 && ! str_contains($result['location'], '/accounts/login')) {
            return;
        }

        $this->quitError('Login failed for user "' . $this->user . '".');
    }

    /**
     * @return list<array{id: int, subject: string, sender: string, reason: string, size: string, date: string}>
     */
    private function fetchHeld(): array
    {
        // The listing is a single request whatever the count, and the per-domain
        // totals behind the REPEAT signal must come from the whole queue, not from
        // the slice --limit asks us to score. So always read the queue in full.
        $path = '/postorius/lists/' . $this->listId() . '/held_messages?count=' . self::HELD_MAX;
        $result = $this->http('GET', $path);
        if ($result['http'] !== 200) {
            $this->quitError('GET ' . $path . ' returned HTTP ' . $result['http']);
        }

        $rows = [];
        $skipped = 0;
        // An empty parse is indistinguishable from an empty queue, so a template
        // change would look like "nothing held" instead of failing. `name="choices"`
        // cannot be that marker: it is rendered per held message, so a clean queue
        // has none and the normal outcome of an unattended run would exit 1.
        // The form that wraps the table is stable either way.
        $sawModerationForm = str_contains($result['body'], 'held_messages/moderate')
            || str_contains($result['body'], 'held_messages');
        if (! $sawModerationForm) {
            $this->quitError('The held-messages page is not the expected view; the template'
                . ' changed and this parser needs updating. Nothing was read.');
        }

        preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $result['body'], $tableRows);
        foreach ($tableRows[1] as $row) {
            if (! preg_match('/name="choices"\s+value="(\d+)"/', $row, $idMatch)) {
                $skipped++;
                continue;
            }
            preg_match_all('#<td[^>]*>(.*?)</td>#s', $row, $cells);
            $text = array_map($this->cellText(...), $cells[1]);

            // Columns are positional, so a missing cell silently shifts every
            // field: a row parsed as 5 cells puts the date where the size belongs
            // and the sender where the reason belongs. Fail loudly instead.
            if (count($text) < 6) {
                fwrite(STDERR, PHP_EOL . 'warning: held row ' . $idMatch[1] . ' has '
                    . count($text) . ' cells, expected 6; skipped rather than mis-parsed' . PHP_EOL);
                continue;
            }

            $rows[] = [
                'id' => (int) $idMatch[1],
                'subject' => $this->untangle($text[1] ?? ''),
                'sender' => $this->addressOf($text[2] ?? ''),
                'reason' => $text[3] ?? '',
                'size' => $text[4] ?? '',
                'date' => $text[5] ?? '',
            ];
        }

        // Only a page that actually carries selection boxes but parsed no row is a
        // template break. Without this distinction a genuinely empty queue - the
        // header row alone - would abort every run.
        if ($rows === [] && $skipped > 0 && str_contains($result['body'], 'name="choices"')) {
            $this->quitError('The held-messages page has ' . $skipped . ' rows and selection'
                . ' boxes, but none parsed. The template changed. Nothing was read.');
        }

        return $rows;
    }

    private function cellText(string $cell): string
    {
        $text = html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** A short subject is rendered twice, back to back; collapse the repeat. */

    /**
     * The template renders a short subject twice, back to back. Collapse that
     * doubling, but only when it is an exact repeat separated by whitespace —
     * a genuine subject like "abc abc" would otherwise lose half of itself, and
     * the scorer prefers the parsed subject whenever a body could be fetched.
     */
    private function untangle(string $subject): string
    {
        if (preg_match('/^(.+?\S)\s+\1$/u', $subject, $matches) === 1) {
            return $matches[1];
        }

        return $subject;
    }

    /**
     * One held message, as the JSON endpoint reports it.
     *
     * The /?raw export is not usable here: responses of a few hundred kilobytes
     * die mid-transfer against this server ("HTTP/2 stream 1 was not closed
     * cleanly", or "SSL_read: unexpected eof"), while a browser reading the same
     * URL is fine. The JSON endpoint answers a few kilobytes for every message
     * and already carries what the scorer needs: the headers as a block and the
     * decoded body.
     *
     * Every failure is named for what it is, so a transport problem is never
     * mistaken for an empty message.
     *
     * @return array{raw: string, attachments: list<string>}|null
     */
    private function fetchMessage(int $id): array|null
    {
        $path = '/postorius/api/list/' . $this->listId() . '/held_message/' . $id . '/';
        $result = $this->request('GET', $this->base . $path);

        if ($result['error'] !== '') {
            return $this->unscorable($id, 'transfer failed: ' . $result['error']);
        }
        if ($result['http'] === 302) {
            return $this->unscorable($id, 'redirected to ' . $result['location']
                . ' (the session expired mid-run)');
        }
        if ($result['http'] !== 200) {
            return $this->unscorable($id, 'HTTP ' . $result['http']);
        }

        $decoded = json_decode($result['body'], true);
        if (! is_array($decoded)) {
            return $this->unscorable($id, str_starts_with(ltrim($result['body']), '<')
                ? 'HTML instead of JSON (session or stale id)'
                : 'response was not JSON');
        }
        if (! isset($decoded['msg']) || ! is_array($decoded['msg'])) {
            return $this->unscorable($id, 'JSON carried no message');
        }

        $headers = (string) ($decoded['msg']['headers'] ?? '');
        $body = (string) ($decoded['msg']['body'] ?? '');
        if (trim($headers) === '') {
            return $this->unscorable($id, 'message has no headers');
        }

        $attachments = [];
        foreach (($decoded['attachments'] ?? []) as $attachment) {
            if (! is_array($attachment) || ! isset($attachment[1])) {
                continue;
            }

            $attachments[] = (string) $attachment[1];
            // A report or a lure is sometimes the attachment itself. Readable
            // text is pulled in when there is little body to judge; binaries are
            // never downloaded.
            if (
                preg_match('/\.(html?|txt|eml|md|csv)$/i', (string) $attachment[1]) !== 1
                || ! isset($attachment[0])
                || mb_strlen(trim($body)) >= 200
            ) {
                continue;
            }

            $text = $this->fetchAttachmentText((string) $attachment[0]);
            if ($text === '') {
                continue;
            }

            $body .= "\n" . $text;
        }

        // The body from the API is already decoded text, so the assembly must not
        // advertise the original multipart structure: a parser reading it would
        // look for parts that are not there and find no text at all.
        // Folded continuation lines must go too: a Content-Type wrapped as
        //   Content-Type: multipart/alternative;
        //       boundary="===="
        // leaves " boundary=..." behind, which the parser then folds onto the
        // previous header -- turning a subject into "도메인 갱신 안내 boundary="====".
        $stripped = (string) preg_replace(
            '/^(content-type|content-transfer-encoding|mime-version)\s*:.*\n(?:[ \t].*\n)*/mi',
            '',
            str_replace(["\r\n", "\r"], "\n", $headers)
        );

        return [
            'raw' => $stripped . "\n\n" . $body,
            'attachments' => $attachments,
        ];
    }

    /**
     * Text of one readable attachment, or '' when it cannot be read.
     *
     * Only ever called for html/txt/eml/md/csv, and only when the message body is
     * too short to judge on its own.
     */
    private function fetchAttachmentText(string $path): string
    {
        // The API returns a path such as "/mailman3/postorius/...". Join on the
        // origin rather than editing the string: str_replace() of "/mailman3" was
        // a no-op on a differently-mounted instance and produced a wrong URL.
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $this->base);
        $url = str_starts_with($path, 'http') ? $path : $origin . $path;
        $result = $this->request('GET', $url);
        if ($result['http'] !== 200 || $result['error'] !== '' || str_starts_with(ltrim($result['body']), '<?xml')) {
            return '';
        }

        return $this->plainText($result['body']);
    }

    private function unscorable(int $id, string $reason): null
    {
        $this->unscorable[] = $id . ' (' . $reason . ')';
        fwrite(STDERR, PHP_EOL . 'note: message ' . $id . ' not scored in full: ' . $reason . PHP_EOL);

        return null;
    }

    /** @return list<string> */

    /**
     * Bans that apply to this list only.
     *
     * @return list<string>
     */
    private function fetchBans(): array
    {
        return $this->parseBanPage('/postorius/lists/' . $this->listId() . '/bans/', 'ban list');
    }

    /**
     * Bans that apply to every list on the server.
     *
     * @return list<string>
     */
    private function fetchGlobalBans(): array
    {
        // The ban list is a correctness input, not a scoring slice, so --limit must
        // not bound it: reading 10 bans and concluding the rest are clean makes an
        // already-banned sender look bannable, and the re-ban then fails.
        $bans = [];
        for ($page = 1; $page <= self::BANS_MAX_PAGES; $page++) {
            $result = $this->http(
                'GET',
                '/postorius/bans/?count=' . self::BANS_PER_PAGE . '&page=' . $page
            );
            $chunk = $this->parseBanPageBody($result['body'], 'site-wide ban list');
            $bans = array_merge($bans, $chunk);
            if (count($chunk) < self::BANS_PER_PAGE) {
                break;
            }
        }

        $bans = array_values(array_unique($bans));
        $ceiling = self::BANS_PER_PAGE * self::BANS_MAX_PAGES;
        if (count($bans) >= $ceiling) {
            fwrite(STDERR, 'warning: site-wide ban list truncated at ' . $ceiling
                . ' entries; raise BANS_MAX_PAGES' . PHP_EOL);
        }

        return $bans;
    }

    /** @return list<string> */
    private function parseBanPage(string $path, string $label): array
    {
        $result = $this->http('GET', $path);
        if ($result['http'] !== 200) {
            $this->quitError('Could not read the ' . $label . ' (HTTP ' . $result['http'] . ').');
        }

        return $this->parseBanPageBody($result['body'], $label);
    }

    /**
     * Ban entries out of a rendered page.
     *
     * Entries appear as a plain address, a "^regexp", or a "*@domain" wildcard.
     * The pattern must not require an "@": a ban written as "^mailer-daemon" or
     * "^.*@(spam|abuse)\." has no address to match, and a ban the tool cannot see
     * is a ban it will try to issue twice.
     *
     * @return list<string>
     */
    private function parseBanPageBody(string $body, string $label): array
    {
        if (! str_contains($body, 'csrfmiddlewaretoken')) {
            $this->quitError('The ' . $label . ' page has no CSRF field; the template changed or the'
                . ' session is not authenticated. Nothing was read.');
        }

        preg_match_all('#<td[^>]*>\s*([^\s<>]{3,})\s*</td>#', $body, $matches);
        $bans = [];
        foreach ($matches[1] as $value) {
            $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Table chrome and counters share the cell shape; a ban always
            // contains an address or starts with the regexp marker.
            if (! str_contains($value, '@') && ! str_starts_with($value, '^')) {
                continue;
            }
            $bans[] = $value;
        }

        return array_values(array_unique($bans));
    }

    /** Header value with the "Name:" prefix the parser keeps in its string form. */
    private function headerValue(FileMessage $message, string $name, int $offset = 0): string
    {
        $header = $message->header($name, $offset);
        if ($header === null) {
            return '';
        }
        $value = (string) $header;

        return trim((string) preg_replace('/^' . preg_quote($name, '/') . '\s*:\s*/i', '', $value));
    }

    /** @return list<string> Public sending addresses from the Received chain, in hop order. */
    private function receivedIps(FileMessage $message): array
    {
        $ips = [];
        for ($offset = 0; $offset < 50; $offset++) {
            $header = $message->header('received', $offset);
            if ($header === null) {
                break;
            }
            $value = (string) $header;
            preg_match_all('/\[([0-9a-f:.]+)\]|\(([0-9a-f:]{2,})\)/i', $value, $matches);
            foreach (array_merge($matches[1], $matches[2]) as $ip) {
                if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    continue;
                }
                // Loopback and link-local hops are the receiving stack's own
                // internal handoffs, not a reputation signal.
                $publicFlags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
                if (filter_var($ip, FILTER_VALIDATE_IP, $publicFlags) === false) {
                    continue;
                }
                if (in_array($ip, $ips, true)) {
                    continue;
                }

                $ips[] = $ip;
            }
        }

        return $ips;
    }

    /** @return array{spf: string|null, dkim: string|null, dmarc: string|null} */

    /**
     * SPF, DKIM and DMARC verdicts.
     *
     * Two notations are in play. "Authentication-Results" carries them as
     * key=value ("spf=pass"), while "Received-SPF" leads with the verdict itself
     * ("Received-SPF: pass (mail.example.net: domain of ...)"). This queue mostly
     * has the second form and no Authentication-Results at all, so reading only
     * key=value silently reported no result for every message.
     *
     * @return array{spf: string|null, dkim: string|null, dmarc: string|null}
     */
    private function authResults(FileMessage $message): array
    {
        $auth = $this->headerValue($message, 'authentication-results');
        $receivedSpf = $this->headerValue($message, 'received-spf');
        $blob = $auth . ' ' . $receivedSpf;

        $out = ['spf' => null, 'dkim' => null, 'dmarc' => null];
        foreach (['spf', 'dkim', 'dmarc'] as $key) {
            if (preg_match('/\b' . $key . '=(\w+)/i', $blob, $matches) !== 1) {
                continue;
            }

            $out[$key] = strtolower($matches[1]);
        }

        // "pass (mail.example.net: domain of ...)", with the header name already
        // stripped. The leading verdict is the whole answer.
        if ($out['spf'] === null && preg_match(
            '/^\s*(?:[\w-]+:\s*)?(pass|fail|softfail|neutral|none|permerror|temperror|unknown)\b/i',
            trim($receivedSpf),
            $matches
        ) === 1) {
            $out['spf'] = strtolower($matches[1]);
        }

        return $out;
    }

    /**
     * Readable text from an HTML part.
     *
     * Style and script blocks are removed first: CSS declares properties like
     * "overflow" and "visibility", which match the abuse vocabulary and made
     * legitimate newsletters look like memory-corruption reports.
     */
    private function plainText(string $html): string
    {
        $text = (string) preg_replace('#<(style|script)[^>]*>.*?</\1>#is', ' ', $html);
        $text = (string) preg_replace('#<[^>]+>#', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /** @return list<string> */
    private function bodyUrls(string $body): array
    {
        preg_match_all('#\bhttps?://[^\s<>"\')\]]+#i', $body, $matches);

        /** @var list<string> $urls */
        $urls = array_values(array_unique($matches[0] ?? []));

        return $urls;
    }

    /**
     * The address out of a queue cell, which may be "Name <a@b>" or a bare
     * address. Never slice on "@" directly: for "Jane <jane@example.com>" that
     * yields "example.com>", which breaks both the domain and the ban comparison.
     */
    private function addressOf(string $value): string
    {
        if (preg_match('/<([^<>@\s]+@[^<>\s]+)>/', $value, $matches) === 1) {
            return strtolower(trim($matches[1]));
        }
        if (preg_match('/[^\s<>@]+@[^\s<>@]+/', $value, $matches) === 1) {
            return strtolower(trim($matches[0]));
        }

        return strtolower(trim($value));
    }

    private function domainOf(string $email): string
    {
        $address = $this->addressOf($email);
        $at = strrpos($address, '@');

        return $at === false ? '' : substr($address, $at + 1);
    }

    /** @param list<string> $domains */
    private function matchesDomain(string $domain, array $domains): bool
    {
        foreach ($domains as $candidate) {
            $candidate = strtolower($candidate);
            if ($candidate !== '' && ($domain === $candidate || str_ends_with($domain, '.' . $candidate))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $domainCounts
     * @param list<string>       $attachmentNames
     *
     * @return array{
     *     signals: list<string>, veto: string|null, domain: string,
     *     sender_parsed: string, sender_from_header: bool,
     *     accept: bool, ips: list<string>, hint_verdict: string,
     *     foreign_subject: bool, void_message: bool, deciding_label: string,
     *     parsed_subject: string,
     * }
     */
    private function score(
        int $id,
        string $sender,
        string $subject,
        string $raw,
        array $domainCounts,
        array $attachmentNames = []
    ): array {
        $signals = [];
        // Reasons only. There is no score: what decides a verdict is the section
        // a policy line lives in, plus the explicit hints below. A numeric tally
        // alongside that was a second, contradictory decision rule.
        $add = static function (string $why) use (&$signals): void {
            $signals[] = $why;
        };

        // FileMessage parses untrusted mail and throws on input it cannot read.
        // A parse failure must not abort the run: fall back to the queue metadata.
        $message = null;
        if ($raw !== '') {
            try {
                $message = new FileMessage($raw);
                // Touch the lazily parsed parts so a malformed body fails here.
                $address = $message->from();
                $address?->email();
                $message->subject();
                $message->text();
            } catch (Throwable $exception) {
                fwrite(STDERR, 'warning: could not parse request ' . $id . ': ' . $exception->getMessage() . PHP_EOL);
                $message = null;
            }
        }
        $senderFromHeader = false;
        if ($message !== null) {
            // Prefer the parsed headers over the table cells.
            $address = $message->from();
            if ($address !== null && $address->email() !== '') {
                $sender = strtolower($address->email());
                $senderFromHeader = true;
            }
            $parsedSubject = $message->subject();
            if (is_string($parsedSubject) && $parsedSubject !== '') {
                $subject = $parsedSubject;
            }
        }

        $domain = $this->domainOf($sender);
        $ips = $message !== null ? $this->receivedIps($message) : [];
        $body = '';
        $attachments = $attachmentNames;
        if ($message !== null) {
            $body = (string) $message->text();
            // html() and attachments() parse lazily and throw on malformed MIME.
            // Outside the try above that would end the run on one bad message.
            try {
                if (trim($body) === '') {
                    $body = $this->plainText((string) $message->html());
                }
                // Names only: the payload is never fetched or written to disk.
                foreach ($message->attachments() as $attachment) {
                    $attachments[] = (string) $attachment->filename();
                }
            } catch (Throwable $malformed) {
                fwrite(STDERR, PHP_EOL . 'warning: message ' . $id . ' has unreadable MIME: '
                    . $malformed->getMessage() . PHP_EOL);
            }
        }
        // Accents folded: the policy is ASCII but the queue is not. The From
        // display name is included because it is part of what a sender claims:
        // "РауРаl" in Cyrillic lives there, not in the subject or body, so
        // leaving it out let a PayPal impersonation read as an ordinary notice.
        $displayName = '';
        if ($message !== null) {
            $from = $message->from();
            $displayName = $from !== null ? (string) $from->name() : '';
        }
        // URLs are removed before matching, on both sides of the decision. A link
        // slug like ".../invoice-and-soa-import-xss" would otherwise match a Ban
        // term while the "xss" in the same URL could not protect, because the veto
        // haystack already had URLs stripped.
        // URLs are removed BEFORE any folding: folding "&" to " and " inside a
        // query string turned "?a=1&b=2" into text that could match a policy term.
        $withoutUrls = (string) preg_replace('#https?://\S+#i', ' ', $subject . "\n" . $displayName . "\n" . $body);
        $haystack = $this->foldAccents($withoutUrls);
        $rawText = $this->foldAccents($subject . "\n" . $displayName . "\n" . $body);
        $veto = null;
        $accept = false;
        // Known here rather than at the end, because the bulk-header rule has to
        // respect it: newsletter plumbing on a domain we trust is not evidence.
        $isAllowlisted = $this->matchesDomain($domain, $this->repList('allow_domains'));

        // Nothing to read but a file to open. Genuine mail to a mailing list
        // carries an argument in the body; a bare attachment is a delivery
        // vehicle for malware or a credential-harvesting document.
        // A readable attachment is the message itself: reports are sometimes sent
        // as an .html or .txt file, and those must not be treated as a payload.
        // A .gpg or .pgp file is a signature or an encrypted disclosure, which is
        // deliberate security practice: no spammer signs or encrypts to a list.
        $payloadOnly = array_filter(
            $attachments,
            static fn (string $name): bool => preg_match('/\.(html?|txt|eml|md|csv|gpg|pgp|asc|sig)$/i', $name) !== 1
        );
        if ($message !== null && $payloadOnly !== [] && mb_strlen(trim(strip_tags($body))) < 20) {
            $add('no readable body, only ' . count($payloadOnly) . ' attachment(s): '
                . mb_strimwidth(implode(', ', $payloadOnly), 0, 60));
        }

        // Evidence for the sender, not against: a signed or encrypted attachment is
        // what a careful researcher sends, so it argues against a verdict.
        $signed = array_filter(
            $attachments,
            static fn (string $name): bool => preg_match('/\.(gpg|pgp|asc|sig)$/i', $name) === 1
        );
        if ($signed !== []) {
            $add('signed or encrypted attachment: ' . mb_strimwidth(implode(', ', $signed), 0, 50));
        }

        // "Empty" means nothing arrived at all: no readable body AND no attachment
        // of any kind. A message carrying only an encrypted attachment is not
        // empty -- the content is there, deliberately unreadable to us. Guarded on
        // $message: a body we failed to fetch is a transport problem, not an empty
        // message.
        $empty = $message !== null && trim(strip_tags($body)) === '' && $attachments === [];

        // Term registry. Every matching line contributes its own
        // short reason, so the output reads like the policy file. At most two
        // lines score, highest first: one incidental word should not add up.
        $matchedGroups = [];
        foreach ($this->termGroups as $group) {
            if (preg_match($this->groupPattern($group['terms']), $haystack) !== 1) {
                continue;
            }

            $matchedGroups[] = $group;
        }
        // Scam detection runs over every match, before the display list is
        // trimmed: otherwise an extortion mail that also trips two phishing lines
        // loses its Scam status and gets protected instead of banned.
        $isScam = false;
        foreach ($matchedGroups as $group) {
            if ($group['verdict'] === self::VERDICT_BAN && str_contains($group['section'], 'scam')) {
                $isScam = true;
                break;
            }
        }
        // Rank before slicing. Slicing first kept the two earliest matches, so with
        // Void, Bulk and Ban all matching, the Ban was discarded and the message
        // discarded instead of banned.
        usort($matchedGroups, static fn (array $a, array $b): int => $b['verdict'] <=> $a['verdict']);
        $matchedGroups = array_slice($matchedGroups, 0, 2);

        $hintVerdict = '';
        $hintRank = 0;
        $decidingLabel = '';
        // Any policy line that already decides an action. The generic security
        // wording only protects a message when no such line matched, otherwise a
        // single incidental word cancels a whole fraud rule.
        $actionableMatched = false;
        foreach ($matchedGroups as $group) {
            $add($group['label']);
            $actionableMatched = true;

            // A Ban line is decisive: impersonating a bank, an invoice or a person
            // is fraud, and a message that also happens to carry newsletter
            // plumbing is still fraud. Bulk only decides Discard when no Ban line
            // matched at all.
            $rank = $group['verdict'] === self::VERDICT_DISCARD
                ? self::RANK_BULK
                : self::RANK_BAN;
            if ($rank <= $hintRank) {
                continue;
            }

            $hintRank = $rank;
            $hintVerdict = $group['verdict'];
            $decidingLabel = $group['label'];
        }

        // This list works in English. A CJK subject has never been a genuine
        // report here, and the sender is told why in the notification.
        // Only decides when nothing stronger matched: a Korean domain-expiry scam
        // is the same fraud as its German twin (254) and must Ban, not be excused
        // for being in another script.
        // Only when it actually decides: a Ban line still wins, otherwise the
        // plan said Ban while act() skipped the ban and sent the language notice.
        $foreignSubject = false;
        if ($this->hasCjkScript($subject)) {
            $add('subject is written in a non-Latin script');
            if ($hintRank < self::RANK_BAN) {
                $hintRank = self::RANK_LANGUAGE;
                $hintVerdict = self::VERDICT_DISCARD;
                $decidingLabel = 'subject is written in a non-Latin script';
                $foreignSubject = true;
            }
        }

        // A void message: no text, no attachment. Dropped, never banned: the
        // sender has demonstrated nothing worth banning, and an empty body is as
        // likely a broken client as intent.
        $voidMessage = false;
        if ($empty) {
            $add('message is empty: no readable text' . ($attachments === [] ? ' and no attachment' : ''));
            $hintRank = self::RANK_EMPTY;
            $hintVerdict = self::VERDICT_DISCARD;
            $decidingLabel = 'message is empty';
            $voidMessage = true;
        }

        // Bulk-mailer plumbing. A person reporting a vulnerability, or replying on
        // a list, does not arrive with unsubscribe headers and an ESP feedback id;
        // a newsletter blast does. Measured on this queue: 29 of 129 carry
        // List-Unsubscribe, 12 List-Post. They route to Discard
        // rather than Ban, so a real advisory sent through a mailing list is still
        // protected by the vocabulary veto above.
        $bulkHeaders = 0;
        // Newsletter plumbing only counts for a sender we do not already trust.
        // A real correspondent on a provider like Proton or Gmail has these added
        // by their mailbox provider, so they say nothing about the sender: a
        // signed disclosure from proton.me was being penalised for a
        // Feedback-ID that Proton itself injects.
        $trustedSender = $isAllowlisted
            || $this->matchesDomain($domain, $this->repList('shared_domains'));
        if ($message !== null && ! $trustedSender) {
            foreach (['list-unsubscribe', 'list-post'] as $header) {
                if ($this->headerValue($message, $header) === '') {
                    continue;
                }
                $add('bulk-mailer header ' . $header);
                $bulkHeaders++;
            }
        }
        // On a domain we trust, newsletter plumbing proves nothing: CISA's case
        // notifications carry that plumbing like any bulk sender does.
        if ($bulkHeaders > 0 && ! $isAllowlisted && $hintRank < self::RANK_BULK) {
            // Bulk-mail plumbing decides Discard, and outranks neither a Ban rule
            // nor a message that is empty or in another script.
            $hintRank = self::RANK_BULK;
            $hintVerdict = self::VERDICT_DISCARD;
            $decidingLabel = 'bulk-mailer header';
        }

        // Protective vocabularies. A scam template outranks them, because
        // extortion mail talks about security on purpose. An unconditional term
        // (XSS, CVE, disclosure) protects whatever else the message contains; a
        // conditional one only when nothing worse matched.
        //
        // This applies to every sender, allowlisted or not. Skipping it for a
        // trusted domain let a Ban rule fire on a real report: an XSS write-up
        // from certik.com was banned because the word "wise" appeared inside
        // "Fix-wise", and the ban rule beat the XSS vocabulary.
        if ($veto === null) {
            // Links are stripped for this check: tracking URLs carry fragments
            // like "#security@phpmyadmin.net", which would otherwise rescue any
            // spam that happens to mention the list address.
            $vetoHaystack = (string) preg_replace('#https?://\S+#i', ' ', $haystack);
            foreach ($this->vetoGroups as $group) {
                if (preg_match($this->groupPattern($group['terms']), $vetoHaystack) !== 1) {
                    continue;
                }
                // The conditional group is the one that sales mail can trip, so it
                // is skipped when a policy line already decided the verdict - or
                // when the message reads as an extortion template, whose whole
                // design is to talk about security.
                if ($group['conditional'] && ($actionableMatched || $isScam)) {
                    continue;
                }
                $veto = $group['label'];
                break;
            }
        }

        if ($this->isDisposable($domain)) {
            $add("disposable mail domain '" . $domain . "'");
        }
        // Suffix match, not equality: "co.uk" in the list must also match
        // "shop.co.uk", whose last label is "uk".
        foreach ($this->repList('suspicious_tlds') as $suspicious) {
            if ($domain !== $suspicious && ! str_ends_with($domain, '.' . $suspicious)) {
                continue;
            }
            $add("'." . $suspicious . "' TLD is mostly bulk senders");
            break;
        }
        if (preg_match('/^[0-9a-f]{6,16}\./i', $domain) === 1) {
            $add("opaque hex subdomain in '" . $domain . "'");
        }

        // A robotic local part on a project list: nobody writes to a security list
        // as "no-reply", and legitimate senders that do are automated notices we
        // cannot reply to anyway.
        $localPart = strstr($sender, '@', true);
        $localPart = $localPart === false ? '' : strtolower($localPart);
        if (in_array($localPart, ['no-reply', 'noreply', 'webmaster', 'postmaster', 'mailer-daemon'], true)) {
            $add("impersonal sender address '" . $localPart . "@'");
        }

        if (
            ($domainCounts[$domain] ?? 0) >= $this->domainBanMinMessages
            && ! $this->matchesDomain($domain, $this->repList('shared_domains'))
        ) {
            $add((string) $domainCounts[$domain] . " held messages from '" . $domain . "'");
        }

        // A sending host inside a firewall blocklist (hijacked ranges, fullbogons,
        // botnets) is a strong signal on its own; the veto above still protects
        // anything that reads like a real report.
        foreach ($ips as $ip) {
            if (! $this->isBlockedIp($ip)) {
                continue;
            }
            $add('sending IP ' . $ip . ' is on the firehol_level1 blocklist');
            break;
        }

        $auth = $message !== null ? $this->authResults($message) : ['spf' => null, 'dkim' => null, 'dmarc' => null];
        if ($auth['spf'] === 'fail') {
            $add('SPF fail');
        }
        if ($auth['spf'] === 'softfail') {
            $add('SPF softfail');
        }
        if ($auth['dkim'] === 'fail') {
            $add('DKIM fail');
        }
        if ($auth['dmarc'] === 'fail') {
            $add('DMARC fail');
        }

        $urls = $this->bodyUrls($body);
        foreach ($urls as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '' && $this->matchesDomain($host, $this->repList('shorteners'))) {
                $add('body links through a URL shortener (' . $host . ')');
                break;
            }
        }
        if (count($urls) >= 8) {
            $add((string) count($urls) . ' links in one message');
        }

        // A lookalike of a domain we own, mentioned in the text: the classic
        // "you should buy phpmyadmin.cn before someone else does" pitch, and the
        // typosquats used to farm credibility. Any spelling of a brand we hold
        // that is not the real domain is worth flagging, because no legitimate
        // correspondent has a reason to write one.
        // On the text WITH links: a squatted domain is usually written as a
        // URL, and stripping URLs before this check made it unfindable.
        foreach ($this->lookalikeDomains($rawText) as $lookalike => $brand) {
            $add('mentions ' . $lookalike . ', a lookalike of the ' . $brand . ' domain we own');
        }

        // A display name that IS a brand, from a domain that does not own it.
        // Matched against the display name alone: the same word in the body is
        // ordinary content ("we found a PayPal phishing kit").
        if ($displayName !== '' && ! $this->matchesDomain($domain, $this->repList('allow_domains'))) {
            $foldedName = $this->foldAccents($displayName);
            foreach ($this->displayNameGroups as $group) {
                foreach ($group['brands'] as $entry) {
                    // The brand's own domain is not impersonating itself: without
                    // this, mail from paypal.com was banned for saying "PayPal".
                    if ($this->matchesDomain($domain, $entry['owned'])) {
                        continue;
                    }
                    $brand = $entry['brand'];
                    // The name must be the brand, not merely contain it: strip
                    // everything that is not a letter or digit and compare.
                    $bare = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($foldedName));
                    $needle = (string) preg_replace('/[^a-z0-9]/', '', $brand);
                    if ($needle === '' || $bare === '') {
                        continue;
                    }
                    // Contains, not equals: "PayPal Support" and "PayPal Inc" are
                    // impersonation too, and exact comparison let them through.
                    // Anchored at the start so "notpaypal" does not match.
                    if (! str_starts_with($bare, $needle)) {
                        continue;
                    }
                    $add($group['label'] . ': display name claims "' . $brand . '"');
                    // Impersonating a brand in the display name is fraud, so it
                    // decides the verdict rather than merely adding a note.
                    // Unconditional: a Cyrillic or CJK subject is exactly how
                    // impersonation arrives, so the language rule must not shield
                    // it. This was previously nested under $hintRank < RANK_BAN.
                    $hintRank = self::RANK_EMPTY + 1;
                    $hintVerdict = self::VERDICT_BAN;
                    $decidingLabel = $group['label'] . ': display name claims "' . $brand . '"';
                    break 2;
                }
            }
        }

        // Header signals measured on this queue: Reply-To is set on 44% of the
        // spam and 9% of the rest, and its domain differing from the From domain
        // is what bulk and phishing mail does to catch bounces.
        if ($message !== null) {
            $replyTo = $this->headerValue($message, 'reply-to');
            if ($replyTo !== '') {
                $replyDomain = $this->domainOf($this->addressOf($replyTo));
                if ($replyDomain !== '' && $replyDomain !== $domain) {
                    $add('Reply-To goes to ' . $replyDomain . ' instead of ' . $domain);
                }
            }
            $spamFlag = strtolower($this->headerValue($message, 'x-spam-flag'));
            if (str_starts_with($spamFlag, 'yes') || str_contains($spamFlag, 'yes')) {
                $add('upstream spam filter marked it (X-Spam-Flag: YES)');
            }
        }

        // Signals that are only noise on a trusted domain are dropped before the
        // clean-score test, so a known correspondent is accepted unless something
        // substantive was found. Anything real keeps it for a human.
        if ($isAllowlisted) {
            $substantive = array_values(array_filter(
                $signals,
                // Only things held AGAINST the sender count. A negative signal is
                // evidence for it, so it must not push a trusted domain to review.
                fn (string $signal): bool => ! $this->isEvidenceForSender($signal)
                    && ! $this->isNoiseLabel($signal)
            ));
            if ($substantive === []) {
                // Not a review: a trusted domain with nothing against it is let
                // through, which is a decision of its own. The reason carries the
                // justification, otherwise the row shows a verdict with no cause.
                $accept = true;
                $veto = "allowlisted domain '" . $domain . "', nothing found against it";
            } else {
                $add("allowlisted domain '" . $domain . "' not auto-accepted: "
                    . implode('; ', array_map(
                        static fn (string $s): string => $s,
                        $substantive
                    )));
                // A trusted domain is never banned or discarded on a heuristic.
                // Something was found, so it goes to a human: a compromised
                // mailbox deserves a look, not an irreversible ban, and a real
                // report from a trusted org must never be thrown away.
                $hintVerdict = self::VERDICT_REVIEW;
            }
        }

        return [
            'signals' => $signals,
            'veto' => $veto,
            'accept' => $accept,
            'domain' => $domain,
            'sender_parsed' => $sender,
            'sender_from_header' => $senderFromHeader,
            'ips' => $ips,
            // The verdict the matched policy lines ask for, if any.
            'hint_verdict' => $hintVerdict,
            'deciding_label' => $decidingLabel,
            'foreign_subject' => $foreignSubject,
            'void_message' => $voidMessage,
            'parsed_subject' => $subject,
        ];
    }

    private function triage(): void
    {
        // Progress always goes to stderr, JSON mode or not: stdout carries the
        // document and nothing else.
        $step = static function (string $message): void {
            fwrite(STDERR, $message . PHP_EOL);
        };

        $step('reading the held queue for ' . $this->list . ' ...');
        $held = $this->fetchHeld();
        if ($held === []) {
            fwrite(STDERR, 'No held messages on ' . $this->list . PHP_EOL);
            if ($this->json) {
                echo (string) json_encode([
                    'list' => $this->list,
                    'mode' => 'DRY-RUN',
                    'held' => 0,
                    'plan' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
            }
            exit(0);
        }
        $step('  ' . count($held) . ' held message(s)');

        $domainCounts = [];
        foreach ($held as $entry) {
            $domain = $this->domainOf($entry['sender']);
            $domainCounts[$domain] = ($domainCounts[$domain] ?? 0) + 1;
        }

        // Both scopes: a sender banned site-wide is already covered, and must not
        // be reported as bannable just because this list has no entry for it.
        $step('reading bans, list and site-wide ...');
        $listBans = $this->fetchBans();
        $globalBans = $this->fetchGlobalBans();
        $bans = array_values(array_unique([...$listBans, ...$globalBans]));
        $step('  ' . count($listBans) . ' on this list, ' . count($globalBans) . ' site-wide');

        // Score the named ids, or the first --limit entries. Domain counts above
        // were taken from the whole queue either way.
        $wanted = $this->requestIds === []
            ? array_slice($held, 0, $this->limit)
            : array_values(array_filter(
                $held,
                fn (array $entry): bool => in_array($entry['id'], $this->requestIds, true)
            ));

        // A named id that is not in the queue would otherwise produce an empty
        // table and exit 0: the operator asked for a specific message, was given
        // success, and nothing happened. Say which ids were not found.
        $missing = array_values(array_diff($this->requestIds, array_column($wanted, 'id')));
        if ($missing !== []) {
            $ids = implode(', ', array_map(static fn (int $id): string => (string) $id, $missing));
            $this->quitError('Not in the held queue: ' . $ids
                . PHP_EOL . 'Nothing was read. Run without --request-id to list what is held.');
        }
        $step('fetching ' . count($wanted) . ' message body/bodies and scoring ...');

        $plan = [];
        $done = 0;
        $total = count($wanted);
        foreach ($wanted as $entry) {
            // Each message is a separate request, so a full queue takes minutes.
            // The counter rewrites itself in place, which only makes sense on a
            // terminal: redirected to a file it would be one long line.
            $done++;
            if (! $this->json && $total > 5 && $done < $total && $this->stderrIsTerminal()) {
                fwrite(STDERR, "\r  " . $done . '/' . $total . ' fetched and scored');
            }
            $message = $this->fetchMessage($entry['id']);
            $score = $this->score(
                $entry['id'],
                $entry['sender'],
                $entry['subject'],
                $message['raw'] ?? '',
                $domainCounts,
                $message['attachments'] ?? []
            );
            if ($score['parsed_subject'] !== '') {
                $entry['subject'] = $score['parsed_subject'];
            }
            $already = $this->isBanned($entry['sender'], $bans);
            // The policy file decides. A heading verdict wins over the score, so
            // editing the registry changes behaviour without touching this code.
            if ($already !== null) {
                $verdict = self::VERDICT_BANNED;
            } elseif ($score['accept']) {
                $verdict = self::VERDICT_ACCEPT;
            } elseif ($score['veto'] !== null) {
                $verdict = self::VERDICT_REVIEW;
            } elseif ($score['hint_verdict'] !== '') {
                $verdict = $score['hint_verdict'];
            } else {
                // Nothing matched: a human reads it. There is no score to fall
                // back on, and inventing one was the defect being removed.
                $verdict = self::VERDICT_REVIEW;
            }

            $plan[] = $entry + $score + [
                'verdict' => $verdict,
                'already' => $already,
                'raw_missing' => $message === null,
            ];
        }
        if (! $this->json && $total > 5) {
            if ($this->stderrIsTerminal()) {
                fwrite(STDERR, "\r  ");
            }
            fwrite(STDERR, '  ' . $total . '/' . $total . ' fetched and scored' . PHP_EOL);
        }
        // Say plainly which messages were judged on queue metadata alone, so a
        // thin verdict is never mistaken for a confident one.
        if ($this->unscorable !== []) {
            fwrite(STDERR, '  ' . count($this->unscorable) . ' message(s) could not be read in full: '
                . implode(', ', $this->unscorable) . PHP_EOL);
        }

        if ($this->json) {
            // With --apply the plan is followed by the results, which would make
            // two JSON documents on one stream. Emit the plan only for a dry run;
            // act() then reports what it did.
            if (! $this->applyChanges) {
                echo (string) json_encode([
                    'list' => $this->list,
                    'mode' => 'DRY-RUN',
                    'held' => count($plan),
                    'plan' => array_map(function (array $entry): array {
                        $entry['verdict'] = $this->verdictLabel((string) $entry['verdict']);

                        return $entry;
                    }, $plan),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
            }
        } else {
            $this->printPlan($plan, count($held));
        }

        if (! $this->applyChanges) {
            // Something to act on means a ban or a discard; Accept and
            // Already banned need nothing from the operator.
            $actionable = array_filter($plan, static fn (array $entry): bool => $entry['verdict'] === self::VERDICT_BAN
                || $entry['verdict'] === self::VERDICT_DISCARD);

            // A message whose body could not be read was scored on queue metadata
            // alone, so its verdict is not a real answer. Say so in the exit code
            // rather than reporting a clean plan.
            if ($this->unscorable !== []) {
                fwrite(
                    STDERR,
                    'warning: ' . count($this->unscorable) . ' message(s) were not scored in full;'
                    . ' the plan is incomplete' . PHP_EOL
                );
                exit(3);
            }

            exit($actionable === [] ? 0 : 2);
        }

        $this->act($plan, $domainCounts);
    }

    /**
     * Short tags for the table, derived from the signal text, so the shape of a
     * verdict is visible at a glance instead of buried in prose. The legend in
     * flagLegend() spells them out; keep the two in step.
     *
     * @param list<string> $signals
     */
    private function flagsFor(array $signals): string
    {
        // Infrastructure signals, recognised by their own wording. Policy labels
        // are NOT guessed at here: a label's flag comes from the section it was
        // written in ($labelFlags), because substring matching got it wrong --
        // "signature lure" is phishing but contains the generic word "lure".
        $map = [
            'SPF' => 'AUTH',
            'DKIM' => 'AUTH',
            'DMARC' => 'AUTH',
            'signed or encrypted attachment' => 'SIGNED',
            'attachment' => 'ATTACHMENT-ONLY',
            'disposable mail domain' => 'SUSPECT-DOMAIN',
            'TLD' => 'SUSPECT-DOMAIN',
            'hex subdomain' => 'SUSPECT-DOMAIN',
            'held messages' => 'REPEAT',
            'firehol_level1' => 'BLOCKLISTED-IP',
            'lookalike of the' => 'LOOKALIKE',
            'URL shortener' => 'LINKS',
            'links in one message' => 'LINKS',
            'Reply-To goes to' => 'REPLY-TO',
            'X-Spam-Flag' => 'SPAM-FLAG',
            'bulk-mailer header' => 'BULK-MAILER',
            'non-Latin script' => 'LANGUAGE',
            'message is empty' => 'EMPTY',
            'impersonal sender' => 'NO-REPLY',
        ];
        $tags = [];
        foreach ($signals as $signal) {
            $label = $signal;
            if (isset($this->labelFlags[$label])) {
                $tags[$this->labelFlags[$label]] = true;
            }
            foreach ($map as $needle => $tag) {
                if (str_contains($label, $needle) !== true) {
                    continue;
                }

                $tags[$tag] = true;

                // The first needle that matches wins. "signed or encrypted
                // attachment" also contains "attachment", which would add the
                // contradictory ATTACHMENT-ONLY flag.
                break;
            }
        }

        return implode(' ', array_keys($tags));
    }

    /** @return array<string, string> what each table flag means */
    private function flagLegend(): array
    {
        return [
            'SIGNED' => 'signature or encrypted attachment (evidence for the sender, -3)',
            'ATTACHMENT-ONLY' => 'no readable body, just an attached file',
            'AUTH' => 'SPF, DKIM or DMARC failed',
            'PHISHING' => 'credential or impersonation lure',
            'SCAM' => 'extortion or advance-fee template',
            'BULK' => 'unsolicited commercial outreach',
            'SUSPECT-DOMAIN' => 'domain reputation: disposable, abusive TLD, hex subdomain or free provider',
            'REPEAT' => 'several messages from this domain are held',
            'BLOCKLISTED-IP' => 'a sending host is on the firehol_level1 blocklist',
            'LOOKALIKE' => 'mentions a lookalike of a domain we own',
            'LINKS' => 'URL shortener, or many links in one message',
            'REPLY-TO' => 'Reply-To points at a different domain than From',
            'SPAM-FLAG' => 'upstream spam filter already marked it',
            'BULK-MAILER' => 'carries List-Unsubscribe or List-Post (newsletter plumbing)',
            'LANGUAGE' => 'subject is not written in a Latin script',
            'EMPTY' => 'no text and no attachment',
            'NO-REPLY' => 'sender local part is no-reply, webmaster or postmaster',
            'BAN-RULE' => 'the policy file puts this line in a Ban section',
        ];
    }

    /** @param list<array<string, mixed>> $plan */
    private function printLegend(array $plan): void
    {
        $used = [];
        foreach ($plan as $entry) {
            foreach (explode(' ', $this->flagsFor($entry['signals'])) as $tag) {
                if ($tag === '') {
                    continue;
                }

                $used[$tag] = true;
            }
        }
        if ($used === []) {
            return;
        }

        $legend = $this->flagLegend();
        echo PHP_EOL . 'FLAGS' . PHP_EOL;
        foreach ($legend as $tag => $meaning) {
            if (! isset($used[$tag])) {
                continue;
            }
            echo '  ' . str_pad($tag, 17) . $meaning . PHP_EOL;
        }
    }

    /** How a verdict reads in output: the raw token is for --json and comparisons. */
    private function verdictLabel(string $verdict): string
    {
        if ($verdict === self::VERDICT_BAN) {
            return 'Ban, Discard and notify';
        }
        if ($verdict === self::VERDICT_DISCARD) {
            return 'Discard and notify';
        }
        if ($verdict === self::VERDICT_BANNED) {
            return 'Already banned';
        }
        if ($verdict === self::VERDICT_ACCEPT) {
            return 'Accept';
        }

        return 'Needs review';
    }

    /** Pad to a display width, counting wide CJK characters as two columns. */
    private function pad(string $text, int $width): string
    {
        $text = mb_strimwidth($text, 0, $width, '...');

        $fill = $width - mb_strwidth($text);

        return $text . str_repeat(' ', max(0, $fill));
    }

    /** @param list<array<string, mixed>> $plan */
    private function printPlan(array $plan, int $queueSize = 0): void
    {
        $bans = array_filter($plan, static fn (array $entry): bool => $entry['verdict'] === self::VERDICT_BAN);
        $discards = array_filter($plan, static fn (array $entry): bool => $entry['verdict'] === self::VERDICT_DISCARD);
        $reviews = array_filter($plan, static fn (array $entry): bool => $entry['verdict'] === self::VERDICT_REVIEW);
        $already = array_filter($plan, static fn (array $entry): bool => $entry['verdict'] === self::VERDICT_BANNED);
        $accepted = array_filter($plan, static fn (array $entry): bool => $entry['verdict'] === self::VERDICT_ACCEPT);

        $scope = $queueSize > count($plan)
            ? count($plan) . ' of ' . $queueSize . ' selected'
            : count($plan) . ' held';

        // First line states what the run will do, before anything else is read.
        echo $this->applyChanges
            ? 'COMMIT mode, changes will be applied.' . PHP_EOL
            : 'DRY-RUN mode, use --apply to perform the changes.' . PHP_EOL;
        echo $this->list . '  ' . $scope . PHP_EOL;
        echo count($bans) . ' to ban, ' . count($discards) . ' to discard and notify, '
            . count($reviews) . ' to review, ' . count($accepted) . ' to accept, '
            . count($already) . ' already banned' . PHP_EOL;
        if (! $this->applyChanges) {
            echo 'nothing is changed: this run only reports.' . PHP_EOL;
        }
        echo PHP_EOL;

        // Table, worst first: ban candidates at the top. REASON is the short label
        // the policy line carries, so the table reads like the policy file.
        $header = sprintf(
            '%-5s %-24s %-28s %-28s %-38s %s',
            'ID',
            'VERDICT',
            'SENDER',
            'SUBJECT',
            'REASON',
            'FLAGS'
        );
        echo $header . PHP_EOL;
        // The rule is the width of the header it sits under, so the two cannot
        // drift apart when a column changes.
        echo str_repeat('-', mb_strwidth($header)) . PHP_EOL;
        $indent = str_repeat(' ', self::REASON_INDENT);
        foreach ($this->sortForDisplay($plan) as $entry) {
            // The reason is never cut: it wraps, and the continuation lines sit
            // under the REASON column with the other columns left blank.
            $reasonLines = $this->wrapText($this->reasonFor($entry), self::REASON_WIDTH);
            $flags = $this->flagsFor($entry['signals']);
            foreach ($reasonLines as $index => $line) {
                if ($index === 0) {
                    printf(
                        '%-5s %-24s %s %s ',
                        $entry['id'],
                        $this->verdictLabel((string) $entry['verdict']),
                        $this->pad((string) $entry['sender'], 28),
                        $this->pad((string) $entry['subject'], 28)
                    );
                    echo $this->pad($line, self::REASON_WIDTH) . ' ' . $flags . PHP_EOL;
                    continue;
                }

                echo rtrim($indent . $this->pad($line, self::REASON_WIDTH)) . PHP_EOL;
            }
        }

        $this->printLegend($plan);
    }

    /**
     * Break text into lines of at most $width display columns, on word
     * boundaries. A single word longer than the width keeps its own line rather
     * than being split.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $width): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $this->wrapWords(explode(' ', $text), $width);
    }

    /**
     * @param list<string> $words
     *
     * @return list<string>
     */
    private function wrapWords(array $words, int $width): array
    {
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            if ($current === '') {
                $current = $word;
                continue;
            }
            if (mb_strwidth($current . ' ' . $word) <= $width) {
                $current .= ' ' . $word;
                continue;
            }
            $lines[] = $current;
            $current = $word;
        }
        $lines[] = $current;

        return $lines;
    }

    /**
     * Order by verdict, then sender, then subject: Ban first, then Needs review,
     * then Already banned. Verdict rank is explicit rather than alphabetical, so
     * the rows needing action stay at the top.
     *
     * @param list<array<string, mixed>> $plan
     *
     * @return list<array<string, mixed>>
     */
    private function sortForDisplay(array $plan): array
    {
        $rank = [
            self::VERDICT_BAN => 0,
            self::VERDICT_DISCARD => 1,
            self::VERDICT_REVIEW => 2,
            self::VERDICT_BANNED => 3,
            self::VERDICT_ACCEPT => 4,
        ];

        usort($plan, static function (array $left, array $right) use ($rank): int {
            $byVerdict = ($rank[(string) $left['verdict']] ?? 9) <=> ($rank[(string) $right['verdict']] ?? 9);
            if ($byVerdict !== 0) {
                return $byVerdict;
            }
            $bySender = strcasecmp((string) $left['sender'], (string) $right['sender']);
            if ($bySender !== 0) {
                return $bySender;
            }

            return strcasecmp((string) $left['subject'], (string) $right['subject']);
        });

        return $plan;
    }

    /**
     * The short reason for a row: the policy label that decided it.
     *
     * A veto reason wins, because that is why the message was left alone; then
     * the highest-scoring policy line; then the infrastructure signal.
     *
     * @param array<string, mixed> $entry
     */
    private function reasonFor(array $entry): string
    {
        if ($entry['veto'] !== null) {
            return (string) $entry['veto'];
        }

        /** @var list<string> $signals */
        $signals = $entry['signals'];
        // Prefer a label from the section whose verdict this row carries: showing
        // a bulk label on a Ban row explains the wrong decision. The policy label
        // of the deciding section is recorded on the entry for exactly this.
        $deciding = (string) ($entry['deciding_label'] ?? '');
        if ($deciding !== '') {
            foreach ($signals as $signal) {
                if (str_contains($signal, $deciding)) {
                    return $signal;
                }
            }
        }

        foreach ($signals as $signal) {
            if (str_contains($signal, 'firehol_level1')) {
                continue;
            }

            return $signal;
        }

        return $signals === [] ? '' : $signals[0];
    }

    private function printBans(): void
    {
        if ($this->showBans === 'list' || $this->showBans === 'all') {
            $this->printBanGroup($this->fetchBans(), 'bans on ' . $this->list);
        }
        if ($this->showBans !== 'global' && $this->showBans !== 'all') {
            return;
        }
        if ($this->showBans === 'all') {
            echo PHP_EOL;
        }
        $this->printBanGroup($this->fetchGlobalBans(), 'site-wide bans (all lists)');
    }

    /** @param list<string> $bans */
    private function printBanGroup(array $bans, string $title): void
    {
        echo '=== ' . $title . ' (' . count($bans) . ') ===' . PHP_EOL;
        foreach ($bans as $ban) {
            echo '  ' . $ban . PHP_EOL;
        }
    }

    /**
     * What the sender is told when the message is rejected.
     *
     * The wording comes from the policy file's Messages section, so the words a
     * stranger reads after their mail was thrown away can be edited without
     * touching this script. `$notice` selects a special case whose wording cannot
     * be the spam wording: a message dropped for its language, or one dropped for
     * having no content. Neither is banned, so neither may claim the address was
     * added to a ban list.
     */
    private function notificationText(
        string $reason,
        string $notice = '',
        bool $banned = false,
        bool $alreadyBanned = false
    ): string {
        if ($notice === 'foreign' || $notice === 'void') {
            $name = $notice;
        } elseif ($alreadyBanned) {
            $name = 'already_banned';
        } elseif ($banned) {
            $name = 'banned';
        } else {
            $name = 'discard';
        }

        $template = $this->messages[$name] ?? '';
        if ($template === '') {
            // The loader requires these, so reaching here means a programming
            // error, not a policy gap. Fail rather than mail an empty body.
            $this->quitError('No "' . $name . '" message in the policy file.');
        }

        $text = strtr($template, [
            '{list}' => $this->list,
            '{reason}' => $reason,
        ]);

        // Reflow after substitution: the policy file is hard-wrapped for reading,
        // but {list} is 27 characters and would leave ragged lines. Each paragraph
        // is wrapped on its own so blank lines still separate them.
        $paragraphs = array_map(
            fn (string $para): string => $para === ''
                ? ''
                : implode(PHP_EOL, $this->wrapText($para, 76)),
            preg_split('/\n[ \t]*\n/', $text) ?: [$text]
        );

        return implode(PHP_EOL . PHP_EOL, $paragraphs);
    }

    /** Postorius mails the sender the reason on reject; discard is silent. */

    /**
     * Submit a moderation decision for one held message.
     *
     * Postorius reads the button name: accept lets the message through to the
     * list, reject drops it and mails the sender the reason, discard drops it
     * silently. Only reject carries a reason.
     */
    private function moderate(
        int $id,
        string $reason,
        bool $accept = false,
        string $notice = '',
        bool $banned = false,
        bool $alreadyBanned = false
    ): bool {
        if ($accept) {
            $choice = 'accept';
        } else {
            $choice = $this->silent ? 'discard' : 'reject';
        }

        $path = '/postorius/lists/' . $this->listId() . '/held_messages/moderate';
        $page = $this->http('GET', '/postorius/lists/' . $this->listId() . '/held_messages');
        $form = [
            'csrfmiddlewaretoken' => $this->csrfFrom($page['body']),
            'msgid' => (string) $id,
            $choice => '1',
        ];
        if ($choice === 'reject') {
            $form['reason'] = $this->notificationText($reason, $notice, $banned, $alreadyBanned);
        }

        return $this->writeSucceeded($this->http('POST', $path, $form), 'Moderation of request ' . $id);
    }

    private function applyBanChange(string $email, bool $add): bool
    {
        $path = '/postorius/lists/' . $this->listId() . '/bans/';
        $page = $this->http('GET', $path);
        if ($page['http'] !== 200) {
            fwrite(STDERR, 'ERROR: could not open the ban page (HTTP ' . $page['http'] . ').' . PHP_EOL);

            return false;
        }
        $result = $this->http('POST', $path, [
            'csrfmiddlewaretoken' => $this->csrfFrom($page['body']),
            ($add ? 'add' : 'del') => '1',
            'email' => $email,
        ]);
        if (! $this->writeSucceeded($result, ($add ? 'Ban' : 'Unban') . ' of ' . $email)) {
            fwrite(STDERR, 'ERROR: ' . ($add ? 'ban' : 'unban') . ' of ' . $email
                . ' was rejected by the server.' . PHP_EOL);

            return false;
        }

        $line = ($add ? 'banned: ' : 'unbanned: ') . $email . PHP_EOL;
        if ($this->json) {
            // stdout carries the JSON document; keep the narrative off it.
            fwrite(STDERR, $line);
        } else {
            echo $line;
        }

        return true;
    }

    /**
     * How many messages from this domain are held, taken from the whole queue.
     * Counting the filtered plan would escalate to a domain ban on a run scoped
     * to a single --request-id, which is exactly when it must not.
     *
     * @param array<string, mixed> $entry
     * @param array<string, int>   $domainCounts
     */
    private function banValueFor(array $entry, array $domainCounts): string
    {
        // The queue cell is rendered from untrusted mail: a display name like
        // "<^.*@.*\$>" parses as address-shaped text, so it must never choose who
        // gets banned. Only the address parsed from the message headers may be
        // used, and when that was unavailable there is no ban at all - returning
        // the cell value here was the last path by which mail content could pick
        // the address.
        $domain = (string) $entry['domain'];
        // Provenance, not equality: a bare-address cell and a parsed From give the
        // same string, so comparing them rejected every ordinary row and the tool
        // could never ban anyone.
        if (($entry['sender_from_header'] ?? false) !== true) {
            return '';
        }
        $sender = (string) ($entry['sender_parsed'] ?? '');
        if ($sender === '') {
            return '';
        }
        $count = $domainCounts[$domain] ?? 0;

        if (
            $this->banDomain
            && $count >= $this->domainBanMinMessages
            && $entry['verdict'] === self::VERDICT_BAN
            && ! $this->matchesDomain($domain, $this->repList('shared_domains'))
        ) {
            return '^.*@' . preg_quote($domain, '/') . '$';
        }

        return $sender;
    }

    /** @param array<string, mixed> $entry */
    private function writeLedger(
        array $entry,
        string $banValue,
        bool $ok,
        string $action = '',
        bool $banAdded = false,
        bool $banFailed = false
    ): void {
        $dir = dirname($this->ledger);
        if (! is_dir($dir)) {
            // mkdir() honours the process umask, so the mode requested here is
            // only an upper bound. Widen it while creating, then narrow again.
            $previous = umask(0);
            mkdir($dir, 0775, true);
            umask($previous);
        }

        $encoded = json_encode([
            // UTC: an audit trail must not shift with the machine's timezone.
            'at' => gmdate('c'),
            'action' => $action === '' ? ($this->silent ? 'discard' : 'reject') : $action,
            'list' => $this->list,
            'sender' => $entry['sender'],
            'subject' => $entry['subject'],
            'ban' => $banValue,
            'ban_added' => $banAdded,
            'ban_failed' => $banFailed,
            'already_banned' => $entry['already'] !== null,
            'request_id' => $entry['id'],
            'signals' => $entry['signals'],
            'ips' => $entry['ips'],
            'moderated' => $ok,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            fwrite(STDERR, 'warning: could not encode a ledger record; skipped' . PHP_EOL);

            return;
        }
        $line = $encoded . PHP_EOL;

        // Created 0600 up front: chmod after the first write left the file at the
        // process umask, typically 0644, while the first record was written.
        if (! is_file($this->ledger)) {
            $created = umask(0077);
            touch($this->ledger);
            umask($created);
        }
        @chmod($this->ledger, 0600);

        // LOCK_EX so two runs cannot interleave a half-written line.
        $handle = fopen($this->ledger, 'ab');
        if ($handle === false) {
            fwrite(STDERR, 'warning: could not open the ledger ' . $this->ledger . PHP_EOL);

            return;
        }
        flock($handle, LOCK_EX);
        fwrite($handle, $line);
        flock($handle, LOCK_UN);
        fclose($handle);
        // Senders' addresses and our verdicts are not for every local user.
        @chmod($this->ledger, 0600);
    }

    /**
     * The ban that already covers this address, if any. Three notations apply:
     * a plain address, "^regexp" matched against the whole address, and the
     * "*@domain" wildcard used by the site-wide list.
     *
     * @param list<string> $bans
     */
    private function isBanned(string $email, array $bans): string|null
    {
        $email = $this->addressOf($email);
        $domain = $this->domainOf($email);

        foreach ($bans as $ban) {
            if (strcasecmp($ban, $email) === 0) {
                return $ban;
            }
            if ($ban !== '' && $ban[0] === '^' && preg_match('#' . str_replace('#', '\#', $ban) . '#i', $email) === 1) {
                return $ban;
            }
            if (! str_starts_with($ban, '*@') || $domain === '') {
                continue;
            }

            $pattern = substr($ban, 2);
            if ($domain === $pattern || str_ends_with($domain, '.' . $pattern)) {
                return $ban;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $plan
     * @param array<string, int>         $domainCounts
     */
    private function act(array $plan, array $domainCounts): void
    {
        // Selection comes from --request-id, and from --action for whole verdict
        // classes. --action is the only way to act without naming ids, so the
        // operator states which class they mean rather than a flag implying it.
        // A message whose body could not be read was judged on its subject alone.
        // Acting on that is guessing, so --action skips it and says so. Naming the
        // id explicitly still works, as a deliberate override.
        $unreadable = array_values(array_filter(
            $plan,
            fn (array $entry): bool => ($entry['raw_missing'] ?? false)
                && in_array($entry['verdict'], $this->actions, true)
                && ! in_array($entry['id'], $this->requestIds, true)
        ));
        if ($unreadable !== []) {
            fwrite(STDERR, 'skipping ' . count($unreadable) . ' message(s) whose body could not be'
                . ' read: ' . implode(', ', array_map(static fn (array $e): string => (string) $e['id'], $unreadable))
                . PHP_EOL . '  Name them with --request-id to act on the subject alone.' . PHP_EOL);
        }

        $selected = array_values(array_filter(
            $plan,
            fn (array $entry): bool => (in_array($entry['id'], $this->requestIds, true)
                    || in_array($entry['verdict'], $this->actions, true))
                && ! in_array($entry, $unreadable, true)
        ));
        if ($selected === []) {
            $this->quitError('A write needs --request-id=<id[,id...]> or --action=<ban,discard,accept>.'
                . ' Nothing was changed.');
        }

        // The verdict decides the action, never the request alone. Acting on an
        // ``Accept`` row would ban and reject a message the triage cleared, which
        // for a report from an allowlisted domain means banning the researcher
        // and rejecting their disclosure.
        //
        // ALREADY-BANNED is allowed through on purpose: if an earlier run banned
        // the sender and then failed to moderate, the message is still held and
        // this is the only way to finish the job. The ban POST is skipped for it,
        // so re-running stays safe.
        $refused = array_filter(
            $selected,
            static fn (array $entry): bool => ! in_array(
                $entry['verdict'],
                [
                    self::VERDICT_BAN,
                    self::VERDICT_DISCARD,
                    self::VERDICT_ACCEPT,
                    self::VERDICT_BANNED,
                    // A review row carries no verdict, so it is actionable only
                    // because --review-action supplied one.
                    self::VERDICT_REVIEW,
                ],
                true
            )
        );
        if ($refused !== []) {
            $ids = implode(', ', array_map(static fn (array $e): string => (string) $e['id'], $refused));
            $this->quitError('Those messages are not actionable: ' . $ids);
        }
        // A review row without a stated decision is still nothing to act on.
        if ($this->reviewActions === []) {
            $unreviewed = array_filter(
                $selected,
                static fn (array $entry): bool => $entry['verdict'] === self::VERDICT_REVIEW
            );
            if ($unreviewed !== []) {
                $ids = implode(', ', array_map(
                    static fn (array $e): string => (string) $e['id'],
                    $unreviewed
                ));
                $this->quitError('Needs review rows need a decision: add --review-action=accept or'
                    . ' --review-action=discard. Not acted on: ' . $ids);
            }
        }

        $results = [];
        $done = 0;
        // Senders banned during this run, so a duplicate in the same batch does
        // not look like a failed ban: the server would answer "already banned".
        /** @var array<string, true> $bannedThisRun */
        $bannedThisRun = [];
        foreach ($selected as $entry) {
            // A stated review decision replaces the verdict for this row: the row
            // had none, and the operator supplied one.
            $decision = $entry['verdict'] === self::VERDICT_REVIEW && $this->reviewActions !== []
                ? $this->reviewActions[0]
                : '';
            $accepting = $entry['verdict'] === self::VERDICT_ACCEPT
                || $decision === 'accept';

            if ($decision === 'discard') {
                $action = 'reject';
            } else {
                $action = $accepting ? 'accept' : ($this->silent ? 'discard' : 'reject');
            }

            /** @var list<string> $signals */
            $signals = $entry['signals'];
            $reasonText = implode('; ', array_map(
                static fn (string $signal): string => (string) preg_replace('/^[+-]\d+ /', '', $signal),
                $signals
            ));

            // Accepting never touches the ban list: the message was cleared.
            // A message rejected only for its language, or for having no content,
            // is dropped without a ban and gets its own wording.
            $foreignSubject = ($entry['foreign_subject'] ?? false) === true;
            $voidMessage = ($entry['void_message'] ?? false) === true;
            // Only a Ban verdict touches the ban list. Discard drops the message
            // and tells the sender why; the policy file promises it is not added
            // to the ban list, so the notification must be able to say that.
            $banning = $entry['verdict'] === self::VERDICT_BAN
                && $decision === '';
            // A sender banned earlier in this same run: the server would answer
            // "already banned", which used to be reported as a failure.
            $alreadyInRun = isset($bannedThisRun[$entry['sender']]);
            $banValue = '';
            $banAdded = false;
            $banFailed = false;
            if ($banning) {
                $banValue = $this->banValueFor($entry, $domainCounts);
                if ($banValue === '') {
                    // No usable address in the headers: skip the ban, keep the
                    // message moving. Exiting here would abandon the batch.
                    $banFailed = true;
                    $banValue = '';
                } else {
                    try {
                        $this->refuseIfBanningTrustedDomain($banValue);
                        $banValue = $this->validBanValue($banValue);
                    } catch (BanRefused $error) {
                        $banFailed = true;
                        fwrite(STDERR, 'warning: not banning ' . $banValue . ': '
                            . $error->getMessage() . PHP_EOL);
                        $banValue = '';
                    }
                }

                // Already covered by a ban: skip the POST, which would be answered
                // with "already banned" and abort the loop, but still moderate the
                // message. Otherwise a ban whose moderation failed leaves a message
                // held forever, because every later run sees it as already banned.
                // A failed ban must not abandon the rest of the batch. HttpFailure
                // from the ban exchange used to escape to the top-level catch and
                // exit mid-run, leaving earlier messages acted on with no summary.
                if (
                    $entry['already'] === null
                    && ! $alreadyInRun
                    && ! $foreignSubject
                    && ! $voidMessage
                    && $banValue !== ''
                ) {
                    try {
                        if (! $this->applyBanChange($banValue, true)) {
                            $banFailed = true;
                        } else {
                            $banAdded = true;
                        }
                    } catch (HttpFailure $failure) {
                        $banFailed = true;
                        fwrite(STDERR, 'warning: ban of ' . $banValue . ' failed: '
                            . $failure->getMessage() . PHP_EOL);
                    }
                }
            }

            $notice = '';
            // void outranks foreign in RANK_*, so it is checked first: a message
            // that is both must not be blamed on its language.
            if ($voidMessage) {
                $notice = 'void';
            } elseif ($foreignSubject) {
                $notice = 'void';
            }

            try {
                $ok = $this->moderate(
                    (int) $entry['id'],
                    $reasonText,
                    $accepting,
                    $notice,
                    $banAdded,
                    $entry['already'] !== null
                );
            } catch (HttpFailure $failure) {
                // One dropped connection must not abandon the rest of the batch:
                // record it, keep the id out of $done, and carry on.
                $ok = false;
                fwrite(STDERR, 'warning: request ' . $entry['id'] . ' failed: '
                    . $failure->getMessage() . PHP_EOL);
            }
            $this->writeLedger($entry, $banValue, $ok, $action, $banAdded, $banFailed);
            if ($ok) {
                $done++;
            }

            $results[] = [
                'request_id' => $entry['id'],
                'sender' => $entry['sender'],
                'ban' => $banValue,
                'ban_added' => $banAdded,
                'ban_failed' => $banFailed,
                'already_banned' => $entry['already'] !== null,
                'action' => $action,
                'moderated' => $ok,
            ];
            if ($this->json) {
                continue;
            }

            printf(
                '%-40s %-8s %-7s %s%s%s',
                mb_strimwidth((string) $entry['sender'], 0, 40),
                $action,
                $ok && ! $banFailed ? 'done' : 'FAILED',
                $banValue === '' ? '' : 'ban=' . $banValue,
                $banFailed ? ' [BAN FAILED]' : '',
                PHP_EOL
            );
        }

        if ($this->json) {
            echo (string) json_encode([
                'list' => $this->list,
                'mode' => 'COMMIT',
                'action' => $action,
                'handled' => $done,
                'requested' => count($selected),
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
        } else {
            echo PHP_EOL . 'handled ' . $done . ' of ' . count($selected) . PHP_EOL;
        }

        $banFailures = count(array_filter($results, static fn (array $r): bool => $r['ban_failed']));
        if ($banFailures > 0) {
            fwrite(STDERR, 'ERROR: ' . $banFailures . ' ban(s) failed; those senders are NOT banned'
                . PHP_EOL);
        }
        exit($done === count($selected) && $banFailures === 0 ? 0 : 1);
    }

    private function printHelp(): void
    {
        echo 'Triage the mailing-list moderation queue.' . PHP_EOL . PHP_EOL;
        echo 'By default nothing is changed. Using --apply performs the changes,'
             . ' --action and --review-action only select what would be done.' . PHP_EOL . PHP_EOL;
        echo 'Usage:' . PHP_EOL;
        echo '  ...                                    report what is held (read-only)' . PHP_EOL;
        echo '  ... --json                             the same, as JSON on stdout' . PHP_EOL;
        echo '  ... --show-bans=list|global|all        which bans apply' . PHP_EOL;
        echo PHP_EOL;
        echo '  ... --apply --request-id=412,415-418   act on these held messages' . PHP_EOL;
        echo '  ... --apply --action=ban               act on every Ban row' . PHP_EOL;
        echo '  ... --apply --action=ban,accept        act on those two classes' . PHP_EOL;
        echo '  ... --apply --action=already-banned    clear messages whose sender is banned' . PHP_EOL;
        echo '  ... --apply --review-action=accept --request-id=412' . PHP_EOL;
        echo '                                         decide rows left for review' . PHP_EOL;
        echo '  ... --apply --ban=<address>            add a ban' . PHP_EOL;
        echo '  ... --apply --unban=<address>          remove a ban' . PHP_EOL;
        echo PHP_EOL;
        echo 'Options:' . PHP_EOL;
        echo '  --apply            perform the changes; without it the run only reports' . PHP_EOL;
        echo '  --action=<class>   select a verdict class: ban, already-banned, discard, accept' . PHP_EOL;
        echo '  --review-action=<decision> decide review rows: accept or discard (needs --request-id)' . PHP_EOL;
        echo '  --request-id=<ids> the held messages, e.g. 412,415-418 (or --request-ids)' . PHP_EOL;
        echo '  --list=<address>   list to work on (default ' . self::DEFAULT_LIST . ')' . PHP_EOL;
        echo '  --silent           discard without notifying the sender' . PHP_EOL;
        echo '  --limit=<n>        held messages to read (default 200)' . PHP_EOL;
        echo '  --ban-domain       allow banning a whole domain (off: a From is forgeable)' . PHP_EOL;
        echo '  --dry-run          force read-only even alongside --apply' . PHP_EOL;
        echo '  --offline          do not refresh the upstream feeds' . PHP_EOL;
        echo '  --config=<path>    config file (default ~/.config/phpmyadmin)' . PHP_EOL;
        echo PHP_EOL;
        echo 'Exit: 0 nothing to do, 2 something actionable, 3 plan incomplete, 1 error' . PHP_EOL;
    }

    private function quitError(string $message): never
    {
        fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
        exit(1);
    }
}

// Anything HttpFailure that reaches here happened outside a batch (login, the
// queue read, the ban list) and ends the run. Print it as an error, not a stack.
try {
    new MailmanModeration();
} catch (HttpFailure | BanRefused $failure) {
    fwrite(STDERR, 'ERROR: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
