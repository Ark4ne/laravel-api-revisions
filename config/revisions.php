<?php

declare(strict_types=1);

/*
 * ark4ne/laravel-api-revisions (docs/specs/model.md §8). Publish with:
 * php artisan vendor:publish --tag=revisions-config
 */
return [
    /*
     * The revision enum of the API: a backed enum implementing Ark4ne\ApiRevisions\Revisions\Revision (with the
     * IsRevision trait), its cases ordered by its order() method. Required.
     */
    'enum' => null,

    /*
     * The chain of resolvers reading the revision of a request, the first returning a revision wins (US 8.2): a
     * resolver class => its options, or a resolver class alone.
     */
    'resolvers' => [],

    /*
     * The revision when no resolver gives one: 'latest' (the head), 'oldest', a backed value of the enum, or a
     * resolver class. Outside a request (jobs, console), a fixed revision is the current one; a resolver class gives
     * the head (model.md D31).
     */
    'default' => 'latest',

    /*
     * The response header carrying the revision a response was rendered in.
     */
    'response_header' => 'Api-Revision',

    /*
     * What a required()->since(R) field declared without default: receives for clients older than R: 'error'
     * (nothing, reported by revision:check) or 'null' (model.md §7.3, D47).
     */
    'missing_default' => 'error',

    /*
     * Value errors (a value its type refuses, a failed conversion, a null on a non-nullable field): thrown (true), or
     * cast and reported once per class and field (false); null follows app.debug (model.md §6.3, D19, D53).
     */
    'strict_values' => null,

    /*
     * The formats of Schema::date() and Schema::dateTime() declared without format() (model.md D29).
     */
    'date_format' => 'Y-m-d',
    'date_time_format' => 'Y-m-d\TH:i:sP',

    /*
     * Where revision:check and revision:cache find the classes implementing Revisioned: the paths scanned, and the
     * classes outside them (packages, modules).
     */
    'discovery' => [
        'paths' => [app_path()],
        'classes' => [],
    ],

    /*
     * The compiled shapes written by revision:cache (run by `php artisan optimize`), and the lock of the published
     * revisions written by revision:lock.
     */
    'cache_path' => base_path('bootstrap/cache/revisions.php'),
    'lock_path' => base_path('revisions.lock'),
];
