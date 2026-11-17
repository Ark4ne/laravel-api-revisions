---
name: laravel-api-revisions
description: Declare and evolve versioned Laravel API resources and form requests with ark4ne/laravel-api-revisions (Stripe-style revisions). Use whenever a task adds, renames, removes or retypes a field of an API response or request, adds or retires an API revision, renders a resource outside HTTP (webhooks, jobs), resolves the revision of a request, or tests a revisioned API.
---

# laravel-api-revisions

The application declares the **head** (the latest shape) of each `JsonResource` and `FormRequest` with `Schema`
descriptors, and only the fields that changed carry their history (`since()`, `until()`, `was()`). The library derives
every older revision from it: responses are projected **down** to the client's revision, requests validated in the
client's revision and upgraded **up** to the head. Controllers only ever see the head.

- [reference.md](reference.md): every class, method, command, check code and configuration key.
- [recipes.md](recipes.md): tested examples of the common tasks.

## Rules

1. **Declare the head only.** Never shape a response or a request with `if (ApiRevision::current() ...)`: a field
   that differs between revisions gets `since()`, `until()` or `was()`. `ApiRevision::current()` is for a behaviour
   change with no schema impact (a status code, a side effect).
2. **The writing rule.** Bounds are inclusive. When the new revision `N` (the head) changes the API:

   | Change in `N` | Declaration |
   | --- | --- |
   | field added | `->since(N)` |
   | field removed | `->until(N->previous())`: keep it, with its source |
   | field renamed or retyped | `->was(N->previous(), name: ..., schema: ..., down: ..., up: ...)` |

   `was(N)` or `until(N)` is the classic mistake: it keeps the old shape in `N` itself. Each `was()` is a delta from
   the next newer shape (an argument left out keeps its value); they are ordered by revision whatever the call order,
   but writing them newest first reads like the history.
3. **A new revision is appended.** Add the case at the end of `order()` with a `#[Meta]`, never reorder `order()` and
   never edit a published case. Then, before changing any declaration, run `php artisan revision:lock`: it locks every
   revision but the head, so the previous head is frozen and a change leaking into it fails the check.
4. **A source is lazy.** It is the key the field is declared under (default), another key (`Schema::int('total_cents')`),
   a dotted path (`'customer.name'`) or a closure `($value, $subject)`. Never an evaluated value:
   `Schema::string($this->title)` reads the key *named by the title*. In a closure, read the `$subject` parameter.
5. **Value closures receive `($value, $subject)`**: sources, `down:`, `up:`. **Conditions** (`when()`, `unless()`)
   run before the value is read and receive `($subject, $attribute)`. Each may declare fewer.
6. **`down:` receives the PHP value of the newer shape**, normalized, never the wire value: an `OrderStatus` case (not
   `'paid'`), an `int`, a `DateTimeImmutable`. In a chain, it receives what the next newer `was()` produced (a
   `string` after a `was(schema: Schema::string())`). It returns the PHP value of the older shape; serialization
   comes after. `up:` mirrors it: it receives the typed value of the older shape and returns the newer one.
7. **A `was()` has no source.** An older value is always derived from the head value. When it needs more, use the
   `$subject` argument of `down:`, or keep the old field with `until()` and its own source.
8. **Read a request from `validated()` or `safe()`**, never `input()`, `all()` or `$request->field`: those still hold
   what the client sent, in its revision.
9. **`required()->since(R)` declares `default:`**, the value older clients get (`default: null` for an explicit
   `null`). Without it, `revision:check` fails (unless the application set `missing_default` to `'null'`).
10. **Conditions belong to the descriptor**: `->whenLoaded('customer')`, `->whenHas()`, `->when(fn ($subject,
    $attribute) => ...)`. A native `$this->when()`, `mergeWhen()` or `$this->whenLoaded()` is invisible to the schema
    (`native-condition`); a plain value (`'x' => $this->x`) is typed `any`, the same in every revision, and fails the
    check (`eager-value`).
11. **Outside HTTP, choose the revision and render inside it**: `ApiRevision::using($revision, function () use (...) {
    ... })`. A queued job implementing `CapturesRevision` runs in the revision of its dispatch: dispatch it in a block
    body, never from an arrow function (it would return the `PendingDispatch`, pushed after `using()` returned).
12. **Run `php artisan revision:check` after every change** and fix every error. A `lock-mismatch` means a published
    revision changed: almost always a forgotten `since(N)`. Fix the declaration; re-locking a published revision
    (`revision:lock --revision=...`) is the maintainer's decision, never a way to silence the check.
13. **Never edit `revisions.lock/` by hand.** It is written by `revision:lock` and committed.

## Workflow

1. Read the revision enum (`config('revisions.enum')`): its `order()` gives the head and `previous()`.
2. If the change must not reach the clients of the current head, add a revision first (rule 3).
3. Change the head declaration, then add the history of rule 2 on the fields the change touches.
4. `php artisan revision:check`, then the tests (`assertRevisionsAreConsistent()`, `assertRendersAtEveryRevision()`,
   `->assertMatchesRevisionSchema()`).
5. `php artisan revision:changelog` shows what each revision changed: read it back against the intent.
