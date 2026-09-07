<?php
namespace App\Domain\Player\Service;

use App\Domain\Player\Repository\PlayerRepository;
use Cake\Validation\Validator;

final class PlayerValidator
{
    private PlayerRepository $repository;

    public function __construct(PlayerRepository $repository)
    {
        $this->repository = $repository;
    }

    public function validatePlayer(array $player): void
    {
        $validator = new Validator();

        $validator
            ->notEmptyString("firstName", "Voornaam is vereist")
            ->notEmptyString("name", "Naam is vereist")
            ->add("doubleRanking", "range", [
                "rule" => function ($value, $context) {
                    return $value >= 0 && $value <= 12;
                },
                "message" => "Dubbelranking moet tussen 0 en 12 liggen"
            ]);
        ;
    }
}