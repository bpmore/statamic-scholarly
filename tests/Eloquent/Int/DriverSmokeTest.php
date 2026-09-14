<?php

declare(strict_types=1);

use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

it('really is running entries on the Eloquent driver', function (): void {
    Collection::make('faculty')->save();
    $entry = Entry::make()->collection('faculty')->slug('ada')->data(['title' => 'Ada', 'researchers' => ['x']]);
    $entry->save();

    expect($entry)->toBeInstanceOf(\Statamic\Eloquent\Entries\Entry::class)
        ->and(\Illuminate\Support\Facades\DB::table('entries')->count())->toBe(1)
        ->and(Entry::query()->whereJsonContains('researchers', 'x')->count())->toBe(1)
        ->and(Entry::find($entry->id()))->not->toBeNull();
});
