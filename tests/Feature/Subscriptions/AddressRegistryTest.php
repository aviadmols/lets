<?php

namespace Tests\Feature\Subscriptions;

use App\Domain\Addresses\AddressRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The closed list the admin picks an address from — the same registry the
 * store's checkout picks from.
 *
 * The law worth pinning is what happens when the registry does NOT answer. An
 * empty list and a failed download look identical to a form unless it is told
 * otherwise, and a form that reads an outage as "there are no cities" refuses
 * every address in the country. So: null means "no list, no rule", and the
 * screen falls back to free text.
 */
final class AddressRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** The cities come back as select options, keyed and labelled by their own name. */
    public function test_it_reads_the_locality_list(): void
    {
        Http::fake([AddressRegistry::GOV_API.'*' => Http::response($this->cityPage(), 200)]);

        $cities = AddressRegistry::cities();

        $this->assertNotNull($cities);
        $this->assertSame('חיפה', $cities['חיפה'] ?? null);
        $this->assertSame('תל אביב - יפו', $cities['תל אביב - יפו'] ?? null);
    }

    /** A registry that does not answer is NOT an empty country. */
    public function test_an_outage_answers_null_rather_than_an_empty_list(): void
    {
        Http::fake([AddressRegistry::GOV_API.'*' => Http::response('', 503)]);

        $this->assertNull(AddressRegistry::cities());
        $this->assertSame([], AddressRegistry::streetsIn('חיפה'));
        $this->assertFalse(AddressRegistry::knowsCity('חיפה'));
    }

    /** The failure is remembered, so a dead registry is not re-asked per keystroke. */
    public function test_a_failed_download_is_not_repeated_immediately(): void
    {
        Http::fake([AddressRegistry::GOV_API.'*' => Http::response('', 500)]);

        AddressRegistry::cities();
        AddressRegistry::cities();
        AddressRegistry::cities();

        Http::assertSentCount(1);
    }

    /** Streets are asked for by the registry's OWN city code, not by the name. */
    public function test_streets_are_fetched_for_the_city_the_merchant_chose(): void
    {
        Http::fake([
            AddressRegistry::GOV_API.'*' => Http::sequence()
                ->push($this->cityPage(), 200)
                ->push($this->streetPage(), 200),
        ]);

        $streets = AddressRegistry::streetsIn('חיפה');

        $this->assertSame('הרצל', $streets['הרצל'] ?? null);

        // Haifa's registry code, not its name: the two resources join on the
        // code, and matching streets by name is what a wrong hyphen breaks.
        Http::assertSent(fn ($request): bool => str_contains(
            urldecode($request->url()),
            '"'.AddressRegistry::CITY_CODE_FIELD.'":4000',
        ));
    }

    /** A city spelled with different punctuation is still the same city. */
    public function test_it_matches_a_city_whatever_the_registry_did_with_its_hyphens(): void
    {
        Http::fake([
            AddressRegistry::GOV_API.'*' => Http::sequence()
                ->push($this->cityPage(), 200)
                ->push($this->streetPage(), 200),
        ]);

        // The list holds "תל אביב - יפו"; the merchant's copy says "תל אביב-יפו".
        $this->assertTrue(AddressRegistry::knowsCity('תל אביב-יפו'));
    }

    /** An unknown city has no street list — and that is not an error. */
    public function test_an_unknown_city_yields_no_street_list(): void
    {
        Http::fake([AddressRegistry::GOV_API.'*' => Http::response($this->cityPage(), 200)]);

        $this->assertSame([], AddressRegistry::streetsIn('נובוסיבירסק'));
        $this->assertFalse(AddressRegistry::knowsCity('נובוסיבירסק'));
    }

    /** @return array<string, mixed> */
    private function cityPage(): array
    {
        return ['result' => ['records' => [
            [AddressRegistry::CITY_FIELD => 'חיפה', AddressRegistry::CITY_CODE_FIELD => 4000],
            [AddressRegistry::CITY_FIELD => 'תל אביב - יפו', AddressRegistry::CITY_CODE_FIELD => 5000],
            // The registry really does carry rows with no code; they are not cities.
            [AddressRegistry::CITY_FIELD => 'לא ידוע', AddressRegistry::CITY_CODE_FIELD => 0],
        ]]];
    }

    /** @return array<string, mixed> */
    private function streetPage(): array
    {
        return ['result' => ['records' => [
            [AddressRegistry::STREET_FIELD => 'הרצל'],
            [AddressRegistry::STREET_FIELD => 'אליהו הנביא'],
        ]]];
    }
}
