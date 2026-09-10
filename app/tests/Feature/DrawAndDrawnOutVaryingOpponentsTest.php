<?php

namespace Tests\Feature;

use App\Enums\DrawSystem;

/**
 * Dezelfde regels, de andere loting.
 *
 * Uitloten, het beschermingsvenster, aanvullen en herloten horen niet van de
 * samenstelling af te hangen -- ze zitten bewust buiten `composeGames()`. Zonder deze
 * uitvoering was dat een bewering en geen test, want de basisklasse draait op de
 * standaardwaarde en die is "Sterktegroepen".
 */
class DrawAndDrawnOutVaryingOpponentsTest extends DrawAndDrawnOutTest
{
    protected static function drawSystem(): DrawSystem
    {
        return DrawSystem::VaryingOpponents;
    }
}
