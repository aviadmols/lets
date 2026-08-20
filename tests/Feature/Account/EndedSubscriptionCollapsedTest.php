<?php

namespace Tests\Feature\Account;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Account\Offers\MakesAccountOffers;
use Tests\TestCase;

/**
 * A subscription that is OVER opens folded in the personal area.
 *
 * A shopper who switched plans twice was reading four cards of equal weight to
 * find the one that still takes their money. Cancelled and completed plans are
 * records, not controls, so they open closed — while PAUSED, FAILED and
 * AWAITING_FIRST_PAYMENT stay open, because each is a plan waiting on the
 * shopper to do something and folding an action away is how a subscription
 * quietly dies.
 */
final class EndedSubscriptionCollapsedTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    #[DataProvider('statuses')]
    public function test_only_an_ended_subscription_opens_folded(string $status, bool $expected): void
    {
        $shop = $this->makeShop('collapse-'.$status.'.example.com');

        $model = Tenant::run($shop, function () use ($shop, $status): array {
            $this->makeSourcePlan(['status' => $status]);

            return app(AccountPresenter::class)->present(AccountVisitor::make(
                shop: $shop,
                customerRef: self::MEMBER_REF,
                source: AccountVisitor::SOURCE_WOOCOMMERCE,
                email: self::MEMBER_EMAIL,
            ));
        });

        $this->assertSame(
            $expected,
            $model['subscriptions'][0]['collapsed'],
            $status.' folded the wrong way'
        );
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function statuses(): array
    {
        return [
            'cancelled is history' => ['cancelled', true],
            'completed is history' => ['completed', true],
            'active is the point of the page' => ['active', false],
            // Not "active" either — but each is waiting on the shopper.
            'paused still has a resume button' => ['paused', false],
            'failed still needs a card fixed' => ['failed', false],
            'awaiting still needs a first payment' => ['awaiting_first_payment', false],
        ];
    }

    public function test_the_merchant_preview_shows_an_open_card(): void
    {
        $shop = $this->makeShop('collapse-preview.example.com');

        $sample = Tenant::run($shop, fn (): array => app(AccountPresenter::class)->sample());

        $this->assertFalse($sample['subscriptions'][0]['collapsed']);
    }
}
