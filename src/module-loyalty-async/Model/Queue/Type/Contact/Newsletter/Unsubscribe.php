<?php

declare(strict_types=1);

namespace Leat\LoyaltyAsync\Model\Queue\Type\Contact\Newsletter;

class Unsubscribe extends Subscribe
{
    protected const string TYPE_CODE = 'newsletter_unsubscribe';

    protected bool $subscribe = false;
}
