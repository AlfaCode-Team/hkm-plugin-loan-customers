# hkm-plugin-loan-customers

An [HKM Kernel](https://github.com/AlfaCode-Team/hkm-kernel) plugin providing
**`customers.management`** — part of the **akasente** loan-management set.

Borrowers. Owns the customers table. Every write takes a new sync rev in the same transaction, and a delete is a tombstone so handsets pull it. Addresses are the four-level Uganda tree stored as names; guarantor and documents are typed JSON with malformed blobs preserved verbatim rather than dropped.

## Install

```bash
hkm plugins install https://github.com/AlfaCode-Team/hkm-plugin-loan-customers.git
hkm plugins enable loan-customers
```

It installs as `plugins/LoanCustomers` with the namespace `Plugins\LoanCustomers\`, because
`hkm` takes the folder from `module.json`'s `name`, not from the repository.

## What it owns

Tables, shipped as `database/tenant-template/` migrations and published into a
project on enable:

- `customers`

Requires the domains: `database.management`, `sync.offline`

Publishes:

- `Plugins\LoanCustomers\API\Contracts\CustomerServiceContract`

## Routes

| Route | Name |
|---|---|
| `GET /api/customers` | `customers.index` |
| `POST /api/customers` | `customers.store` |
| `GET /api/customers/{id:num}` | `customers.show` |
| `PUT /api/customers/{id:num}` | `customers.update` |
| `PATCH /api/customers/{id:num}` | `customers.patch` |
| `DELETE /api/customers/{id:num}` | `customers.destroy` |

## Every write takes a sync revision

Each row carries `rev`, `device_id`, `created_at`, `updated_at` and
`deleted_at`. `rev` comes from `sync.offline`'s counter and is the cursor a
handset pulls against; a delete is a tombstone, never a `DELETE`. Ids are
client-minted from a claimed shard, so a phone with no signal can create a row
that will never collide with one the server made.

## Tests

```bash
HKM_KERNEL_PATH=/path/to/hkm-kernel phpunit
```

Check these out beside this repository (or install them) before running the suite: `hkm-plugin-database`, `hkm-plugin-loan-sync`.

The suite migrates the real tenant template into a throwaway database and
drives the real adapter against it. `HKM_TEST_DB` picks which database that is:

```bash
phpunit                                                  # SQLite (default, no server)
HKM_TEST_DB=mysql  HKM_TEST_DB_USERNAME=root  phpunit
HKM_TEST_DB=pgsql  HKM_TEST_DB_PORT=5432      phpunit
HKM_TEST_DB=sqlsrv HKM_TEST_DB_USERNAME=sa    phpunit
```

The account needs permission to CREATE DATABASE; each run provisions
`hkm_test_<suite>_<random>` and drops it in teardown.

**Run at least one non-SQLite driver before believing a green suite.** SQLite
has no strict mode, no real column types, it tolerates a named placeholder used
twice, and it serialises writers for free — so it hides the whole class of
defect this code is most likely to have.

## Licence

MIT. See [LICENSE](LICENSE).
