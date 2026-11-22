---
title: Laravel API Revisions
description: Change your Laravel API without breaking the clients built against an older revision of it.
---

## Why Laravel API Revisions

Every change to a response breaks a client built against the old one, and copying resources per version
multiplies the code. Declare the latest shape once; each client keeps the revision it was built against.

::card-group
:::card{title="A revision per client" icon="i-lucide-route" to="/laravel-api-revisions/revisions/resolving-the-revision"}
Picked from a header, the path or the query, with `Deprecation` and `Sunset` headers from its `#[Meta]`.
:::
:::card{title="History on the field" icon="i-lucide-history" to="/laravel-api-revisions/evolving-the-api/renaming-and-retyping"}
Only the fields that changed carry their past: `since()`, `until()`, `was()`. No copied resource, no `if` per
version.
:::
:::card{title="Requests upgraded" icon="i-lucide-arrow-up-circle" to="/laravel-api-revisions/declaring-the-head/requests"}
Validated with the names the client knows, upgraded to the latest shape: controllers handle one input.
:::
:::card{title="Checked and locked" icon="i-lucide-shield-check" to="/laravel-api-revisions/tooling/check"}
`revision:check` and the lock fail the build on a forgotten `since()`, before a published revision changes.
:::
::

## One request, two revisions

The same controller and the same `OrderResource`, asked by a client on `spring-26` and one on `genesis`.

::http-call{method="GET" url="/orders/1" :headers='{"Api-Revision":"spring-26"}' status="200"}
#response
```json
{
  "data": {
    "title": "Lamp",
    "status": "paid",
    "total": 1250,
    "lines": [{ "sku": "L-1", "quantity": 2 }],
    "customer": { "name": "Ada Lovelace", "email": "ada@example.com" },
    "first_name": "Ada",
    "last_name": "Lovelace",
    "tags": ["gift"]
  }
}
```
::

::http-call{method="GET" url="/orders/1" :headers='{"Api-Revision":"genesis"}' status="200"}
#response
```json
{
  "data": {
    "title": "Lamp",
    "paid": true,
    "total": 12.5,
    "lines": [{ "sku": "L-1", "qty": 2 }],
    "customer": { "name": "Ada Lovelace", "email": "ada@example.com" },
    "name": "Ada Lovelace"
  }
}
```
::

::tip
The resource declares only the `spring-26` shape. The [quick start](/laravel-api-revisions/getting-started/quick-start)
builds it, with the `winter-25` revision in between.
::

## Compatibility

Laravel resources and form requests, with a pure-PHP core.

::compatibility
::
