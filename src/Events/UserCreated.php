<?php

namespace Greatplr\AmemberSso\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public $user,
        public array $rawData
    ) {}
}
