# laravel-api-revisions

Typed schemas and their evolution through ordered API revisions, Stripe-style, for Laravel.

Your code declares the latest shape of a response or a request. Older shapes are declared on the fields that changed
(`since()`, `until()`, `was()`), and the library derives from that single declaration the response of every revision,
the validation and the upgrade of every request to the latest shape, a JSON Schema per revision, a changelog, a
consistency check and a lock of the published revisions.

```php
'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state'),
```

> **Status: in development.** Nothing is released yet. The behaviour is specified in
> [`docs/specs/model.md`](docs/specs/model.md) and the plan in [`docs/specs/epics.md`](docs/specs/epics.md).

Requirements: PHP 8.3+, Laravel 12.46+ or 13.

## Custom types and PHPStan

A custom type is a `Schema\Descriptor` registered at boot, then available on `Schema`:

```php
TypeRegistry::register('money', MoneySchema::class);   // in a service provider's boot()

'total' => Schema::money('total_cents'),
```

PHPStan does not know `Schema::money()` by itself. The package ships an extension (`extension.neon`, included
automatically by `phpstan/extension-installer`, or by hand with
`includes: [vendor/ark4ne/laravel-api-revisions/extension.neon]`); map each registered name to its class:

```neon
parameters:
    apiRevisions:
        types:
            money: App\Schema\MoneySchema
```

`Schema::money(...)` then takes the parameters of `MoneySchema`'s constructor and returns a `MoneySchema`; an
unmapped name stays an undefined method. A generic descriptor (`@template`) is typed as its raw class.

## License

MIT.
