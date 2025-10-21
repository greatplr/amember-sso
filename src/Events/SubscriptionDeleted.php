<?php

namespace Greatplr\AmemberSso\Events;

use Greatplr\AmemberSso\Models\AmemberProduct;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public array $rawData,
        public ?AmemberProduct $productMapping = null
    ) {}
}
