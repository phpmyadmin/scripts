<?php

declare(strict_types=1);

namespace PhpMyAdmin\Scripts\MailmanModeration;

use RuntimeException;

/**
 * A request that never produced an HTTP answer.
 *
 * Distinct from a 4xx/5xx, which IS an answer. Thrown rather than exiting so that
 * act() can record the failure and continue with the remaining messages; outside
 * act() it propagates and ends the run.
 */
class HttpFailure extends RuntimeException
{
}
