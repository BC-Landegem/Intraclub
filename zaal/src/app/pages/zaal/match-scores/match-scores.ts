import {
  Component,
  ElementRef,
  computed,
  inject,
  input,
  linkedSignal,
  signal,
  viewChildren,
} from '@angular/core';
import {
  FieldTree,
  FormField,
  ValidationError,
  form,
  max,
  min,
  validate,
} from '@angular/forms/signals';
import { Game, GameScores, PlayerSummary } from '../../../core/models';
import { isPlayablePoints, isPlayableSet, scoreRule } from '../../../core/score-rules';
import { ZaalApi } from '../../../core/zaal-api';

/**
 * De zes velden zoals de API ze verwacht. Bewust een type en geen interface: zo
 * gaat hij zonder omweg door als GameScores bij het bewaren.
 */
type SetScores = {
  set1_home: number | null;
  set1_away: number | null;
  set2_home: number | null;
  set2_away: number | null;
  set3_home: number | null;
  set3_away: number | null;
};

/** Een getal dat op zichzelf al niet kan; die melding hoort onder het vakje. */
const OUT_OF_RANGE = 'bereik';

/** Twee getallen die samen geen set kunnen zijn; die melding hoort onder de set. */
const IMPOSSIBLE = 'onmogelijk';

/** Eén vakje ingevuld en één leeg; pas hinderlijk wanneer je wil bewaren. */
const INCOMPLETE = 'onvolledig';

/**
 * De scores van één match: drie sets, één knop.
 *
 * Staat per game apart zodat het formulier van de ene match niets weet van de
 * andere. De regel die zegt welke setstanden kunnen bestaan staat in
 * score-rules.ts; de getallen erin (setmaximum en cap) komen van de server mee.
 */
@Component({
  selector: 'app-match-scores',
  imports: [FormField],
  templateUrl: './match-scores.html',
  styleUrl: './match-scores.css',
})
export class MatchScores {
  protected readonly api = inject(ZaalApi);

  readonly game = input.required<Game>();

  /** Het setmaximum van het seizoen, en het plafond op een verlenging. */
  readonly target = input.required<number>();
  readonly cap = input.required<number>();

  /** Het nummer waaronder de match op het scherm staat. */
  readonly index = input.required<number>();

  private readonly boxes = viewChildren<ElementRef<HTMLInputElement>>('scoreBox');

  /** Er is op bewaren gedrukt; pas dan is een half ingevulde set een probleem. */
  private readonly submitted = signal(false);

  protected readonly saved = signal(false);

  /** De zes cijfers zoals de server ze kent, als één vergelijkbare sleutel. */
  private readonly fromServer = computed(() =>
    this.game()
      .sets.flatMap((set) => [set.home.score, set.away.score])
      .join(','),
  );

  /**
   * Het model volgt de server enkel wanneer die écht andere cijfers heeft. Zonder
   * die voorwaarde zou elke verversing van de speeldag — het bewaren van een
   * andere match — hier wissen wat er net ingetikt staat.
   */
  private readonly model = linkedSignal<string, SetScores>({
    source: this.fromServer,
    computation: (key) => {
      const [one, two, three, four, five, six] = key
        .split(',')
        .map((value) => (value === '' ? null : Number(value)));

      return {
        set1_home: one,
        set1_away: two,
        set2_home: three,
        set2_away: four,
        set3_home: five,
        set3_away: six,
      };
    },
  });

  protected readonly scores = form(this.model, (path) => {
    // De grenzen per vakje horen in het schema: de formField-richtlijn zet ze dan
    // zelf als min- en max-attribuut op de input.
    const boxes = [
      path.set1_home,
      path.set1_away,
      path.set2_home,
      path.set2_away,
      path.set3_home,
      path.set3_away,
    ];

    for (const box of boxes) {
      min(box, 0, { message: 'Een setstand kan niet negatief zijn.' });
      max(box, () => this.cap(), {
        message: () => `Meer dan ${this.cap()} punten kan niet in een set.`,
      });
      validate(box, ({ value }) => this.wholeNumberErrors(value()));
    }

    // De regel over de twee getallen sámen hangt aan het thuisvak, zodat de
    // melding maar één keer onder de set verschijnt.
    validate(path.set1_home, ({ value, valueOf }) =>
      this.pairErrors(value(), valueOf(path.set1_away)),
    );
    validate(path.set2_home, ({ value, valueOf }) =>
      this.pairErrors(value(), valueOf(path.set2_away)),
    );
    validate(path.set3_home, ({ value, valueOf }) =>
      this.pairErrors(value(), valueOf(path.set3_away)),
    );
  });

