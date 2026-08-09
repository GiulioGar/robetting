<?php

namespace App\Services\Matches;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CanonicalMatchResolver
{
    private const SCORE_FIELDS = [
        'home_score_ht',
        'away_score_ht',
        'home_score_ft',
        'away_score_ft',
    ];

    private const FILL_ONLY_FIELDS = [
        'home_score_et',
        'away_score_et',
        'home_score_penalties',
        'away_score_penalties',
    ];

    public function resolve(
        int $competitionId,
        int $seasonId,
        int $homeTeamId,
        int $awayTeamId,
        DataSource $source,
        string $externalId,
        array $incomingFields,
        MatchFieldPolicy $policy,
        string $logContext = '',
    ): MatchResolveResult {
        return DB::transaction(function () use (
            $competitionId, $seasonId, $homeTeamId, $awayTeamId,
            $source, $externalId, $incomingFields, $policy, $logContext,
        ): MatchResolveResult {
            $extId = MatchExternalId::where('data_source_id', $source->id)
                ->where('external_id', $externalId)
                ->first();

            if ($extId) {
                $match = FootballMatch::find($extId->match_id);
                $this->applyFieldUpdates($match, $incomingFields, $policy, $logContext);
                return new MatchResolveResult('updated', $match);
            }

            $candidates = FootballMatch::where('competition_id', $competitionId)
                ->where('season_id', $seasonId)
                ->where('home_team_id', $homeTeamId)
                ->where('away_team_id', $awayTeamId)
                ->get();

            if ($candidates->count() === 0) {
                $match = FootballMatch::create($incomingFields);
                MatchExternalId::create([
                    'match_id'       => $match->id,
                    'data_source_id' => $source->id,
                    'external_id'    => $externalId,
                    'external_name'  => null,
                ]);
                return new MatchResolveResult('created', $match);
            }

            if ($candidates->count() === 1) {
                $match = $candidates->first();
                $this->checkKickoffMismatch($match, $incomingFields['kickoff_at'] ?? null, $logContext);
                $this->applyFieldUpdates($match, $incomingFields, $policy, $logContext);
                MatchExternalId::create([
                    'match_id'       => $match->id,
                    'data_source_id' => $source->id,
                    'external_id'    => $externalId,
                    'external_name'  => null,
                ]);
                return new MatchResolveResult('linked', $match);
            }

            Log::warning('canonical-match-resolver: multiple candidates — skipping', [
                'context'        => $logContext,
                'competition_id' => $competitionId,
                'season_id'      => $seasonId,
                'home_team_id'   => $homeTeamId,
                'away_team_id'   => $awayTeamId,
                'candidates'     => $candidates->pluck('id')->all(),
            ]);

            return new MatchResolveResult('skipped', null);
        });
    }

    private function applyFieldUpdates(
        FootballMatch $match,
        array $incoming,
        MatchFieldPolicy $policy,
        string $logContext,
    ): void {
        $updates = [];

        // kickoff_at + kickoff_timezone
        if ($policy->kickoff === 'overwrite') {
            if (($incoming['kickoff_at'] ?? null) !== null) {
                $updates['kickoff_at']       = $incoming['kickoff_at'];
                $updates['kickoff_timezone'] = $incoming['kickoff_timezone'] ?? null;
            }
        } elseif ($match->kickoff_at === null && ($incoming['kickoff_at'] ?? null) !== null) {
            $updates['kickoff_at']       = $incoming['kickoff_at'];
            $updates['kickoff_timezone'] = $incoming['kickoff_timezone'] ?? null;
        }

        // status
        if ($policy->status === 'overwrite') {
            if (isset($incoming['status'])) {
                $updates['status'] = $incoming['status'];
            }
        } elseif (in_array($match->status, [null, 'unknown'], true) && isset($incoming['status'])) {
            $updates['status'] = $incoming['status'];
        }

        // round, matchday, venue_name: overwrite with any non-null incoming value
        foreach (['round', 'matchday', 'venue_name'] as $field) {
            if (array_key_exists($field, $incoming) && $incoming[$field] !== null) {
                $updates[$field] = $incoming[$field];
            }
        }

        // ET/PEN scores: fill-only (FDCUK never provides these)
        foreach (self::FILL_ONLY_FIELDS as $field) {
            if (array_key_exists($field, $incoming) && $incoming[$field] !== null && $match->{$field} === null) {
                $updates[$field] = $incoming[$field];
            }
        }

        // HT/FT scores: fill NULLs; warn and do not overwrite on conflict
        foreach (self::SCORE_FIELDS as $field) {
            $canonical = $match->{$field} !== null ? (int) $match->{$field} : null;
            $incomingVal = (array_key_exists($field, $incoming) && $incoming[$field] !== null)
                ? (int) $incoming[$field]
                : null;

            if ($canonical === null && $incomingVal !== null) {
                $updates[$field] = $incomingVal;
            } elseif ($canonical !== null && $incomingVal !== null && $canonical !== $incomingVal) {
                Log::warning('canonical-match-resolver: score mismatch — not overwriting', [
                    'context'   => $logContext,
                    'match_id'  => $match->id,
                    'field'     => $field,
                    'canonical' => $canonical,
                    'incoming'  => $incomingVal,
                ]);
            }
        }

        if (!empty($updates)) {
            $match->fill($updates)->save();
        }
    }

    private function checkKickoffMismatch(
        FootballMatch $match,
        mixed $incomingKickoff,
        string $logContext,
    ): void {
        if ($match->kickoff_at === null || $incomingKickoff === null) {
            return;
        }

        $incoming    = $incomingKickoff instanceof Carbon ? $incomingKickoff : Carbon::parse($incomingKickoff);
        $canonical   = $match->kickoff_at->copy()->utc();
        $diffMinutes = (int) abs($canonical->diffInMinutes($incoming));

        if ($diffMinutes > config('imports.kickoff_mismatch_warning_minutes', 15)) {
            Log::warning('canonical-match-resolver: kickoff mismatch', [
                'context'           => $logContext,
                'match_id'          => $match->id,
                'canonical_kickoff' => $canonical->toIso8601String(),
                'incoming_kickoff'  => $incoming->toIso8601String(),
                'diff_minutes'      => $diffMinutes,
            ]);
        }
    }
}
