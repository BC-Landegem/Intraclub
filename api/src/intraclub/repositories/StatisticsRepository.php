<?php
namespace intraclub\repositories;

use PDO;

class StatisticsRepository
{
    /**
     * Database connection
     *
     * @var PDO
     */
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Update seizoensstatistieken (bereken tussenstand)
     *
     * @param  int $seasonId
     * @param  int $playerId
     * @param  int $setsPlayed
     * @param  int $setsWon
     * @param  int $pointsPlayed
     * @param  int $pointsWon
     * @param  int $matchesPlayed
     * @param  int $matchesWon
     * @param  int $roundsPresent
     * @return void
     */
    public function updateSeasonStatistics($seasonId, $playerId, $setsPlayed, $setsWon, $pointsPlayed, $pointsWon, $matchesPlayed, $matchesWon, $roundsPresent)
    {

        $updatePlayerSeasonStmt = $this->db->prepare("UPDATE intra_spelerperseizoen
            SET
                gespeelde_sets = :setsPlayed,
                gewonnen_sets = :setsWon,
                gespeelde_punten= :pointsPlayed,
                gewonnen_punten = :pointsWon,
                gespeelde_matchen = :matchesPlayed,
                gewonnen_matchen = :matchesWon,
                speeldagen_aanwezig= :roundsPresent

            WHERE speler_id = :playerId AND seizoen_id = :seasonId");

        $updatePlayerSeasonStmt->bindParam(':setsPlayed', $setsPlayed, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':setsWon', $setsWon, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':pointsPlayed', $pointsPlayed, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':pointsWon', $pointsWon, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':matchesPlayed', $matchesPlayed, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':matchesWon', $matchesWon, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':playerId', $playerId, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':seasonId', $seasonId, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':roundsPresent', $roundsPresent, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->execute();
    }

    /**
     * Voeg (of update) rondestatistieken (bereken tussenstand)
     *
     * @param  int $roundId
     * @param  int $playerId
     * @param  int $average
     * @return void
     */
    public function insertOrUpdateRoundStatistics($roundId, $playerId, $average)
    {

        $updatePlayerSeasonStmt = $this->db->prepare("INSERT INTO
            intra_spelerperspeeldag
            SET
                gemiddelde = :average,
                speler_id = :playerId,
                speeldag_id = :roundId
            ON DUPLICATE KEY UPDATE
                gemiddelde = :average");

        $updatePlayerSeasonStmt->bindParam(':average', $average, PDO::PARAM_STR);
        $updatePlayerSeasonStmt->bindParam(':playerId', $playerId, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':roundId', $roundId, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->execute();
    }

}