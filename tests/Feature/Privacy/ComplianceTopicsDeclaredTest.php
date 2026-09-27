<?php

namespace Tests\Feature\Privacy;

use App\Services\Shopify\Webhooks\PrivacyWebhookHandler;
use Tests\TestCase;

/**
 * The mandatory privacy webhooks are DECLARED in both app configs.
 *
 * Compliance topics cannot be subscribed per shop through the Admin API; the
 * toml (or the Partner Dashboard) is the only way Shopify delivers them. With
 * the block commented out, customers/redact, shop/redact and
 * customers/data_request never reached the app. Pinned against the handler's
 * own topics and config('shopify.webhook_topics') so the three stay in step.
 */
final class ComplianceTopicsDeclaredTest extends TestCase
{
    // === CONSTANTS ===
    private const TOML_FILES = ['shopify.app.toml', 'shopify.app.subscriptions.toml'];

    private const TOPICS = ['customers/data_request', 'customers/redact', 'shop/redact'];

    public function test_each_app_config_declares_the_compliance_topics_uncommented(): void
    {
        foreach (self::TOML_FILES as $file) {
            $toml = (string) file_get_contents(base_path($file));

            $this->assertMatchesRegularExpression(
                '/^\s*compliance_topics\s*=\s*\[(?<list>[^\]]*)\]\s*\R\s*uri\s*=\s*"\/shopify\/webhooks"/m',
                $toml,
                "{$file} must declare an uncommented compliance_topics block on /shopify/webhooks.",
            );

            preg_match('/^\s*compliance_topics\s*=\s*\[(?<list>[^\]]*)\]/m', $toml, $m);
            foreach (self::TOPICS as $topic) {
                $this->assertStringContainsString('"'.$topic.'"', $m['list'], "{$file} is missing {$topic}.");
            }
        }
    }

    public function test_the_declared_topics_are_handled_and_configured(): void
    {
        $configured = (array) config('shopify.webhook_topics');

        foreach (self::TOPICS as $topic) {
            $this->assertContains($topic, $configured);
        }

        $this->assertEqualsCanonicalizing(self::TOPICS, [
            PrivacyWebhookHandler::TOPIC_CUSTOMERS_DATA_REQUEST,
            PrivacyWebhookHandler::TOPIC_CUSTOMERS_REDACT,
            PrivacyWebhookHandler::TOPIC_SHOP_REDACT,
        ]);
    }
}
