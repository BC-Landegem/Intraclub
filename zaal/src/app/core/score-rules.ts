/**
 * Welke setstanden kunnen bestaan.
 *
 * Spiegelt PointsPerSet::allowsSet() aan de serverkant. De server weigert sowieso
 * wat hier zou doorglippen, dus als de twee ooit uiteenlopen kost dat hoogstens
 * een lelijkere melding — nooit foute cijfers in de databank. De getallen zelf
 * (het setmaximum en de cap) komen uit de payload, niet uit deze code.
 */

/** De regel in één zin, voor onder een geweigerde set. */
export function scoreRule(target: number, cap: number): string {
  return `Sets gaan tot ${target} met minstens 2 punten verschil, verlenging tot maximum ${cap}.`;
}

/** Kan dit ene getal punten in een set zijn? Leeg mag: die set is nog niet gespeeld. */
export function isPlayablePoints(value: number | null, cap: number): boolean {
  return value === null || (Number.isInteger(value) && value >= 0 && value <= cap);
}

/**
 * Een set gaat tot het setmaximum met minstens twee punten verschil. Staat het op
 * één punt verschil vanaf daar, dan wordt doorgespeeld tot iemand er twee
 * voorstaat — tot aan de cap, waar het volgende punt beslist.
 */
export function isPlayableSet(home: number, away: number, target: number, cap: number): boolean {
  if (home === away || home < 0 || away < 0) {
    return false;
  }

  const winner = Math.max(home, away);
  const loser = Math.min(home, away);

  if (winner === target) {
    return loser <= target - 2;
  }
  if (winner > target && winner < cap) {
    return loser === winner - 2;
  }

  return winner === cap && loser >= cap - 2;
}
