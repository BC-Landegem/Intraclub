import type { PlayerSummary } from './models';

/**
 * Namen die vanavond niet met elkaar te verwarren zijn.
 *
 * Een derde van de leden heeft een voornaam die ook iemand anders heeft — drie
 * Barten, drie Filips, drie Geerts, drie Jeroenen, drie Stevens. Zaten er twee
 * in hetzelfde viertal, dan werd de vraag op het invoerscherm letterlijk
 * onbeantwoordbaar: "Bram + Bart tegen Bram + Ciska — wie won?".
 *
 * Daarom: enkel de voornaam wanneer die vanavond uniek is, en anders de kortste
 * aanzet van de achternaam die het verschil maakt ("Bart Co." tegen "Bart Cl.").
 * De verzameling waarbinnen "uniek" geldt zijn de spelers van déze speeldag, niet
 * het hele ledenbestand: zo krijgt niemand een achtervoegsel om iemand die er
 * niet is.
 */
export function shortLabels(players: readonly PlayerSummary[]): Map<number, string> {
  const perFirstName = new Map<string, PlayerSummary[]>();

  for (const player of players) {
    const key = player.firstName.trim().toLowerCase();
    const group = perFirstName.get(key);
    group === undefined ? perFirstName.set(key, [player]) : group.push(player);
  }

  const labels = new Map<number, string>();

  for (const group of perFirstName.values()) {
    for (const player of group) {
      const suffix = group.length === 1 ? '' : distinguish(player, group);
      labels.set(player.id, [player.firstName.trim(), suffix].filter(Boolean).join(' '));
    }
  }

  return labels;
}

/**
 * De kortste aanzet van de achternaam die deze speler binnen zijn groep
 * onderscheidt: één letter waar dat volstaat, twee waar de achternamen met
 * dezelfde letter beginnen.
 *
 * Helpt de volledige achternaam ook niet — twee leden met exact dezelfde voor- én
 * achternaam — dan geeft dit hem toch terug. Dat is dan een dubbele registratie
 * in het ledenbestand, en die hoort daar opgelost te worden, niet hier verstopt.
 */
function distinguish(player: PlayerSummary, group: readonly PlayerSummary[]): string {
  const lastName = player.name.trim();

  if (lastName === '') {
    return '';
  }

  const others = group
    .filter((other) => other.id !== player.id)
    .map((other) => other.name.trim().toLowerCase());

  for (let length = 1; length < lastName.length; length++) {
    const prefix = lastName.slice(0, length);

    if (!others.some((other) => other.startsWith(prefix.toLowerCase()))) {
      return `${prefix}.`;
    }
  }

  return lastName;
}
