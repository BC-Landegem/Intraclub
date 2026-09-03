/**
 * Welke setstanden kunnen bestaan.
 *
 * Spiegelt PointsPerSet::allowsSet() aan de serverkant. De server weigert sowieso
 * wat hier zou doorglippen, dus als de twee ooit uiteenlopen kost dat hoogstens
 * een lelijkere melding — nooit foute cijfers in de databank. De getallen zelf
 * (het setmaximum en de cap) komen uit de payload, niet uit deze code.
 *
 * De zaal-app kéurt geen invoer meer, ze somt op: `directWins` en `extensionWins`
 * geven de volledige verzameling standen die de speler mag aantikken. Beide zijn
 * afgeleid door kandidaten door `isPlayableSet` te filteren, dus ze kunnen per
 * constructie niet van de regel afwijken.
 */

/** Een setstand als paar, altijd vanuit de winnende kant gezien. */
export interface SetOption {
  /** Punten van de winnaar. */
  winner: number;
  /** Punten van de verliezer. */
  loser: number;
}

/** De regel in één zin, voor waar er nog over uitgelegd moet worden. */
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

/**
 * De standen waarin de winnaar precies op het setmaximum eindigt — negen op de
 * tien sets. Alleen het getal van de verliezer verschilt, dus dit is het raster
 * waar de speler in de zaal zijn tik in kwijt is.
 */
export function directWins(target: number, cap: number): SetOption[] {
  return options(target, cap).filter((option) => option.winner === target);
}

/**
 * De rest van de verzameling: elke verlenging die kan bestaan. Hier verschilt ook
 * het getal van de winnaar, dus deze knoppen dragen beide cijfers.
 */
export function extensionWins(target: number, cap: number): SetOption[] {
  return options(target, cap).filter((option) => option.winner > target);
}

/**
 * Elke stand die de regel toelaat, één keer, oplopend. Het kandidatenveld is
 * hoogstens (cap+1)² groot — bij sets tot 21 dus 961 combinaties — en wordt
 * gefilterd door dezelfde functie die de server nabootst. Zo is opsommen en
 * keuren gegarandeerd hetzelfde antwoord.
 */
function options(target: number, cap: number): SetOption[] {
  const found: SetOption[] = [];

  for (let winner = 0; winner <= cap; winner++) {
    for (let loser = 0; loser < winner; loser++) {
      if (isPlayableSet(winner, loser, target, cap)) {
        found.push({ winner, loser });
      }
    }
  }

  return found;
}
