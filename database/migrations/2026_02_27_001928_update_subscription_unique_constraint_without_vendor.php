<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1 — Drop FKs using raw SQL (safe to ignore if not exist)
        $this->dropFKIfExists('subscriptions', 'subscriptions_product_id_foreign');
        $this->dropFKIfExists('subscriptions', 'subscriptions_vendor_id_foreign');

        // Step 2 — Drop old unique indexes using raw SQL
        $this->dropIndexIfExists('subscriptions', 'unique_subscription_record');
        $this->dropIndexIfExists('subscriptions', 'subscription_business_unique');

        // Step 3 — Clean up existing duplicates on new 4-col key (keep earliest id)
        DB::statement("
            DELETE s1 FROM subscriptions s1
            INNER JOIN subscriptions s2
            ON  s1.product_id   = s2.product_id
            AND s1.client_id    = s2.client_id
            AND s1.amount       = s2.amount
            AND s1.renewal_date = s2.renewal_date
            AND s1.id > s2.id
        ");

        // Step 4 — Add 4-column unique constraint (NO vendor_id)
        DB::statement("
            ALTER TABLE subscriptions
            ADD UNIQUE KEY `unique_subscription_record`
            (`product_id`, `client_id`, `amount`, `renewal_date`)
        ");

        // Step 5 — Re-add foreign keys
        DB::statement("
            ALTER TABLE subscriptions
            ADD CONSTRAINT `subscriptions_product_id_foreign`
            FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
        ");
        DB::statement("
            ALTER TABLE subscriptions
            ADD CONSTRAINT `subscriptions_vendor_id_foreign`
            FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`id`) ON DELETE CASCADE
        ");
    }

    public function down(): void
    {
        $this->dropFKIfExists('subscriptions', 'subscriptions_product_id_foreign');
        $this->dropFKIfExists('subscriptions', 'subscriptions_vendor_id_foreign');
        $this->dropIndexIfExists('subscriptions', 'unique_subscription_record');

        DB::statement("
            ALTER TABLE subscriptions
            ADD UNIQUE KEY `unique_subscription_record`
            (`product_id`, `client_id`, `vendor_id`, `renewal_date`, `amount`)
        ");
        DB::statement("
            ALTER TABLE subscriptions
            ADD CONSTRAINT `subscriptions_product_id_foreign`
            FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
        ");
        DB::statement("
            ALTER TABLE subscriptions
            ADD CONSTRAINT `subscriptions_vendor_id_foreign`
            FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`id`) ON DELETE CASCADE
        ");
    }

    private function dropFKIfExists(string $table, string $fk): void
    {
        $exists = DB::select("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND CONSTRAINT_NAME = ?
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ", [$table, $fk]);

        if (!empty($exists)) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk}`");
        }
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        $exists = DB::select("
            SELECT INDEX_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
        ", [$table, $index]);

        if (!empty($exists)) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }
};
