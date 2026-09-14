<?php

declare(strict_types=1);

use Bpmore\Scholarly\Http\Controllers\FeedController;
use Illuminate\Support\Facades\Route;

// Mounted under /!/statamic-scholarly by the addon service provider.
Route::get('feed/{researcher}', FeedController::class)->name('statamic-scholarly.feed');
