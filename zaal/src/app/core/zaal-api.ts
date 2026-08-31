import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { FillCandidates, GameScores, NewPlayer, RoundState } from './models';

/**
 * Praat met de zaal-API. Elke wijziging geeft de volledige toestand van de
 * speeldag terug, dus houden we die als één signaal bij.
 */
@Injectable({ providedIn: 'root' })
export class ZaalApi {
  private readonly http = inject(HttpClient);

  private readonly state = signal<RoundState | null>(null);
  private readonly busy = signal(false);
  private readonly failure = signal<string>('');

  readonly round = computed(() => this.state()?.round ?? null);
  readonly players = computed(() => this.state()?.players ?? []);
  readonly games = computed(() => this.state()?.games ?? []);
  readonly drawnOut = computed(() => this.state()?.drawnOut ?? []);
  readonly proposedGames = computed(() => this.state()?.proposedGames ?? []);
  readonly presentPlayers = computed(() => this.players().filter((player) => player.present));
  readonly presentCount = computed(() => this.state()?.presentCount ?? 0);
  readonly latestRound = computed(() => this.state()?.latestRound ?? null);
  readonly seasonName = computed(() => this.state()?.seasonName ?? null);
  readonly isBusy = this.busy.asReadonly();
  readonly errorMessage = this.failure.asReadonly();

  /** Players who are present but not in any game yet. */
  readonly playersWithoutGame = computed(() => {
    const playing = new Set(this.games().flatMap((game) => game.players.map((player) => player.id)));

    return this.presentPlayers().filter((player) => !playing.has(player.id));
  });

  /** Starts today's matchday (or opens it when it already exists). */
  startToday(): Promise<void> {
    return this.run(() => this.http.post<RoundState>('/api/zaal/rounds', {}));
  }

  /** Deliberately opens an older matchday, e.g. to finish entering scores. */
  openRound(roundId: number): Promise<void> {
    return this.run(() => this.http.get<RoundState>(`/api/zaal/rounds/${roundId}`));
  }

  loadCurrentRound(): Promise<void> {
    return this.run(() => this.http.get<RoundState>('/api/zaal/round'));
  }

  setAttendance(playerId: number, present: boolean): Promise<void> {
    return this.run(() =>
      this.http.post<RoundState>(`/api/zaal/rounds/${this.roundId()}/attendance`, { playerId, present }),
    );
  }

  drawRound(): Promise<void> {
    return this.run(() => this.http.post<RoundState>(`/api/zaal/rounds/${this.roundId()}/draw`, {}));
  }

  confirmGame(playerIds: number[]): Promise<void> {
    return this.run(() =>
      this.http.post<RoundState>(`/api/zaal/rounds/${this.roundId()}/games`, { playerIds }),
    );
  }

  saveScores(gameId: number, scores: GameScores): Promise<void> {
    return this.run(() => this.http.put<RoundState>(`/api/zaal/games/${gameId}`, scores));
  }

  /** Candidates that can volunteer to fill up an incomplete foursome. */
  fillCandidates(): Promise<FillCandidates> {
    return firstValueFrom(
      this.http.get<FillCandidates>(`/api/zaal/rounds/${this.roundId()}/fill-candidates`),
    );
  }

  addPlayer(player: NewPlayer): Promise<void> {
    return this.run(() =>
      this.http.post<RoundState>(`/api/zaal/rounds/${this.roundId()}/players`, player),
    );
  }

  clearError(): void {
    this.failure.set('');
  }

  private roundId(): number {
    const round = this.round();
    if (round === null) {
      throw new Error('Er is nog geen speeldag geladen.');
    }

    return round.id;
  }

  /** Voert een aanroep uit, bewaart het resultaat en vertaalt fouten naar een leesbare melding. */
  private async run(call: () => import('rxjs').Observable<RoundState>): Promise<void> {
    this.busy.set(true);
    this.failure.set('');
    try {
      const result = await firstValueFrom(call());
      this.state.set(result);
    } catch (error: unknown) {
      this.failure.set(describeError(error));
    } finally {
      this.busy.set(false);
    }
  }
}

/** Zet een HTTP-fout om in iets dat je in de zaal kan lezen. */
export function describeError(error: unknown): string {
  const response = error as { status?: number; error?: { message?: string; errors?: Record<string, string[]> } };

  if (response?.error?.errors) {
    return Object.values(response.error.errors).flat().join(' ');
  }
  if (response?.error?.message) {
    return response.error.message;
  }
  if (response?.status === 0) {
    return 'Geen verbinding met de server.';
  }

  return 'Er ging iets mis. Probeer opnieuw.';
}
