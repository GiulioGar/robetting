<?php

use App\Http\Controllers\CompetitionOverviewController;
use App\Http\Controllers\CompetitionSeasonZoneController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/teams/{team}', [TeamController::class, 'show'])
    ->name('teams.show');

Route::get('/matches/{match}', [MatchController::class, 'show'])
    ->name('matches.show');

Route::get('/competitions/{competition:slug}', [CompetitionOverviewController::class, 'index'])
    ->name('competitions.show');

Route::get('/competitions/{competition:slug}/seasons/{season}', [CompetitionOverviewController::class, 'show'])
    ->name('competitions.seasons.show');

Route::get('/competitions/{competition:slug}/seasons/{season}/zones', [CompetitionSeasonZoneController::class, 'index'])
    ->name('competitions.seasons.zones.index');

Route::post('/competitions/{competition:slug}/seasons/{season}/zones', [CompetitionSeasonZoneController::class, 'store'])
    ->name('competitions.seasons.zones.store');

Route::patch('/competitions/{competition:slug}/seasons/{season}/zones/{zone}', [CompetitionSeasonZoneController::class, 'update'])
    ->name('competitions.seasons.zones.update');

Route::delete('/competitions/{competition:slug}/seasons/{season}/zones/{zone}', [CompetitionSeasonZoneController::class, 'destroy'])
    ->name('competitions.seasons.zones.destroy');
