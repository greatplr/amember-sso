<?php

namespace Greatplr\AmemberSso\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public array $rawData
    ) {}
}
