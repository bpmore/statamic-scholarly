<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Researchers
    |--------------------------------------------------------------------------
    |
    | Which collection holds researchers, and which field on it holds each
    | identifier. The defaults are ONE reasonable naming scheme, not a standard.
    | Every handle here is expected to differ between sites, and the addon has
    | to work when they all do.
    |
    | A wrong handle fails silently: the field reads empty, no publications are
    | found, and that is indistinguishable from a researcher who has published
    | nothing. Check these first when a fetch finds less than you expect.
    |
    */
    'researchers' => [
        'collection' => 'people',
        'fields' => [
            'orcid' => 'orcid_id',
            'pubmed_author' => 'pubmed_author_id',
            'scopus_author' => 'scopus_author_id',
            'researcher_id' => 'researcherid',
            'google_scholar' => 'google_scholar_author_id',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Publications
    |--------------------------------------------------------------------------
    |
    | The collection this addon creates and owns. It does not adopt a
    | collection you already have.
    |
    */
    'publications' => [
        'collection' => 'publications',
    ],

    /*
    |--------------------------------------------------------------------------
    | NCBI E-utilities
    |--------------------------------------------------------------------------
    |
    | `email` is REQUIRED and there is no default. NCBI asks every client to
    | identify itself so they can make contact before blocking a heavy one, and
    | an addon installed on many sites that skipped this would make all of them
    | anonymous traffic. Scholarly refuses to query without it rather than
    | querying anonymously.
    |
    | `api_key` is optional and raises the ceiling from three requests a second
    | to ten. Get one from an NCBI account.
    |
    | `verify_pmid_exists_on_validation` makes PMID validation call PubMed to
    | confirm the record is real. Off by default: it puts a network call in the
    | middle of form validation, which is a reasonable trade on one site and a
    | poor default for everyone.
    |
    */
    'ncbi' => [
        'tool' => 'statamic-scholarly',
        'email' => env('SCHOLARLY_NCBI_EMAIL'),
        'api_key' => env('SCHOLARLY_NCBI_API_KEY'),
        'verify_pmid_exists_on_validation' => false,
        'cache_ttl_seconds' => 604_800,
        'request_timeout_seconds' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | A source with no credential disables itself, says so in the run report,
    | and does not fail the run. It is never silently skipped.
    |
    */
    'sources' => [
        // ORCID's public API needs no credential. A read-public token only
        // raises the rate ceiling, so the source runs without one. `base_url`
        // exists for the sandbox (https://pub.sandbox.orcid.org/v3.0/).
        'orcid' => [
            'enabled' => true,
            'access_token' => env('SCHOLARLY_ORCID_ACCESS_TOKEN'),
            'base_url' => env('SCHOLARLY_ORCID_BASE_URL', 'https://pub.orcid.org/v3.0/'),
        ],
        'pubmed' => ['enabled' => true],
        'scopus' => ['enabled' => false, 'api_key' => env('SCOPUS_API_KEY')],
        'web_of_science' => ['enabled' => false, 'api_key' => env('WOS_API_KEY')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Affiliation patterns
    |--------------------------------------------------------------------------
    |
    | Strings matched against an author's affiliation to raise confidence that a
    | name-matched paper is really this researcher's.
    |
    | Ships EMPTY on purpose. A default naming any real institution would be
    | wrong for every other site, and quietly so.
    |
    */
    'affiliation_patterns' => [],

    /*
    |--------------------------------------------------------------------------
    | Scoring
    |--------------------------------------------------------------------------
    |
    | `auto_approve` lets a candidate at or above the threshold be approved
    | without a person, and appear on public profiles. Only a number that
    | means something counts: an ORCID anchor (1.0 by definition) or a
    | calibrated probability. The shipped prior never approves anything,
    | whatever it says, because an uncalibrated 0.90 is an ordering, not nine
    | in ten. An auto-approved entry says so (`auto_approved`, no verifier)
    | and a person can still reject it.
    |
    | `discard_floor` sets a candidate aside when a calibrated probability is
    | below it: still a candidate, still confirmable, but left out of the
    | digest mail and filterable out of the queue ("Set aside on score"),
    | so a common name does not bury a verifier. Never deleted, and asked
    | about again if a later rescoring lifts it above the floor. Null or 0
    | turns it off. The prior never sets anything aside.
    |
    | `scholarly:calibrate` fits the model to this site's confirm and reject
    | decisions, pooled across researchers, and refuses below `min_labels`
    | decisions with `min_labels_per_class` of each kind. Until then every
    | candidate is scored by the shipped prior and says "uncalibrated".
    |
    */
    'scoring' => [
        'auto_approve' => true,
        'auto_approve_threshold' => 0.90,
        'discard_floor' => 0.30,
        'min_labels' => 30,
        'min_labels_per_class' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Feed
    |--------------------------------------------------------------------------
    |
    | A public JSON feed of each researcher's APPROVED publications at
    | /!/statamic-scholarly/feed/{researcher}. The same data the tag renders,
    | nothing more. Off, the route answers 404.
    |
    */
    'feed' => [
        'enabled' => true,
    ],

    'schedule' => 'daily',

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | `scholarly:notify` mails whoever verifies for a researcher (the
    | `scholarly_verifier` field on the researcher entry) when candidates are
    | waiting. `fallback` is who gets it when a researcher has no verifier;
    | with neither, the candidates are logged and nobody is mailed.
    |
    | `digest` sends one mail per verifier covering every researcher they
    | verify for. Off, it is one mail per researcher.
    |
    | Add 'webhook' to `channels` and set `webhook_url` to also POST each
    | digest as JSON, signed with HMAC-SHA256 of the body under
    | `webhook_secret` in the X-Scholarly-Signature header. 'webhook' with
    | no URL is dropped, not sent nowhere. A failed post is logged and does
    | not stop the mail.
    |
    | There is no in-panel "bell": Statamic 6 has no notification inbox. The
    | count of candidates waiting is shown on the "Publication review" nav
    | item instead, for users who can verify.
    |
    */
    'notifications' => [
        'channels' => ['mail'],
        'digest' => true,
        'fallback' => env('SCHOLARLY_VERIFIER_EMAIL'),
        'webhook_url' => env('SCHOLARLY_WEBHOOK_URL'),
        'webhook_secret' => env('SCHOLARLY_WEBHOOK_SECRET'),
    ],

];
