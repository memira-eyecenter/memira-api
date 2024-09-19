<?php

use Illuminate\Support\Facades\Route;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/salesforce/authenticate', function () {
    return Forrest::authenticate();
});

Route::get('/salesforce/callback', function () {
    Forrest::callback();
    return redirect('/');
});
