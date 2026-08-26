import { Component, inject, output, signal } from '@angular/core';
import { FormField, form, max, maxLength, min, required, submit } from '@angular/forms/signals';
import { NewPlayer } from '../../core/models';
import { ZaalApi } from '../../core/zaal-api';

/**
 * Adds a player during the matchday. The new player is marked present right
 * away, so they can join the very next draw.
 */
@Component({
  selector: 'app-add-player',
  imports: [FormField],
  templateUrl: './add-player.html',
  styleUrl: './add-player.css',
})
export class AddPlayer {
  private readonly api = inject(ZaalApi);

  readonly closed = output<void>();

  protected readonly model = signal<NewPlayer>({
    firstName: '',
    name: '',
    gender: 'male',
    birthDate: '',
    playsCompetition: false,
    doubleRanking: 0,
  });

  protected readonly playerForm = form(this.model, (path) => {
    required(path.firstName, { message: 'Vul een voornaam in.' });
    maxLength(path.firstName, 100);
    required(path.name, { message: 'Vul een achternaam in.' });
    maxLength(path.name, 100);
    required(path.birthDate, { message: 'Vul een geboortedatum in.' });
    min(path.doubleRanking, 0, { message: 'Klassement ligt tussen 0 en 12.' });
    max(path.doubleRanking, 12, { message: 'Klassement ligt tussen 0 en 12.' });
  });

  protected readonly errorMessage = signal('');
  protected readonly busy = signal(false);

  protected onSubmit(event: Event): void {
    event.preventDefault();
    this.errorMessage.set('');

    submit(this.playerForm, async () => {
      this.busy.set(true);
      try {
        await this.api.addPlayer(this.model());
        if (this.api.errorMessage() === '') {
          this.closed.emit();
        } else {
          this.errorMessage.set(this.api.errorMessage());
        }
      } finally {
        this.busy.set(false);
      }
    });
  }
}
