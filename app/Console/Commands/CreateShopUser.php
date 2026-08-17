<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Give an EXISTING shop another login.
 *
 * The Team screen in the admin does this for a merchant who can already get in.
 * This is for the case that screen cannot help with: nobody can sign in to that
 * shop yet, or the operator is standing one up on a store's behalf. Same rules,
 * reached from a terminal instead of a browser.
 *
 *   php artisan lets:user:create sara@shop.com --shop=selameir.com --name="Sara"
 *
 * The shop is resolved by id OR by domain, because a human running this knows
 * the domain and would have to go looking for the id. It REFUSES an unknown
 * shop rather than creating one: a typo that silently provisioned a store would
 * be far harder to notice than an error.
 *
 * NEVER a platform admin. is_platform_admin is guarded on the model and is not
 * touched here — the flag has its own deliberate command, which is the point of
 * keeping the two apart.
 */
class CreateShopUser extends Command
{
    // === CONSTANTS ===
    protected $signature = 'lets:user:create
        {email : The login email for the new user}
        {--shop= : The shop id, or its domain (myshopify.com / WooCommerce)}
        {--name= : Display name (defaults to the part before the @)}
        {--password= : Set a password now; omit to generate one and print it once}';

    protected $description = 'Create a login for an existing shop (never a platform admin).';

    /** Long enough that a generated one is not worth guessing. */
    public const GENERATED_LENGTH = 16;

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email address is required.');

            return self::FAILURE;
        }

        $shop = $this->resolveShop();

        if ($shop === null) {
            return self::FAILURE;
        }

        // The email is the login. Two accounts sharing one would make "who signed
        // in" unanswerable, so an existing address is reported rather than reused
        // — including when it belongs to a DIFFERENT shop, which is exactly the
        // case a silent "attach" would get wrong.
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $where = $existing->shop_id === null
                ? 'the platform (no shop)'
                : 'shop #'.$existing->shop_id;

            $this->error("[{$email}] already exists on {$where}. Pick another address, or change that user in the admin.");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(self::GENERATED_LENGTH));
        $generated = ! $this->option('password');

        $user = new User;
        $user->forceFill([
            'name' => (string) ($this->option('name') ?: str($email)->before('@')->headline()),
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'shop_id' => $shop->getKey(),
            // Stated rather than left to a column default: a teammate is a
            // merchant user, and nothing here may quietly make an operator.
            'is_platform_admin' => false,
        ])->save();

        $this->info("Created [{$email}] for {$shop->name} (shop #{$shop->getKey()}).");

        if ($generated) {
            // Printed ONCE, and only when we made it up. A password the operator
            // chose is theirs to know; one we generated has to be readable
            // somewhere or the account is unusable.
            $this->line('');
            $this->warn("Password: {$password}");
            $this->line('Give it to them over something private, and have them change it.');
        }

        return self::SUCCESS;
    }

    /** By id or by domain — a human knows the domain. */
    private function resolveShop(): ?Shop
    {
        $needle = trim((string) $this->option('shop'));

        if ($needle === '') {
            $this->error('--shop is required (an id, or a domain).');
            $this->listShops();

            return null;
        }

        $shop = Shop::query()
            ->when(ctype_digit($needle), fn ($q) => $q->orWhere('id', (int) $needle))
            ->orWhere('woocommerce_domain', $needle)
            ->orWhere('shopify_domain', $needle)
            ->first();

        if ($shop === null) {
            $this->error("No shop matches [{$needle}].");
            $this->listShops();

            return null;
        }

        return $shop;
    }

    /** A refusal that names the alternatives beats one that just says no. */
    private function listShops(): void
    {
        $rows = Shop::query()
            ->orderBy('id')
            ->get(['id', 'name', 'woocommerce_domain', 'shopify_domain'])
            ->map(static fn (Shop $s): array => [
                $s->id,
                $s->name,
                $s->woocommerce_domain ?: $s->shopify_domain ?: '—',
            ])
            ->all();

        if ($rows === []) {
            $this->line('There are no shops yet.');

            return;
        }

        $this->line('');
        $this->table(['id', 'name', 'domain'], $rows);
    }
}
