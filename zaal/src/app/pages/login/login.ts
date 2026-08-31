import { Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { FormField, email, form, minLength, required, submit } from '@angular/forms/signals';
import { Auth } from '../../core/auth';
import { describeError } from '../../core/zaal-api';

@Component({
  selector: 'app-login',
  imports: [FormField],
  templateUrl: './login.html',
  styleUrl: './login.css',
})
export class Login {
  private readonly auth = inject(Auth);
  private readonly router = inject(Router);

  protected readonly model = signal({ email: '', password: '' });

  protected readonly loginForm = form(this.model, (path) => {
    required(path.email, { message: 'Vul je e-mailadres in.' });
    email(path.email, { message: 'Dat lijkt geen geldig e-mailadres.' });
    required(path.password, { message: 'Vul je wachtwoord in.' });
    minLength(path.password, 6, { message: 'Een wachtwoord telt minstens 6 tekens.' });
  });

  protected readonly errorMessage = signal('');
  protected readonly busy = signal(false);

  protected onSubmit(event: Event): void {
    event.preventDefault();
    this.errorMessage.set('');

    submit(this.loginForm, async () => {
      this.busy.set(true);
      try {
        const { email: address, password } = this.model();
        await this.auth.login(address, password);
        await this.router.navigate(['/']);
      } catch (error: unknown) {
        this.errorMessage.set(describeError(error));
      } finally {
        this.busy.set(false);
      }
    });
  }
}
