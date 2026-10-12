<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// The SPA shell for every path except the API's: an unknown /api/... path answers 404 JSON
// instead of the HTML page.
Route::get('/{any}', function () {
    return view('app');
})->where('any', '(?!api(/|$)).*');
