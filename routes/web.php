<?php

use App\Http\Controllers\Admin\ApiFootballAdminController;
use App\Http\Controllers\CompetitionOverviewController;
use App\Http\Controllers\CompetitionSeasonZoneController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\TeamController;
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

// Admin — local only (gated in controller constructor)
Route::prefix('admin/api-football')->name('admin.api-football.')->group(function () {
    Route::get('/', [ApiFootballAdminController::class, 'dashboard'])->name('dashboard');
    Route::get('teams', [ApiFootballAdminController::class, 'teams'])->name('teams');
    Route::post('teams/sync', [ApiFootballAdminController::class, 'syncTeams'])->name('teams.sync');
    Route::get('fixtures', [ApiFootballAdminController::class, 'fixtures'])->name('fixtures');
    Route::post('fixtures/sync', [ApiFootballAdminController::class, 'syncFixtures'])->name('fixtures.sync');
    Route::get('statistics', [ApiFootballAdminController::class, 'statistics'])->name('statistics');
    Route::post('statistics/sync', [ApiFootballAdminController::class, 'syncStatistics'])->name('statistics.sync');
});
