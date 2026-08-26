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
}

export interface RoundPlayer extends PlayerSummary {
  present: boolean;
  drawnOut: boolean;
}

export interface GameSet {
  home: number;
  away: number;
}

export interface Game {
  id: number;
  firstPlayer: PlayerSummary;
  secondPlayer: PlayerSummary;
  thirdPlayer: PlayerSummary;
  fourthPlayer: PlayerSummary;
  firstSet: GameSet;
  secondSet: GameSet;
  thirdSet: GameSet;
  round: { id: number; number: number };
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

/** Setstanden zoals ze naar de API gaan; leeg veld = nog niet gespeeld. */
export interface GameScores {
  set1_home: number | null;
  set1_away: number | null;
  set2_home: number | null;
  set2_away: number | null;
  set3_home: number | null;
  set3_away: number | null;
}
