import { Component, computed, inject } from '@angular/core';
import { RouterLink, RouterOutlet } from '@angular/router';
import { PlayerSummary } from '../../../../core/models';
import { ZaalApi } from '../../../../core/zaal-api';

/**
 * De wedstrijden van de speeldag: wie uitgeloot is, wat er nog bevestigd moet
 * worden, en de afwerklijst met wat nog geen score heeft.
 */
@Component({
  selector: 'app-games',
  imports: [RouterLink, RouterOutlet],
  templateUrl: './games.html',
  styleUrl: './games.css',
})
export class Games {
  protected readonly api = inject(ZaalApi);

  /** De afwerklijst voor de organisator: wat nog geen score heeft, bovenaan. */
  protected readonly progress = computed(() =>
    this.api
      .games()
      .map((game, index) => ({ game, number: index + 1 }))
      .sort((one, other) => Number(one.game.isComplete) - Number(other.game.isComplete)),
  );

  /**
   * Loot opnieuw. Matches die al bevestigd zijn houden hun spelers, dus een
   * herloting herschikt enkel wie nog wacht.
   */
  protected async redraw(): Promise<void> {
    await this.api.drawRound();
  }

  protected async confirmProposal(proposal: PlayerSummary[]): Promise<void> {
    await this.api.confirmProposal(proposal);
  }

  protected async confirmAllProposals(): Promise<void> {
    for (const proposal of this.api.pendingGames()) {
      await this.api.confirmProposal(proposal);

      if (this.api.errorMessage() !== '') {
        return;
      }
    }
  }
}
