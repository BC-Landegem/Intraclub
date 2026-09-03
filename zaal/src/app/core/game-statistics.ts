/**
 * Wat er van één wedstrijd naar het klassement gaat, per speler.
 *
 * Spiegelt App\Services\GameStatistics aan de serverkant: dezelfde roterende
 * teams, dezelfde trim-regel, dezelfde deling door drie. Bewust een aparte kopie
 * en geen extra endpoint — de zes cijfers staan al op het scherm, dus de zaal-app
 * kan dit zelf en heeft er geen netwerkrondje voor nodig.
 *
 * Het getal dat telt is het **gemiddelde**, niet het puntentotaal:
 * SeasonCalculator zet `averages[slot]` als het resultaat van die speeldag, en
 * daaruit volgt het voortschrijdende seizoensgemiddelde. Het ruwe puntentotaal
 * bestaat aan de serverkant ook (`pointsWon`), maar dat is een teller voor de
 * statistiek en beslist niets over de rangschikking.
 */

/**
 * De opstelling per set, als spelerposities (0-gebaseerd): [thuisduo, uitduo].
 * Set 1 = 1+2 vs 3+4, set 2 = 1+3 vs 2+4, set 3 = 1+4 vs 2+3.
 */
export const LINE_UPS: readonly [readonly [number, number], readonly [number, number]][] = [
  [
    [0, 1],
    [2, 3],
  ],
  [
    [0, 2],
    [1, 3],
  ],
  [
    [0, 3],
    [1, 2],
  ],
];

/** Eén setstand: punten van het thuisduo en van het uitduo van díe set. */
export type SetScore = readonly [number, number];

export interface PlayerTally {
  /** Gewonnen sets, 0 tot 3. Een teller, geen ranglijstgetal. */
  setsWon: number;
  /** Het getrimde puntengemiddelde over de drie sets — dít gaat naar het klassement. */
  average: number;
}

/**
 * Per spelerpositie (0..3) de gewonnen sets en het gemiddelde dat naar het
 * klassement gaat.
 *
 * Een gelijke set kan niet bestaan, dus de winnaar is altijd de hogere stand;
 * de legacy-regel "gelijk telt als winst voor het uitduo" hoort bij lege sets en
 * die komen hier niet binnen.
 */
export function gameTally(sets: readonly SetScore[], pointsPerSet: number): PlayerTally[] {
  const setsWon = [0, 0, 0, 0];
  const trimmed = [0, 0, 0, 0];

  sets.forEach(([home, away], index) => {
    const [homePair, awayPair] = LINE_UPS[index];

    for (const position of home > away ? homePair : awayPair) {
      setsWon[position]++;
    }
    for (const position of homePair) {
      trimmed[position] += trim(home, away, pointsPerSet);
    }
    for (const position of awayPair) {
      trimmed[position] += trim(away, home, pointsPerSet);
    }
  });

  return setsWon.map((won, position) => ({
    setsWon: won,
    average: trimmed[position] / sets.length,
  }));
}

/**
 * Herschaal een setscore naar de puntenschaal van het seizoen wanneer er voorbij
 * dat maximum werd gespeeld (verlengingen), zodat elke set even zwaar doorweegt
 * in het gemiddelde. Een set tot 21 die op 30-28 eindigde levert dus 21 en 19,6.
 */
function trim(score: number, opponentScore: number, pointsPerSet: number): number {
  const highest = Math.max(score, opponentScore);

  if (highest <= pointsPerSet || highest === 0) {
    return score;
  }

  return (pointsPerSet / highest) * score;
}
