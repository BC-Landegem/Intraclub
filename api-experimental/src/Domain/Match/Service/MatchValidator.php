<?php

declare(strict_types=1);

namespace App\Domain\Match\Service;

use App\Domain\Match\Repository\MatchRepository;
use App\Domain\Player\Repository\PlayerRepository;
use App\Domain\Round\Repository\RoundRepository;
use DomainException;

/**
 * Validates match input. Throws DomainException (rendered as HTTP 400) with all
 * collected error messages when the input is invalid.
 */
final class MatchValidator
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private RoundRepository $roundRepository,
        private MatchRepository $matchRepository
    ) {
    }

    public function validateCreateMatch(
        int $roundId,
        int $playerId1,
        int $playerId2,
        int $playerId3,
        int $playerId4
    ): void {
        $errors = [];

        if (!$this->roundRepository->exists($roundId)) {
            $errors[] = 'Ronde bestaat niet.';
        }
        if (!$this->playerRepository->existsAndIsMember($playerId1)) {
            $errors[] = 'Eerste thuisspeler is geen lid.';
        }
        if (!$this->playerRepository->existsAndIsMember($playerId2)) {
            $errors[] = 'Tweede thuisspeler is geen lid.';
        }
        if (!$this->playerRepository->existsAndIsMember($playerId3)) {
            $errors[] = 'Eerste uitspeler is geen lid.';
        }
        if (!$this->playerRepository->existsAndIsMember($playerId4)) {
            $errors[] = 'Tweede uitspeler is geen lid.';
        }

        if ($errors) {
            throw new DomainException(implode(' ', $errors));
        }
    }

    public function validateUpdateMatch(
        int $id,
        int $set1Home,
        int $set1Away,
        int $set2Home,
        int $set2Away,
        int $set3Home,
        int $set3Away
    ): void {
        $errors = [];

        if (!$this->matchRepository->exists($id)) {
            $errors[] = 'Match bestaat niet.';
        }

        $errors = $this->checkIfValidNumber($set1Home, 'Thuisscore eerste set', $errors);
        $errors = $this->checkIfValidNumber($set1Away, 'Uitscore eerste set', $errors);
        $errors = $this->checkIfValidNumber($set2Home, 'Thuisscore tweede set', $errors);
        $errors = $this->checkIfValidNumber($set2Away, 'Uitscore tweede set', $errors);
        $errors = $this->checkIfValidNumber($set3Home, 'Thuisscore derde set', $errors);
        $errors = $this->checkIfValidNumber($set3Away, 'Uitscore derde set', $errors);

        if ($errors) {
            throw new DomainException(implode(' ', $errors));
        }

        if ($set1Home != 0 && $set1Away != 0) {
            $errors = $this->checkSet($set1Home, $set1Away, 'eerste set', $errors);
        }
        if ($set2Home != 0 && $set2Away != 0) {
            $errors = $this->checkSet($set2Home, $set2Away, 'tweede set', $errors);
        }
        if ($set3Home != 0 && $set3Away != 0) {
            $errors = $this->checkSet($set3Home, $set3Away, 'derde set', $errors);
        }

        if ($errors) {
            throw new DomainException(implode(' ', $errors));
        }
    }

    /**
     * @param array<int, string> $errors
     *
     * @return array<int, string>
     */
    private function checkIfValidNumber(int $score, string $message, array $errors): array
    {
        if ($score < 0 || $score > 30) {
            $errors[] = $message . '  is een ongeldig getal';
        }

        return $errors;
    }

    /**
     * @param array<int, string> $errors
     *
     * @return array<int, string>
     */
    private function checkSet(int $homeScore, int $awayScore, string $message, array $errors): array
    {
        if (($homeScore === 30 && $awayScore === 29) || ($awayScore === 30 && $homeScore === 29)) {
            return $errors;
        }
        if (
            ($homeScore > 21 && $homeScore <= 30 && $homeScore > $awayScore && $awayScore != $homeScore - 2) ||
            ($awayScore > 21 && $awayScore <= 30 && $awayScore > $homeScore && $homeScore != $awayScore - 2)
        ) {
            $errors[] = 'Foutieve score voor ' . $message;
        }
        if (
            ($homeScore === 21 && $homeScore > $awayScore && $awayScore >= 20) ||
            ($awayScore === 21 && $awayScore > $homeScore && $homeScore >= 20)
        ) {
            $errors[] = 'Foutieve score voor ' . $message;
        }

        return $errors;
    }
}
