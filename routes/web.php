<?php

use App\Http\Controllers\CompetitionOverviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/competitions/{competition:slug}', [CompetitionOverviewController::class, 'index'])
    ->name('competitions.show');

Route::get('/competitions/{competition:slug}/seasons/{season}', [CompetitionOverviewController::class, 'show'])
    ->name('competitions.seasons.show');
