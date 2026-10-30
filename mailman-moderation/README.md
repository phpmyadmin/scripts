# Mailman moderation

Triages the phpMyAdmin mailing-list moderation queue and can ban, reject or accept held messages.

Mailman's REST API is not published on `lists.phpmyadmin.net`: nginx proxies only Postorius, and the API binds `127.0.0.1:8001`. This script drives the Postorius web interface instead.

## Requirements

- PHP 8.4.1 or later
- `ext-curl`, `ext-mbstring`, `ext-ctype`, `ext-json`
- A Postorius account that can moderate the list

```
cd mailman-moderation
composer install
```

## Configuration

Read from `~/.config/phpmyadmin`, the same file `phpmyadmin-report.php` uses. Section named after the host:

```ini
[lists.phpmyadmin.net]
user = <login>
password = <password>
```

Optional keys:

| key | default | |
|---|---|---|
| `url` | `https://lists.phpmyadmin.net/mailman3` | Postorius base URL |
| `list` | `security@phpmyadmin.net` | list to moderate; `--list` wins |
| `ledger` | `../private/mailman-moderation-ledger.jsonl` | append-only audit trail |
| `reputation` | `../private/mailman-moderation-reputation.md` | policy file, **required** |

The policy file holds the domain lists, the detection terms, the sender-facing notices and the thresholds. It is kept out of this repository: it records which senders are trusted and what counts as abuse.

## Usage

Read-only triage, the default:

```
./mailman-moderation/phpmyadmin-mailman-moderation.php
```

Every run is read-only until `--apply` is given. `--apply` performs; `--action` and
`--review-action` only select what would be performed, so the two can be separated:

```
./mailman-moderation/phpmyadmin-mailman-moderation.php --action=ban            # preview
./mailman-moderation/phpmyadmin-mailman-moderation.php --apply --action=ban   # perform
```

```
# act on named messages, ranges allowed
./mailman-moderation/phpmyadmin-mailman-moderation.php --apply --request-id=412,415-418

# act on every Ban row, capped at 25
./mailman-moderation/phpmyadmin-mailman-moderation.php --apply --action=ban

# decide rows the triage left for review: one decision, named ids
./mailman-moderation/phpmyadmin-mailman-moderation.php --apply --review-action=accept --request-id=412

# ban list changes
./mailman-moderation/phpmyadmin-mailman-moderation.php --apply --ban=spammer@example.test
./mailman-moderation/phpmyadmin-mailman-moderation.php --apply --unban=spammer@example.test
./mailman-moderation/phpmyadmin-mailman-moderation.php --show-bans=all
```

## Options

| option | |
|---|---|
| `--apply` | perform the changes; without it the run only reports |
| `--request-id=<ids>` | held messages: `412,415-418` (also `--request-ids`) |
| `--action=<class>` | select a verdict class: `ban`, `discard`, `accept`, `already-banned` |
| `--review-action=<decision>` | decide review rows: `accept` or `discard`, needs `--request-id` |
| `--dry-run` | force read-only even alongside `--apply` |
| `--ban-domain` | allow banning a whole domain; off by default |
| `--silent` | use discard instead of reject: no mail to the sender |
| `--list=<address>` | list to moderate |
| `--limit=<n>` | held messages to read, default 200 |
| `--json` | machine-readable plan on stdout |
| `--show-bans=list\|global\|all` | which bans apply |
| `--ban=<value>` / `--unban=<value>` | change the ban list |
| `--offline` | do not refresh the upstream feeds |
| `--config=<path>` | config file |

## Output

A table, ordered by verdict then sender then subject. `REASON` is the short label of the policy line that decided the verdict; a wrapped reason continues under its own column. A legend below the table spells out the flags.

```
DRY-RUN  security@example.org  41 held
6 to ban, 3 to discard and notify, 31 to review, 1 to accept, 0 already banned
nothing is changed: this run only reports.

ID    VERDICT                  SENDER                       SUBJECT                      REASON                                 FLAGS
-------------------------------------------------------------------------------------------------------------------------------------
412   Ban, Discard and notify  billing@mail-relay.example   Invoice 88213 attached       wallet brands: display name claims     PHISHING BAN-RULE
                                                                                         "paypal"
407   Discard and notify       news@bulk-sender.example     Spring product bulletin      bulk-mailer header list-unsubscribe    BULK-MAILER
399   Accept                   reports@research.example     XSS in the Designer          allowlisted domain "research.example", AUTH
                                                                                         nothing found against it
```

Flags: `SIGNED`, `ATTACHMENT-ONLY`, `AUTH`, `PHISHING`, `SCAM`, `BULK`, `SUSPECT-DOMAIN`, `REPEAT`, `BLOCKLISTED-IP`, `LOOKALIKE`, `LINKS`, `REPLY-TO`, `SPAM-FLAG`, `BULK-MAILER`, `LANGUAGE`, `EMPTY`, `BAN-RULE`.

Progress goes to stderr, one line per step. Each message body is a separate request, so a full queue takes a couple of minutes.

## Verdicts

| verdict | action | ban list |
|---|---|---|
| `Ban, Discard and notify` | reject, notify the sender | added |
| `Discard and notify` | reject, notify the sender | not added |
| `Needs review` | nothing; left for a human | not added |
| `Accept` | accept, the message reaches the list | not added |
| `Already banned` | sender is already on a ban list; the held message can still be cleared | already there |

A verdict comes from the `-> Verdict` in the policy section heading whose line matched. There is no score.

## Behaviour

- A message is never banned when its sender domain is allow-listed. It is accepted when nothing was found against it, and sent to review otherwise.
- A message carrying vulnerability vocabulary is never auto-banned. Genuine reports arrive from personal mailboxes with terse subjects, so that check is deliberately wide.
- A subject written in a non-Latin script is discarded and the sender asked to write in English.
- A message with no readable text and no attachment is discarded.
- `.gpg`, `.pgp`, `.asc` and `.sig` attachments count in the sender's favour; only the file name is read.
- `List-Unsubscribe` and `List-Post` on an untrusted sender mean newsletter plumbing and route to discard.
- Requests are spaced by 0.7s and sent with browser headers: the server drops connections when its queue is polled quickly. Transport failures are retried on `GET`; a `POST` is never retried, because a lost reply cannot be told apart from a lost write.
- A message whose body cannot be read is judged on queue metadata alone and named in the summary.

## Exit codes

| code | |
|---|---|
| 0 | nothing to do |
| 1 | error |
| 2 | at least one message is actionable |
| 3 | the plan is incomplete: some bodies could not be read |

## Tests

Not yet written. A change to the policy or the scoring should be checked with a dry run before acting.
