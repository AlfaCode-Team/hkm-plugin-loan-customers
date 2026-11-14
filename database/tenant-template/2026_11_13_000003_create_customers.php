<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

/**
 * Borrowers.
 *
 * Nested value objects (guarantor, next of kin, captured documents) are JSON
 * columns rather than child tables. That is deliberate: the phone holds and
 * syncs a whole customer document, so a child table would mean the server
 * decomposing and recomposing a shape the client treats as one record — an
 * impedance mismatch the sync protocol would then have to describe. Everything
 * a report or a list filters on stays a real column.
 *
 * Addresses are four levels of Uganda's administrative tree, stored as NAMES
 * rather than ids, because the tree is published data the app ships and names
 * are what a receipt and a field officer read. County is deliberately absent:
 * it is a parliamentary constituency, not part of an address.
 */
/**
 * JSON-SHAPED COLUMNS ARE `longText`, NOT `json`, ON PURPOSE.
 *
 * The kernel supports MySQL, PostgreSQL, SQLite and SQL Server, and a `json`
 * column means a different thing on each: MySQL and PostgreSQL REFUSE a value
 * that does not parse, SQLite stores whatever it is handed, and SQL Server has
 * no JSON type at all (it compiles to NVARCHAR(MAX) and validates nothing). So
 * `json` would give this application three different behaviours depending on
 * where it was deployed.
 *
 * It is the permissive one that is correct here. These columns carry what v1
 * and the Firestore import hand over, and some of that does not parse — the
 * Customers suite pins the behaviour the app must have when it meets a blob it
 * cannot read: keep it, show what can be shown, and never silently discard it.
 * A column that rejects the row instead would fail the import, which is the
 * one outcome with no recovery.
 *
 * `longText` holds the same 4GB on MySQL that `json` did, and reads the same
 * on all four. Nothing queries these server-side; every one of them is decoded
 * in PHP.
 */
return new class implements MigrationInterface {

    public function up($schema): void
    {
        $schema->create('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();          // client-minted
            $table->string('customer_number', 32)->nullable();    // printed reference
            $table->string('first_name', 120);
            $table->string('last_name', 120)->nullable();
            $table->string('full_name', 240);                     // what lists and receipts read
            $table->string('phone', 32)->nullable();
            $table->string('alt_phone', 32)->nullable();
            $table->string('national_id', 64)->nullable();
            $table->string('gender', 16)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('occupation', 120)->nullable();
            $table->string('business_type', 120)->nullable();

            // Address — the four levels, plus the derived one-line summary the
            // list rows and the district filter read. Built by the client with
            // locationSummary(), never joined by hand.
            $table->string('district', 120)->nullable();
            $table->string('subcounty', 120)->nullable();
            $table->string('parish', 120)->nullable();
            $table->string('village', 120)->nullable();
            $table->string('location', 255)->nullable();

            $table->unsignedBigInteger('branch_id')->nullable();

            // The officer this client belongs to — the round they are on. A
            // different question from a LOAN's officer (who collects that
            // loan), and the services keep the two in step for open loans.
            $table->unsignedBigInteger('field_officer_id')->nullable();

            $table->unsignedBigInteger('savings_balance')->default(0);
            $table->string('photo_path', 255)->nullable();

            $table->longText('guarantor')->nullable();                // {name, phone, relationship, address…}
            $table->longText('next_of_kin')->nullable();              // legacy shape; new records save null
            $table->longText('documents')->nullable();                // [{kind, path, capturedAt}]

            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();
            $table->date('registered_on')->nullable();
            $table->unsignedBigInteger('registered_by')->nullable();

            $table->unsignedBigInteger('rev')->default(0);
            $table->string('device_id', 64)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('deleted_at')->nullable();

            $table->index(['rev'], 'idx_customers_rev');
            $table->index(['field_officer_id'], 'idx_customers_officer');
            $table->index(['branch_id'], 'idx_customers_branch');
            $table->index(['phone'], 'idx_customers_phone');
            $table->index(['district'], 'idx_customers_district');
            $table->index(['full_name'], 'idx_customers_name');
        });
    }

    public function down($schema): void
    {
        $schema->dropIfExists('customers');
    }
};
