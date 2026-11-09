<?php

use Illuminate\Support\Facades\Route;

//  Catch-All for SPA: every URL passes control to Vue Router
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');