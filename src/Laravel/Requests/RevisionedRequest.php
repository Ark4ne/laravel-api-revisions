<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Requests;

use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Exceptions\InvalidInputException;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Laravel\Validation\RuleCompiler;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionScope;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Upgrade\Upgrader;
use Illuminate\Support\ValidatedInput;
use Illuminate\Validation\Validator;

/**
 * Makes a `FormRequest` revisioned (model.md §7, D63): it declares its head body in `body()` and its head query
 * parameters in `queryParameters()` (`query()` is `Request::query()`); `rules()` validates the input in the client's
 * revision, under the names the client knows; once the rules pass, the input is upgraded to the head, and `validated()`
 * / `safe()` return it. A value the upgrade rejects fails the validation on the client's key. The `protected upgrade()`
 * hook reshapes the body at each step (`UpgradesInput`). Implements the `Revisioned` bridge: the class declares a body
 * and a query.
 *
 * `input()` / `all()` keep the input as sent: read the head from `validated()` or `safe()`.
 *
 * @phpstan-require-extends \Illuminate\Foundation\Http\FormRequest
 * @phpstan-require-implements \Ark4ne\ApiRevisions\Schema\Revisioned
 */
trait RevisionedRequest
{
    /**
     * The validated input in the head shape, body and query merged; `null` until the validation passes.
     *
     * @var array<string, mixed>|null
     */
    private ?array $revisionedHead = null;

    /**
     * The validator the upgrade is registered on.
     */
    private ?object $revisionedValidator = null;

    /**
     * @return non-empty-list<DeclarationKind>
     */
    public static function declarationKinds(): array
    {
        return [DeclarationKind::RequestBody, DeclarationKind::RequestQuery];
    }

    /**
     * The descriptors of `body()` or `queryParameters()`, the request built without input (a `FormRequest` needs none,
     * and the shape never depends on the input).
     *
     * @return array<string, Descriptor<mixed>>
     *
     * @internal called by the compiler, the rule compiler and the upgrader
     */
    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        $request = new static();

        return $kind === DeclarationKind::RequestQuery ? $request->queryParameters() : $request->body();
    }

    /**
     * The head body: the descriptors of its fields, by head key.
     *
     * @return array<string, Descriptor<mixed>>
     */
    protected function body(): array
    {
        return [];
    }

    /**
     * The head query parameters: the descriptors of its fields, by head key.
     *
     * @return array<string, Descriptor<mixed>>
     */
    protected function queryParameters(): array
    {
        return [];
    }

    /**
     * The hook of the body (model.md §7.1): reshapes what the `was()` cannot express, once per step `X → $to`.
     *
     * @param array<string, mixed> $input the body after the renames and conversions of the step, under the names of `$to`
     *
     * @return array<string, mixed> the body in the shape of `$to`
     */
    protected function upgrade(Revision $to, array $input): array
    {
        return $input;
    }

    /**
     * The rules of the body and query shapes at the current revision (model.md D62).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        [$body, $query] = $this->revisionedShapes();
        $compiler = $this->container->make(RuleCompiler::class);

        return $compiler->compile($body, $this->body()) + $compiler->compile($query, $this->queryParameters());
    }

    /**
     * The validated input in the head shape (or one key of it).
     *
     * @param array<string>|int|string|null $key
     */
    public function validated($key = null, $default = null): mixed
    {
        return data_get($this->revisionedValidated(), $key, $default);
    }

    /**
     * The validated input in the head shape, as a `ValidatedInput` (or the keys `$keys` of it).
     *
     * @param array<string>|null $keys
     *
     * @return ($keys is null ? ValidatedInput : array<string, mixed>)
     */
    public function safe(?array $keys = null): ValidatedInput|array
    {
        $input = new ValidatedInput($this->revisionedValidated());
        if ($keys === null) {
            return $input;
        }

        /** @var array<string, mixed> the keys of the head input asked for */
        return $input->only($keys);
    }

    /**
     * The validator of `FormRequest`, the upgrade registered as its last `after` hook (once per validator: one given by
     * `setValidator()` gets it too).
     *
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function getValidatorInstance()
    {
        $validator = parent::getValidatorInstance();
        if ($this->revisionedValidator !== $validator) {
            // last: the application's own `after` hooks run first, and an error of theirs skips the upgrade
            $validator->after($this->revisionedUpgrade(...));
            $this->revisionedValidator = $validator;
            // a head upgraded by a previous validator is not this one's
            $this->revisionedHead = null;
        }

        return $validator;
    }

    /**
     * The body and query shapes at the current revision; a name both declare there, or at the head (the body and query
     * heads are merged), throws.
     *
     * @return array{Shape, Shape}
     */
    private function revisionedShapes(): array
    {
        $revision = $this->container->make(RevisionScope::class)->current($this->container->make(RevisionsConfig::class)->enum);
        $shapes = $this->container->make(Shapes::class);
        $current = [];
        foreach (array_unique([$revision::latest(), $revision], SORT_REGULAR) as $at) {
            $body = $shapes->for(static::class, DeclarationKind::RequestBody, $at);
            $query = $shapes->for(static::class, DeclarationKind::RequestQuery, $at);
            foreach (array_intersect($query->names(), $body->names()) as $name) {
                throw DefinitionException::declaredTwice(static::class, $name, $at);
            }
            $current = [$body, $query];
        }

        return $current;
    }

    /**
     * Upgrades the validated input to the head when the validation passed: the query parameters (by their names at
     * the revision) and the body each in its shape, the hook on the body only; a rejected value fails the validation
     * on its key, with the reason given or the message of the `in` rule (model.md D63).
     */
    private function revisionedUpgrade(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }
        [$bodyShape, $queryShape] = $this->revisionedShapes();
        /** @var array<string, mixed> $body the validated input is keyed by the names of the rules */
        $body = $validator->validated();
        $query = [];
        foreach ($queryShape->fields as $field) {
            if (array_key_exists($field->name, $body)) {
                $query[$field->name] = $body[$field->name];
                unset($body[$field->name]);
            }
        }
        $upgrader = $this->container->make(Upgrader::class);
        try {
            $this->revisionedHead = $upgrader->upgrade($bodyShape, $this->body(), $body, new RequestHook($this->upgrade(...)))
                + $upgrader->upgrade($queryShape, $this->queryParameters(), $query);
        } catch (InvalidInputException $rejection) {
            if ($rejection->reason === null) {
                $validator->addFailure($rejection->key, 'in');
            } else {
                $validator->errors()->add($rejection->key, $rejection->reason);
            }
        }
    }

    /**
     * The validated input in the head shape; the validation (and the upgrade with it) run first when they have not
     * (a failure throws its `ValidationException`).
     *
     * @return array<string, mixed>
     */
    private function revisionedValidated(): array
    {
        // first: a validator given by `setValidator()` gets the upgrade, and the head of the previous one is dropped
        $validator = $this->getValidatorInstance();
        if ($this->revisionedHead === null) {
            $validator->validated();
        }

        return $this->revisionedHead ?? [];
    }
}
