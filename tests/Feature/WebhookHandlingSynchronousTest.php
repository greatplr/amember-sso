<?php

namespace Greatplr\AmemberSso\Tests\Feature;

/**
 * Runs every WebhookHandlingTest case with queueing disabled, which goes
 * through the controller's own handlers instead of ProcessAmemberWebhook.
 */
class WebhookHandlingSynchronousTest extends WebhookHandlingTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('amember-sso.webhook.use_queue', false);
    }
}
