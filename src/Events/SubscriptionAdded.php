<?php

namespace Greatplr\AmemberSso\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionAdded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public array $subscription,
        public array $rawData
    ) {}
}
