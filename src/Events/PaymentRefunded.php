<?php

namespace Greatplr\AmemberSso\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentRefunded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public array $payment,
        public array $rawData
    ) {}
}
