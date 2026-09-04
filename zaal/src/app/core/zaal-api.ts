import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, firstValueFrom } from 'rxjs';
import { FillCandidates, Game, GameScores, NewPlayer, PlayerSummary, RoundState } from './models';
import { shortLabels } from './player-names';

/** Hoe lang de teller een nieuwe aanwezige mag vieren. */
const PULSE_MS = 600;

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

  /** Games waarvoor op dit moment een score onderweg is naar de server. */
  private readonly inFlight = signal<readonly number[]>([]);

  /**
   * Voorstellen uit de laatste loting die nog bevestigd moeten worden.
   *
   * Waarom hier en niet in het scherm dat ze toont: de loting begint bij de
   * aanwezigheidslijst en eindigt op het wedstrijdenscherm, dus de lijst moet een
   * navigatie overleven. En een bevestiging stuurt de voorstellen niet meer mee
   * in het antwoord, dus de server kan er niet aan herinneren.
   */
  private readonly pending = signal<PlayerSummary[][]>([]);

  /**
   * Kort waar na een aanmelding, zodat de teller de nieuwe aanwezige kan
   * bevestigen. Die teller staat in de tabbalk, dus in een ander component dan de
   * tegel die aangetikt werd: dit is de toestand die ze delen.
   */
  private readonly arrival = signal(false);

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
  readonly pendingGames = this.pending.asReadonly();
  readonly justArrived = this.arrival.asReadonly();

  /** Games zonder volledige score: waar de organisator nog achter moet. */
  readonly gamesWithoutScore = computed(() => this.games().filter((game) => !game.isComplete));

  /** Iedereen die vanavond op een scherm kan komen: aanwezigen plus wie in een game staat. */
  private readonly participants = computed(() => {
    const seen = new Map<number, PlayerSummary>();

    for (const player of this.presentPlayers()) {
      seen.set(player.id, player);
    }
    for (const game of this.games()) {
      for (const player of game.players) {
        seen.set(player.id, player);
      }
    }

    return [...seen.values()];
  });

  private readonly labels = computed(() => shortLabels(this.participants()));

  /** Players who are present but not in any game yet. */
  readonly playersWithoutGame = computed(() => {
    const playing = new Set(
      this.games().flatMap((game) => game.players.map((player) => player.id)),
    );

    return this.presentPlayers().filter((player) => !playing.has(player.id));
  });

  /**
   * De kortste naam waarmee deze speler vanavond niet te verwarren is: gewoon de
   * voornaam waar die uniek is, met een aanzet van de achternaam waar niet.
   */
  nameOf(player: PlayerSummary): string {
    return this.labels().get(player.id) ?? player.firstName;
  }

  /** Staat er voor deze game nog een score onderweg? */
  isSaving(gameId: number): boolean {
    return this.inFlight().includes(gameId);
  }

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

  /**
   * Meldt iemand aan of af zónder de zaal te blokkeren.
   *
   * Waarom apart van `run()`: die zet een globale busy-vlag, en elke tegel hangt
   * aan `isBusy()`. De hele namenlijst viel dus op 45% terwijl de server antwoordde
   * — het eerste wat je na je eigen tik zag was dat álles wegviel, en pas daarna
   * dat jij groen werd. Dat is precies wat een aanmelding niet mag doen: de tik
   * krijgt hier meteen antwoord, de server hoort het daarna.
   *
   * Loopt het mis, dan halen we de echte toestand op. Wie zich aangemeld dacht te
   * hebben ziet zichzelf dan terugvallen, met de foutmelding erbij.
   */
  async setAttendance(playerId: number, present: boolean): Promise<void> {
    this.applyAttendanceLocally(playerId, present);
    this.failure.set('');

    if (present) {
      this.arrival.set(true);
      setTimeout(() => this.arrival.set(false), PULSE_MS);
    }

    try {
      this.state.set(
        await firstValueFrom(
          this.http.post<RoundState>(`/api/zaal/rounds/${this.roundId()}/attendance`, {
            playerId,
            present,
          }),
        ),
      );
    } catch (error: unknown) {
      this.failure.set(describeError(error));
      await this.loadCurrentRound();
    }
  }

  /**
   * Loot de speeldag. Matches die al bevestigd zijn houden hun spelers, dus dit
   * verdeelt enkel wie nog wacht. Loopt de loting mis, dan blijven de voorstellen
   * staan die er al wachtten.
   */
  async drawRound(): Promise<void> {
    await this.run(() => this.http.post<RoundState>(`/api/zaal/rounds/${this.roundId()}/draw`, {}));

    if (this.failure() === '') {
      this.pending.set(this.proposedGames());
    }
  }

  confirmGame(playerIds: number[]): Promise<void> {
    return this.run(() =>
      this.http.post<RoundState>(`/api/zaal/rounds/${this.roundId()}/games`, { playerIds }),
    );
  }

  /** Bevestigt één voorstel; gelukt, dan is het geen voorstel meer. */
  async confirmProposal(proposal: PlayerSummary[]): Promise<void> {
    await this.confirmGame(proposal.map((player) => player.id));

    if (this.failure() === '') {
      this.pending.update((current) => current.filter((item) => item !== proposal));
    }
  }

  /**
   * Bewaart de setstanden van één game zónder de zaal te blokkeren.
   *
   * Waarom apart van `run()`: die zet een globale busy-vlag en laat het hele
   * scherm wachten. Score-invoer gebeurt per set, en elke save laat de server het
   * volledige seizoen herrekenen — met `run()` zou elke tik een spinner geven.
   * Hier gaan de cijfers dus eerst lokaal in de toestand, zodat de tik meteen
   * antwoord krijgt, en pas daarna naar de server.
   *
   * Loopt het mis, dan halen we de echte toestand op: de speler ziet dan wat er
   * werkelijk in de databank staat en niet wat hij hoopte.
   */
  async saveScores(gameId: number, scores: GameScores): Promise<void> {
    this.applyScoresLocally(gameId, scores);
    this.inFlight.update((current) => [...current, gameId]);
    this.failure.set('');

    try {
      this.state.set(
        await firstValueFrom(this.http.put<RoundState>(`/api/zaal/games/${gameId}`, scores)),
      );
    } catch (error: unknown) {
      this.failure.set(describeError(error));
      await this.loadCurrentRound();
    } finally {
      this.inFlight.update((current) => current.filter((id) => id !== gameId));
    }
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

  /**
   * Zet de zes cijfers meteen in de lokale toestand, in dezelfde vorm waarin de
   * server ze zou terugsturen. `savedAt` blijft ongemoeid: die komt van de server,
   * en zolang de save onderweg is heet dit "aan het bewaren", niet "bewaard".
   */
  private applyScoresLocally(gameId: number, scores: GameScores): void {
    this.state.update((current) => {
      if (current === null) {
        return current;
      }

      return {
        ...current,
        games: current.games.map((game) => (game.id === gameId ? withScores(game, scores) : game)),
      };
    });
  }

  /**
   * Zet de aanwezigheid meteen in de lokale toestand, in dezelfde vorm waarin de
   * server ze zou terugsturen — de teller inbegrepen, want die staat in de tabbalk
   * en op de loten-knop.
   */
  private applyAttendanceLocally(playerId: number, present: boolean): void {
    this.state.update((current) => {
      if (current === null) {
        return current;
      }

      const players = current.players.map((player) =>
        player.id === playerId ? { ...player, present } : player,
      );

      return {
        ...current,
        players,
        presentCount: players.filter((player) => player.present).length,
      };
    });
  }

  /** Voert een aanroep uit, bewaart het resultaat en vertaalt fouten naar een leesbare melding. */
  private async run(call: () => Observable<RoundState>): Promise<void> {
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

/** Dezelfde game met nieuwe setstanden, klaar om in de toestand te schuiven. */
function withScores(game: Game, scores: GameScores): Game {
  const sets = game.sets.map((set) => ({
    ...set,
    home: { ...set.home, score: scores[`set${set.number}_home`] ?? null },
    away: { ...set.away, score: scores[`set${set.number}_away`] ?? null },
  }));

  return {
    ...game,
    sets,
    isComplete: sets.every((set) => set.home.score !== null && set.away.score !== null),
  };
}

/** Zet een HTTP-fout om in iets dat je in de zaal kan lezen. */
export function describeError(error: unknown): string {
  const response = error as {
    status?: number;
    error?: { message?: string; errors?: Record<string, string[]> };
  };

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
