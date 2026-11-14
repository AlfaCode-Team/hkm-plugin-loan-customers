<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanCustomers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\LoanCustomers\API\DTO\CustomerSearch;
use Plugins\LoanCustomers\API\Exceptions\CustomerNotFoundException;
use Plugins\LoanCustomers\Application\Services\CustomerService;
use Plugins\LoanCustomers\Domain\UgandanPhone;
use Tests\Unit\Plugins\LoanCustomers\Support\CustomerHarness;

#[CoversClass(CustomerService::class)]
final class CustomerServiceTest extends TestCase
{
    private ?CustomerHarness $harness = null;

    protected function tearDown(): void
    {
        // Positively identified: the one file this test created.
        $this->harness?->destroy();
        $this->harness = null;
    }

    private function harness(): CustomerHarness
    {
        return $this->harness ??= new CustomerHarness();
    }

    public function test_create_takes_a_rev_and_is_pullable_by_a_device(): void
    {
        $h = $this->harness();

        $created = $h->customers->create(CustomerHarness::create());

        self::assertSame(1, $created['rev'], 'the first write takes the first rev');
        self::assertSame(1, (int) $h->db->queryOne('SELECT rev FROM customers WHERE id = :id', ['id' => $created['id']])['rev']);

        $page = $h->sync->pull('device-a', 0, ['customers']);

        self::assertCount(1, $page->changes['customers'] ?? [], 'a web-created row must reach a handset');
        self::assertSame($created['id'], (int) $page->changes['customers'][0]['id']);
        self::assertSame(1, $page->cursor);
    }

    public function test_update_takes_a_new_rev_and_the_pull_cursor_moves_past_the_old_one(): void
    {
        $h = $this->harness();

        $created = $h->customers->create(CustomerHarness::create());
        $updated = $h->customers->update($created['id'], CustomerHarness::update(['phone' => '0701234567']));

        self::assertGreaterThan($created['rev'], $updated['rev'], 'an edit must take a NEW rev');
        self::assertSame('0701234567', $updated['phone']);

        $page = $h->sync->pull('device-a', $created['rev'], ['customers']);

        self::assertCount(1, $page->changes['customers'] ?? [], 'a handset past the create must still get the edit');
        self::assertSame($updated['rev'], $page->cursor);
    }

