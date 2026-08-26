/** Vormen zoals de zaal-API ze teruggeeft (zie ZaalController::roundPayload). */

export interface RoundInfo {
  id: number;
  number: number;
  date: string;
  seasonId: number;
  isCalculated: boolean;
}

export interface PlayerSummary {
  id: number;
  firstName: string;
  name: string;
  fullName: string;
  /** Derived from gender, competition and doubles ranking; used when composing matches. */
  bonusPoints: number;
}

export interface RoundPlayer extends PlayerSummary {
  present: boolean;
  drawnOut: boolean;
}

/** Eén kant van een set: het duo en hun punten. */
export interface SetSide {
  players: PlayerSummary[];
  score: number | null;
  field: string;
}

export interface GameSet {
  number: number;
  home: SetSide;
  away: SetSide;
}

export interface Game {
  id: number;
  players: PlayerSummary[];
  sets: GameSet[];
  isComplete: boolean;
}

export interface RoundState {
  round: RoundInfo | null;
  players: RoundPlayer[];
  presentCount: number;
  drawnOut: RoundPlayer[];
  games: Game[];
  /** Enkel aanwezig in het antwoord van een loting. */
  proposedGames?: PlayerSummary[][];
}

export interface CurrentUser {
  id: number;
  name: string;
  email: string;
}

export interface NewPlayer {
  firstName: string;
  name: string;
  gender: 'male' | 'female';
  birthDate: string;
  playsCompetition: boolean;
  doubleRanking: number;
}

/** Setstanden zoals ze naar de API gaan; null = nog niet gespeeld. */
export type GameScores = Record<string, number | null>;

/** Candidates for filling up an incomplete foursome. */
export interface FillCandidate extends PlayerSummary {
  present: boolean;
  drawnOut: boolean;
  gamesPlayed: number;
}

export interface FillCandidates {
  drawnOut: FillCandidate[];
  present: FillCandidate[];
  others: FillCandidate[];
}
