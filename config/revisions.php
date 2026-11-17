<?php

declare(strict_types=1);

/*
 * ark4ne/laravel-api-revisions. Publish with:
 * php artisan vendor:publish --tag=revisions-config
 */
return [
    /*
     * The revision enum of the API: a backed enum implementing Ark4ne\ApiRevisions\Revisions\Revision (with the
     * IsRevision trait), its cases ordered by its order() method. Left null, the library works on one implicit revision
     * (Ark4ne\ApiRevisions\Revisions\Unrevisioned): no history, no revision header, no resolver run.
     */
    'enum' => null,

    /*
     * The chain of resolvers reading the revision of a request, the first returning a revision wins: a class
     * implementing Ark4ne\ApiRevisions\Laravel\Http\RevisionResolver => its options (named constructor arguments),
     * or the class alone. An unknown value is answered with 400. Custom resolvers pin the revision of an account.
     */
    'resolvers' => [
        Ark4ne\ApiRevisions\Laravel\Http\Resolvers\HeaderResolver::class => ['name' => 'Api-Revision'],
        // Ark4ne\ApiRevisions\Laravel\Http\Resolvers\PathResolver::class => ['parameter' => 'revision'],
        // Ark4ne\ApiRevisions\Laravel\Http\Resolvers\QueryResolver::class => ['name' => 'revision'],
    ],

    /*
     * The revision when no resolver gives one: 'latest' (the head), 'oldest', a backed value of the enum, or a
     * resolver class. Outside a request (jobs, console), a fixed revision is the current one; a resolver class gives
     * the head. Without enum, only 'latest' or 'oldest' (both the implicit revision).
     */
    'default' => 'latest',

    /*
     * The response header carrying the revision a response was rendered in.
     */
    'response_header' => 'Api-Revision',

    /*
     * What a required()->since(R) field declared without default: receives for clients older than R: 'error'
     * (nothing, reported by revision:check) or 'null'.
     */
    'missing_default' => 'error',

    /*
     * Value errors (a value its type refuses, a failed conversion, a null on a non-nullable field): thrown (true), or
     * cast and reported once per class and field (false); null follows app.debug.
     */
    'strict_values' => null,

    /*
     * The formats of Schema::date() and Schema::dateTime() declared without format().
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
