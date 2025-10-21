<?php

namespace Greatplr\AmemberSso\Events;

use Greatplr\AmemberSso\Models\AmemberProduct;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public array $subscription,
        public array $rawData,
        public ?AmemberProduct $productMapping = null
    ) {}
}
