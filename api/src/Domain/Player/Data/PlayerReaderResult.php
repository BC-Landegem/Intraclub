<?php
namespace App\Domain\Player\Data;

use DateTime;

final class PlayerReaderResult
{
    public ?int $id = null;
    public ?string $firstname = null;
    public ?string $name = null;
    public ?string $gender = null;
    public ?bool $member = null;
    public ?int $doubleRanking = null;
    public ?bool $playsCompetition = null;


}