  /** De drie sets met hun duo's én de twee velden die erbij horen. */
  protected readonly rows = computed(() => {
    const fields = [
      { home: this.scores.set1_home, away: this.scores.set1_away },
      { home: this.scores.set2_home, away: this.scores.set2_away },
      { home: this.scores.set3_home, away: this.scores.set3_away },
    ];

    return this.game().sets.map((set, index) => ({ set, ...fields[index] }));
  });

  /** Hoeveel van de drie sets er nog een score missen. */
  protected readonly setsRemaining = computed(
    () =>
      this.game().sets.filter((set) => set.home.score === null || set.away.score === null).length,
  );

  /** Melding onder één vakje: een getal dat op zichzelf al geen punten kan zijn. */
  protected numberMessage(field: FieldTree<number | null>): string | undefined {
    return field()
      .errors()
      .find((error) => error.kind !== IMPOSSIBLE && error.kind !== INCOMPLETE)?.message;
  }

  /**
   * Melding onder de set. Een onmogelijke stand verschijnt meteen — beide vakjes
   * staan er dan al. Een half ingevulde set pas na een poging tot bewaren, anders
   * klaagt hij terwijl je het tweede getal nog aan het typen bent.
   */
  protected setMessage(home: FieldTree<number | null>): string | undefined {
    const error = home()
      .errors()
      .find((candidate) => candidate.kind === IMPOSSIBLE || candidate.kind === INCOMPLETE);

    if (error === undefined || (error.kind === INCOMPLETE && !this.submitted())) {
      return undefined;
    }

    return error.message;
  }

  /** Voor de schermlezerlabels op de scorevakjes. */
  protected pairName(players: PlayerSummary[]): string {
    return players.map((player) => player.fullName).join(' en ');
  }

  /**
   * Bewaren. De knop blijft klikbaar ook wanneer er nog een fout staat: een dode
   * knop zegt niet waarom. Klikken toont dan de meldingen en zet de cursor in het
   * eerste vakje dat niet klopt, zonder de server lastig te vallen.
   */
  protected async onSubmit(event: Event): Promise<void> {
    event.preventDefault();
    this.submitted.set(true);
    this.saved.set(false);

    if (this.scores().invalid()) {
      this.focusFirstError();

      return;
    }

    await this.api.saveScores(this.game().id, this.model() as GameScores);

    if (this.api.errorMessage() === '') {
      this.submitted.set(false);
      this.saved.set(true);
    }
  }

  private focusFirstError(): void {
    const fields = [
      this.scores.set1_home,
      this.scores.set1_away,
      this.scores.set2_home,
      this.scores.set2_away,
      this.scores.set3_home,
      this.scores.set3_away,
    ];

    this.boxes()[fields.findIndex((field) => field().invalid())]?.nativeElement.focus();
  }

  /** Enkel wat min en max niet vangen: een half punt bestaat niet. */
  private wholeNumberErrors(value: number | null): ValidationError[] {
    return value === null || Number.isInteger(value)
      ? []
      : [{ kind: OUT_OF_RANGE, message: 'Een setstand is een heel getal.' }];
  }

  private pairErrors(home: number | null, away: number | null): ValidationError[] {
    if (home === null || away === null) {
      return home === null && away === null
        ? []
        : [{ kind: INCOMPLETE, message: 'Vul beide punten in of laat beide leeg.' }];
    }

    // Eerst het losse getal: bij 13-47 wijst de melding naar de 47, niet naar de regel.
    if (!isPlayablePoints(home, this.cap()) || !isPlayablePoints(away, this.cap())) {
      return [];
    }

    return isPlayableSet(home, away, this.target(), this.cap())
      ? []
      : [{ kind: IMPOSSIBLE, message: `Kan niet. ${scoreRule(this.target(), this.cap())}` }];
  }
}
