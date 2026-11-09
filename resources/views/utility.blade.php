{{--
    The addon's state, as a page under Tools. Read-only, and nothing here
    fetches: every number is from entries the last run wrote, from config,
    or from the fitted model on disk. Settings are changed in the config
    file, and the page says where.

    Statamic compiles a utility's HTML as a Vue template, so Statamic's
    `ui-*` components work here and a literal double brace is read by Vue
    as an expression and run in the viewer's session. Every string that did
    not come from this file goes through `@plain`, not `{{ }}`: a
    researcher's title, a last-run summary (which quotes what a source
    returned), a verifier's name, a handle or address from config. Numbers,
    booleans and the words chosen here are the only things `{{ }}` prints.
    Nothing dynamic goes into a `ui-description` text prop, which is
    rendered as HTML.
--}}

@php($r = $report)

<ui-header title="Scholarly" icon="media-news-paper" />

<div class="space-y-6">

    <ui-description text="Where publications come from, who reviews them, and whether the last fetch answered. Settings live in config/statamic-scholarly.php and .env (the SCHOLARLY_ keys); this page reads them and changes nothing." />

    <ui-card-panel heading="Review queue">
        <div class="space-y-3">
            <div class="flex flex-wrap gap-2">
                <ui-badge color="{{ $r['queue']['waiting'] > 0 ? 'amber' : 'default' }}" text="{{ $r['queue']['waiting'] }} waiting" pill />
                <ui-badge text="{{ $r['queue']['deferred'] }} deferred" pill />
                <ui-badge text="{{ $r['queue']['set_aside'] }} set aside on score" pill />
                <ui-badge color="emerald" text="{{ $r['queue']['approved'] }} approved" pill />
                <ui-badge text="{{ $r['queue']['auto_approved'] }} of those on score alone" pill />
                <ui-badge text="{{ $r['queue']['rejected'] }} rejected" pill />
            </div>
            <ui-description text="Waiting means a candidate nobody has confirmed, rejected or set aside. A candidate is an estimate, not a verified publication, until a person confirms it or a calibrated score clears the threshold." />
            <div>
                <ui-button size="sm" href="@plain(cp_route('collections.show', $r['queue']['collection']))" text="Open Publication review" />
            </div>
        </div>
    </ui-card-panel>

    <ui-card-panel heading="Researchers">
        <div class="space-y-4">
            <div class="flex flex-wrap gap-2">
                <ui-badge text="{{ $r['researchers']['total'] }} in @plain($r['researchers']['collection'])" pill />
                <ui-badge color="{{ $r['researchers']['without_identifiers'] > 0 ? 'amber' : 'default' }}" text="{{ $r['researchers']['without_identifiers'] }} with no identifier" pill />
                <ui-badge color="{{ $r['researchers']['never_fetched'] > 0 ? 'amber' : 'default' }}" text="{{ $r['researchers']['never_fetched'] }} never fetched" pill />
                <ui-badge color="{{ $r['researchers']['partial'] > 0 ? 'red' : 'default' }}" text="{{ $r['researchers']['partial'] }} last run partial" pill />
                <ui-badge color="{{ $r['researchers']['nobody_to_tell'] > 0 ? 'red' : 'default' }}" text="{{ $r['researchers']['nobody_to_tell'] }} waiting with nobody to tell" pill />
            </div>
            <ui-description text="A researcher with no identifier is not one with no publications: a fetch for them must report failure, and does. PARTIAL means a source could not answer, so that list may be incomplete." />

            @if ($r['researchers']['rows'] === [])
                <ui-description text="No entries in the researchers collection yet." />
            @else
                <ui-table>
                    <ui-table-columns>
                        <ui-table-column>Researcher</ui-table-column>
                        <ui-table-column>Identifiers</ui-table-column>
                        <ui-table-column>Last fetch</ui-table-column>
                        <ui-table-column>Waiting</ui-table-column>
                        <ui-table-column>Verifier</ui-table-column>
                    </ui-table-columns>
                    <ui-table-rows>
                        @foreach ($r['researchers']['rows'] as $row)
                            <ui-table-row>
                                <ui-table-cell><a href="@plain($row['edit_url'])">@plain($row['title'])</a></ui-table-cell>
                                <ui-table-cell>
                                    @if ($row['identifiers'] === [])
                                        <ui-badge color="amber" text="none" />
                                    @else
                                        @foreach ($row['identifiers'] as $kind => $value)
                                            <ui-badge text="@plain(str_replace('_', ' ', $kind))" />
                                        @endforeach
                                    @endif
                                </ui-table-cell>
                                <ui-table-cell>
                                    @if ($row['last_run'] === null)
                                        <ui-badge text="never" />
                                    @else
                                        <ui-badge color="{{ $row['last_run']['complete'] ? 'emerald' : 'red' }}" text="{{ $row['last_run']['complete'] ? 'complete' : 'partial' }}" />
                                        <span class="text-sm">@plain($row['last_run']['at'] === null ? '' : substr($row['last_run']['at'], 0, 16)) @plain($row['last_run']['summary'])</span>
                                    @endif
                                </ui-table-cell>
                                <ui-table-cell>{{ $row['waiting'] }}</ui-table-cell>
                                <ui-table-cell>
                                    @if ($row['verifier'] !== null)
                                        @plain($row['verifier'])
                                    @elseif ($r['notifications']['fallback'] !== null)
                                        <span class="text-sm">fallback: @plain($r['notifications']['fallback'])</span>
                                    @else
                                        <ui-badge color="{{ $row['waiting'] > 0 ? 'red' : 'amber' }}" text="nobody" />
                                    @endif
                                </ui-table-cell>
                            </ui-table-row>
                        @endforeach
                    </ui-table-rows>
                </ui-table>
            @endif
        </div>
    </ui-card-panel>

    <ui-card-panel heading="Sources">
        <div class="space-y-4">
            <ui-table>
                <ui-table-columns>
                    <ui-table-column>Source</ui-table-column>
                    <ui-table-column>In config</ui-table-column>
                    <ui-table-column>Will run</ui-table-column>
                    <ui-table-column>Why not</ui-table-column>
                </ui-table-columns>
                <ui-table-rows>
                    @foreach ($r['sources']['registered'] as $s)
                        <ui-table-row>
                            <ui-table-cell><code>@plain($s['key'])</code></ui-table-cell>
                            <ui-table-cell>{{ $s['enabled'] ? 'on' : 'off' }}</ui-table-cell>
                            <ui-table-cell><ui-badge color="{{ $s['configured'] ? 'emerald' : 'amber' }}" text="{{ $s['configured'] ? 'yes' : 'no' }}" /></ui-table-cell>
                            <ui-table-cell>@plain($s['needs'] ?? '')</ui-table-cell>
                        </ui-table-row>
                    @endforeach
                    @foreach ($r['sources']['unregistered'] as $s)
                        <ui-table-row>
                            <ui-table-cell><code>@plain($s['key'])</code></ui-table-cell>
                            <ui-table-cell>{{ $s['enabled'] ? 'on' : 'off' }}</ui-table-cell>
                            <ui-table-cell><ui-badge color="{{ $s['enabled'] ? 'red' : 'default' }}" text="no" /></ui-table-cell>
                            <ui-table-cell>no source of this name is installed{{ $s['enabled'] ? ': turning it on in config fetches nothing' : '' }}</ui-table-cell>
                        </ui-table-row>
                    @endforeach
                </ui-table-rows>
            </ui-table>
            <ui-description text="A source that will not run is skipped and named in every run report; it does not fail the run, and it does not look and see nothing." />

            <div>
                <div class="font-medium">Identifier fields</div>
                <ui-description text="Which researcher fields this site maps to each identifier kind (researchers.fields). A kind with no field is why a source cannot run here." />
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($r['identifiers'] as $kind => $handle)
                        <li><code>@plain($kind)</code>: @plain($handle === null ? 'not mapped' : $handle)</li>
                    @endforeach
                </ul>
            </div>

            <div>
                <div class="font-medium">NCBI</div>
                <ul class="mt-2 space-y-1 text-sm">
                    <li>tool: <code>@plain($r['ncbi']['tool'])</code></li>
                    <li>
                        email:
                        @if ($r['ncbi']['email'] === null)
                            <ui-badge color="red" text="not set" /> NCBI requires one, and PubMed will not be queried without it (SCHOLARLY_NCBI_EMAIL)
                        @else
                            @plain($r['ncbi']['email'])
                        @endif
                    </li>
                    <li>API key: {{ $r['ncbi']['api_key'] ? 'set (ten requests a second)' : 'not set (three requests a second)' }}</li>
                </ul>
            </div>
        </div>
    </ui-card-panel>

    <ui-card-panel heading="Scoring">
        <div class="space-y-3">
            <div class="flex flex-wrap gap-2">
                <ui-badge color="{{ $r['scoring']['calibrated'] ? 'emerald' : 'amber' }}" text="{{ $r['scoring']['calibrated'] ? 'calibrated' : 'uncalibrated (shipped prior)' }}" pill />
                <ui-badge text="auto-approve {{ $r['scoring']['auto_approve'] && $r['scoring']['threshold'] !== null ? 'at '.number_format($r['scoring']['threshold'], 2) : 'off' }}" pill />
                <ui-badge text="set aside below {{ $r['scoring']['floor'] === null ? 'off' : number_format($r['scoring']['floor'], 2) }}" pill />
            </div>
            @if ($r['scoring']['calibrated'])
                <p class="text-sm">Fitted @plain($r['scoring']['fitted_at'] === null ? '' : substr($r['scoring']['fitted_at'], 0, 16).' ')on {{ $r['scoring']['positives'] ?? '?' }} confirmations and {{ $r['scoring']['negatives'] ?? '?' }} rejections.</p>
                <ui-description text="A calibrated score is a probability, not a guarantee: 0.94 means roughly one in seventeen is wrong." />
            @else
                <ui-description text="Until scholarly:calibrate has {{ $r['scoring']['min_labels'] }} decisions with {{ $r['scoring']['min_labels_per_class'] }} of each kind, every candidate is scored by the shipped prior. An uncalibrated score is an ordering, not a probability, so the prior never approves anything and never sets anything aside, whatever the threshold and floor say." />
            @endif
        </div>
    </ui-card-panel>

    <ui-card-panel heading="Notifications and schedule">
        <ul class="space-y-1 text-sm">
            <li>channels: @plain(implode(', ', $r['notifications']['channels']))@if ($r['notifications']['webhook_dropped']) (webhook is listed but has no URL, so it is dropped)@endif</li>
            <li>digest: {{ $r['notifications']['digest'] ? 'one mail per verifier' : 'one mail per researcher' }}</li>
            <li>fallback verifier: @plain($r['notifications']['fallback'] ?? 'none (SCHOLARLY_VERIFIER_EMAIL)')</li>
            @if ($r['notifications']['webhook'])
                <li>webhook: set, {{ $r['notifications']['webhook_signed'] ? 'signed' : 'unsigned (SCHOLARLY_WEBHOOK_SECRET is empty)' }}</li>
            @endif
            <li>fetch schedule: @plain($r['schedule'] ?? 'off (the host runs scholarly:fetch itself)')</li>
        </ul>
    </ui-card-panel>

</div>
