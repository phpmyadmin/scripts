<?php

declare(strict_types=1);

namespace PhpMyAdmin\Scripts\MailmanModeration;

use RuntimeException;

/**
 * A ban value the tool refuses to issue.
 *
 * Thrown rather than exiting so that a single unusable address cannot abandon a
 * batch; outside a batch it ends the run with the same message as before.
 */
class BanRefused extends RuntimeException
{
}