    public function test_delete_is_a_tombstone_that_keeps_the_row_and_reaches_devices(): void
    {
        $h = $this->harness();

        $created = $h->customers->create(CustomerHarness::create());
        $deleted = $h->customers->delete($created['id']);

        self::assertNotNull($deleted['deleted_at']);
        self::assertGreaterThan($created['rev'], $deleted['rev'], 'a delete takes a rev');

        $row = $h->db->queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $created['id']]);
        self::assertNotNull($row, 'the row must still exist');
        self::assertNotNull($row['deleted_at']);

        $page = $h->sync->pull('device-a', $created['rev'], ['customers']);
        self::assertCount(1, $page->changes['customers'] ?? [], 'the tombstone must travel to handsets');
        self::assertNotNull($page->changes['customers'][0]['deleted_at']);
    }

    public function test_deleting_twice_takes_no_second_rev(): void
    {
        $h = $this->harness();

        $created = $h->customers->create(CustomerHarness::create());
        $first   = $h->customers->delete($created['id']);
        $second  = $h->customers->delete($created['id']);

        self::assertSame($first['rev'], $second['rev']);
    }

    public function test_listing_excludes_tombstoned_rows(): void
    {
        $h = $this->harness();

        $keep = $h->customers->create(CustomerHarness::create(['first_name' => 'Keep', 'last_name' => 'Me']));
        $gone = $h->customers->create(CustomerHarness::create(['first_name' => 'Gone', 'last_name' => 'Now']));
        $h->customers->delete($gone['id']);

        $page = $h->customers->list(new CustomerSearch());

        self::assertSame(1, $page['total']);
        self::assertSame([$keep['id']], array_column($page['items'], 'id'));
    }

    public function test_a_tombstoned_customer_is_not_found(): void
    {
        $h = $this->harness();

        $created = $h->customers->create(CustomerHarness::create());
        $h->customers->delete($created['id']);

        $this->expectException(CustomerNotFoundException::class);
        $h->customers->find($created['id']);
    }

    public function test_phone_search_matches_partial_and_international_forms(): void
    {
        $h = $this->harness();

        $aminah = $h->customers->create(CustomerHarness::create(['first_name' => 'Aminah', 'last_name' => 'Nakato', 'phone' => '0772123456']));
        $juma   = $h->customers->create(CustomerHarness::create(['first_name' => 'Juma', 'last_name' => 'Okello', 'phone' => '0701234567']));

        $ids = fn(string $q): array => array_column($h->customers->list(new CustomerSearch(term: $q))['items'], 'id');

        self::assertSame([$aminah['id']], $ids('0772'), 'the leading digits of a number find it');
        self::assertSame([$aminah['id']], $ids('2123'), 'a middle run of digits finds it');
        self::assertSame([$aminah['id']], $ids('772 12'), 'a run that spans a space in the typed form still matches');
        self::assertSame([$juma['id']], $ids('+256 701'), 'the country code folds to the trunk digit');
        self::assertSame([], $ids('99999'), 'a number nobody has matches nobody');
        self::assertSame([], $ids('07'), 'two digits is too few to search on, so it matches nothing rather than everything');
    }

    public function test_name_search_is_case_insensitive_and_literal_about_wildcards(): void
    {
        $h = $this->harness();

        $h->customers->create(CustomerHarness::create(['first_name' => 'Aminah', 'last_name' => 'Nakato']));

        self::assertCount(1, $h->customers->list(new CustomerSearch(term: 'NAKA'))['items']);
        self::assertCount(0, $h->customers->list(new CustomerSearch(term: '%'))['items'], 'a percent sign is not a wildcard');
    }

    public function test_a_malformed_json_blob_does_not_break_the_list(): void
    {
        $h = $this->harness();

        $ok = $h->customers->create(CustomerHarness::create(['first_name' => 'Ok', 'last_name' => 'Row']));

        // A handset wrote this with no validation. Both JSON columns are broken.
        $h->db->execute(
            "INSERT INTO customers (id, customer_number, first_name, last_name, full_name, phone, status, guarantor, documents, rev, created_at, updated_at)"
            . " VALUES (900001, 'LEGACY-1', 'Broken', 'Blob', 'Broken Blob', '0772999999', 'active', '{not json', '[oops', 1, '2026-11-01 10:00:00', '2026-11-01 10:00:00')",
        );

        $page = $h->customers->list(new CustomerSearch(perPage: 100));

        self::assertSame(2, $page['total'], 'the broken row is still listed');

        $broken = array_values(array_filter($page['items'], static fn(array $c): bool => $c['id'] === 900001))[0];

        self::assertNull($broken['guarantor']);
        self::assertSame([], $broken['documents']);
        self::assertSame(['guarantor', 'documents'], $broken['data_issues'], 'the gap is reported, not hidden');
        self::assertSame('Broken Blob', $broken['full_name']);
        self::assertNotSame($ok['id'], $broken['id']);
    }

    public function test_an_edit_keeps_unreadable_json_and_legacy_next_of_kin_as_stored(): void
    {
        $h = $this->harness();

        $h->db->execute(
            "INSERT INTO customers (id, first_name, full_name, phone, status, guarantor, next_of_kin, rev, created_at, updated_at)"
            . " VALUES (900002, 'Legacy', 'Legacy', '0772888888', 'active', '{broken', '{\"name\":\"Old Kin\"}', 1, '2026-11-01 10:00:00', '2026-11-01 10:00:00')",
        );

        $h->customers->update(900002, CustomerHarness::update(['notes' => 'Visited on Friday']));

        $row = $h->db->queryOne('SELECT guarantor, next_of_kin, notes FROM customers WHERE id = 900002');

        self::assertSame('{broken', $row['guarantor'], 'an unrelated edit must not erase a damaged blob');
        self::assertSame('{"name":"Old Kin"}', $row['next_of_kin'], 'next of kin is never rewritten by this module');
        self::assertSame('Visited on Friday', $row['notes']);
    }

    public function test_setting_a_guarantor_replaces_a_damaged_blob_deliberately(): void
    {
        $h = $this->harness();

        $h->db->execute(
            "INSERT INTO customers (id, first_name, full_name, status, guarantor, rev, created_at, updated_at)"
            . " VALUES (900003, 'Legacy', 'Legacy', 'active', '{broken', 1, '2026-11-01 10:00:00', '2026-11-01 10:00:00')",
        );

        $updated = $h->customers->update(900003, CustomerHarness::update([
            'guarantor' => ['name' => 'Sarah Nambi', 'phone' => '0712345678', 'relationship' => 'Sister'],
        ]));

        self::assertSame('Sarah Nambi', $updated['guarantor']['name']);
        self::assertSame('0712345678', $updated['guarantor']['phone']);
        self::assertSame([], $updated['data_issues']);
    }

    public function test_address_is_stored_as_names_with_a_derived_location(): void
    {
        $h = $this->harness();

        $created = $h->customers->create(CustomerHarness::create([
            'address' => ['district' => 'Masaka', 'subcounty' => 'Kyanamukaaka', 'parish' => 'Kasensero', 'village' => 'Kigo'],
        ]));

        $row = $h->db->queryOne('SELECT district, subcounty, parish, village, location FROM customers WHERE id = :id', ['id' => $created['id']]);

        self::assertSame('Masaka', $row['district']);
        self::assertSame('Kigo, Kasensero, Kyanamukaaka, Masaka', $row['location']);
    }

    public function test_a_level_cannot_be_filled_under_an_empty_level(): void
    {
        $this->expectException(ValidationException::class);

        CustomerHarness::create(['address' => ['village' => 'Kigo']]);
    }

    public function test_savings_is_integer_ugx_and_never_negative(): void
    {
        $h = $this->harness();

        $created = $h->customers->create(CustomerHarness::create(['savings_balance' => '50000']));
        self::assertSame(50000, $created['savings_balance']);

        $this->expectException(ValidationException::class);
        CustomerHarness::update(['savings_balance' => -1]);
    }

    public function test_validation_reports_every_bad_field_at_once(): void
    {
        try {
            CustomerHarness::create([
                'phone'         => '0212345678',
                'national_id'   => 'short',
                'date_of_birth' => '1990-02-30',
                'status'        => 'frozen',
            ]);
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $fields = array_keys($e->errors);
            sort($fields);

            self::assertSame(['date_of_birth', 'national_id', 'phone', 'status'], $fields);
        }
    }

    public function test_a_blank_first_name_is_refused_on_create_and_on_update(): void
    {
        $h = $this->harness();

        try {
            CustomerHarness::create(['first_name' => '  ']);
            self::fail('a blank first name must be refused on create');
        } catch (ValidationException) {
        }

        $created = $h->customers->create(CustomerHarness::create());

        $this->expectException(ValidationException::class);
        $h->customers->update($created['id'], CustomerHarness::update(['first_name' => '']));
    }

    /**
     * A blank stored name must not become a real one.
     *
     * `first_name` is NOT NULL but a handset can write an empty string, and
     * the profile object refuses to exist without a name — so the repository
     * substitutes a placeholder to keep list pages rendering. The danger is
     * the write path: editing the customer's PHONE NUMBER would save that
     * placeholder back, renaming a real person to "Unnamed customer" with
     * nothing left to recover what they were called.
     */
    public function test_a_blank_stored_name_is_never_written_back_as_the_placeholder(): void
    {
        $h = $this->harness();

        $created = $h->customers->create(CustomerHarness::create());

        // What a handset with a half-filled form actually leaves behind.
        $h->db->execute(
            'UPDATE customers SET first_name = :first, full_name = :full WHERE id = :id',
            ['first' => '', 'full' => '', 'id' => $created['id']],
        );

        // An edit that has nothing to do with the name.
        $h->customers->update($created['id'], CustomerHarness::update(['phone' => '0772555444']));

        $row = $h->db->queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $created['id']]);

        self::assertSame('', $row['first_name'], 'the blank name is preserved, not overwritten');
        self::assertStringNotContainsString('Unnamed', (string) $row['first_name']);
        self::assertStringNotContainsString('Unnamed', (string) $row['full_name']);
        self::assertSame('0772555444', $row['phone'], 'and the edit that was asked for still landed');
    }

    public function test_web_created_ids_come_from_the_servers_own_shard(): void
    {
        $h = $this->harness();

        $a = $h->customers->create(CustomerHarness::create());
        $b = $h->customers->create(CustomerHarness::create());

        // Shard 999_999 belongs to the server. Shard 0 is the LEGACY range the
        // Firestore import writes into, and minting here used to mean a web
        // row and an imported row could be handed the same id.
        self::assertSame(999_999_000_000, $a['id']);
        self::assertSame(999_999_000_001, $b['id']);
        self::assertGreaterThanOrEqual(999_999_000_000, $b['id'], 'never the legacy range');
        self::assertLessThanOrEqual(999_999_999_999, $b['id'], 'never another shard');
    }

    public function test_a_missing_customer_is_not_found_and_takes_no_rev(): void
    {
        $h = $this->harness();

        $before = $h->sync->pull('device-a', 0, ['customers'])->cursor;

        try {
            $h->customers->update(424242, CustomerHarness::update(['notes' => 'x']));
            self::fail('expected not found');
        } catch (CustomerNotFoundException) {
        }

        self::assertSame($before, $h->sync->pull('device-a', 0, ['customers'])->cursor, 'a refused write must not burn a rev');
    }

    public function test_phone_normalisation_accepts_the_forms_people_type(): void
    {
        foreach (['0772123456', '0772 123 456', '0772-123-456', '+256772123456', '256772123456', '772123456'] as $input) {
            self::assertSame('0772123456', UgandanPhone::from($input)->value(), $input);
        }

        foreach (['0212345678', '07721', 'abc', ''] as $bad) {
            try {
                UgandanPhone::from($bad);
                self::fail("'{$bad}' should be refused");
            } catch (\DomainException) {
            }
        }

        self::assertFalse(UgandanPhone::fromStored('0212 345')->isCanonical(), 'a stored landline is kept, not refused');
    }
}
