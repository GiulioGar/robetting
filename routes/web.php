<?php

use App\Http\Controllers\Admin\FootballDataCoUkSeasonImportController;
use App\Http\Controllers\Admin\FootballDataCoUkUploadController;
use App\Http\Controllers\Admin\LiveScoreSyncController;
use App\Http\Controllers\CompetitionOverviewController;
use App\Http\Controllers\CompetitionSeasonZoneController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\TeamController;
use App\Http\Middleware\EnsureLocalEnvironment;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])
    ->name('home');

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

// Temporary gate: no admin auth exists yet, so these routes are local-only.
// Remove EnsureLocalEnvironment once real admin authentication is introduced.
Route::prefix('admin/imports')->name('admin.imports.')->middleware(EnsureLocalEnvironment::class)->group(function () {
    Route::get('football-data-co-uk', [FootballDataCoUkUploadController::class, 'index'])
        ->name('football-data-co-uk.index');

    Route::post('football-data-co-uk', [FootballDataCoUkUploadController::class, 'store'])
        ->name('football-data-co-uk.store');

    Route::post('football-data-co-uk/{season}/import', FootballDataCoUkSeasonImportController::class)
        ->name('football-data-co-uk.import');

    Route::post('sync-live-scores', LiveScoreSyncController::class)
        ->name('sync-live-scores');
});
