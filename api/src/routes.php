<?php

use intraclub\managers\MatchManager;
use intraclub\managers\PlayerManager;
use intraclub\managers\RankingManager;
use intraclub\managers\RoundManager;
use intraclub\managers\SeasonManager;

use intraclub\validators\MatchValidator;
use intraclub\validators\RoundValidator;
use intraclub\validators\SeasonValidator;
use intraclub\validators\PlayerValidator;
use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;

define('_JEXEC', 1);
if (file_exists('/home/bclandegem/domains/bclandegem.be/public_html' . '/defines.php')) {
    include_once '/home/bclandegem/domains/bclandegem.be/public_html' . '/defines.php';
}
if (!defined('_JDEFINES')) {
    define('JPATH_BASE', '/home/bclandegem/domains/bclandegem.be/public_html');
    require_once JPATH_BASE . '/includes/defines.php';
}
require_once JPATH_BASE . '/includes/framework.php';

/**
 * Check toegangsrechten
 * Joomla - laden framework & checken user
 */
function checkAccessRights()
{
    $joomla_app = JFactory::getApplication('site');
    $joomla_app->initialise();
    $user = JFactory::getUser();
    $authorisedViewLevels = $user->getAuthorisedViewLevels();
    //5 = id intrclub access level
    if (!in_array(5, $authorisedViewLevels)) {
        die("Onvoldoende rechten !");
    }

}

return function (App $app) {

    /* Creatie speler
     */
    $app->post('/players', function (Request $request, Response $response, array $args) {
        checkAccessRights();
        $playerManager = new PlayerManager($this->db);
        $playerValidator = new PlayerValidator($this->db);

        $postArr = $request->getParsedBody();

        // Validatie
        $errors = $playerValidator->validateNewPlayer(
            $postArr["firstName"],
            $postArr["name"],
            $postArr["gender"],
            $postArr["birthDate"],
            $postArr["doubleRanking"],
            $postArr["playsCompetition"],
            $postArr["basePoints"]
        );

        if (!empty($errors)) {
            $newResponse = $response->withStatus(400);
            return $newResponse->withJson($errors);
        }

        // Creatie
        $playerManager->create(
            $postArr["firstName"],
            $postArr["name"],
            $postArr["gender"],
            $postArr["birthDate"],
            $postArr["doubleRanking"],
            $postArr["playsCompetition"],
            $postArr["basePoints"]
        );

        return $response;
    });

    $app->get('/rankings/general', function (Request $request, Response $response, array $args) {
        $items = $request->getQueryParam('$top');
        $rankingManager = new RankingManager($this->db);
        $data = $rankingManager->get($items, true);
        return $response->withJson($data);
    });
    /*Creatie nieuw seizoen
    {
        "period": "2020 - 2021"
    }
    */
    $app->post('/seasons', function (Request $request, Response $response, array $args) {
        checkAccessRights();
        $seasonManager = new SeasonManager($this->db);
        $seasonValidator = new SeasonValidator($this->db);

        $postArr = $request->getParsedBody();
        $period = $postArr["period"];
        $errors = $seasonValidator->validateCreateSeason($period);
        if (!empty($errors)) {
            $newResponse = $response->withStatus(400);
            return $newResponse->withJson($errors);
        }
        $seasonManager->create($period);
        return $response;
    });
    /* Creatie nieuwe ronde
    {
        "date": "2020-09-30"
    }
    */
    $app->post('/rounds', function (Request $request, Response $response, array $args) {
        checkAccessRights();
        $roundManager = new RoundManager($this->db);
        $roundValidator = new RoundValidator($this->db);

        $postArr = $request->getParsedBody();
        $date = $postArr["date"];

        $errors = $roundValidator->validateCreateRound($date);
        if (!empty($errors)) {
            $newResponse = $response->withStatus(400);
            return $newResponse->withJson($errors);
        }

        $roundManager->create($date);
        return $response;
    });
    /* Creatie match
    {
        "roundId": 118,
        "playerId1": 1,
        "playerId2": 2,
        "playerId3": 3,
        "playerId4": -4,
        "set1Home": 20,
        "set1Away": 21,
        "set2Home": 6,
        "set2Away": 23,
        "set3Home": "",
        "set3Away": ""
    }
    */
    $app->post('/matches', function (Request $request, Response $response, array $args) {
        checkAccessRights();

        $matchValidator = new MatchValidator($this->db);
        $matchManager = new MatchManager($this->db);

        $postArr = $request->getParsedBody();

        $errors = $matchValidator->validateCreateMatch(
            $postArr["roundId"],
            $postArr["playerId1"],
            $postArr["playerId2"],
            $postArr["playerId3"],
            $postArr["playerId4"],
            $postArr["set1Home"],
            $postArr["set1Away"],
            $postArr["set2Home"],
            $postArr["set2Away"],
            $postArr["set3Home"],
            $postArr["set3Away"]
        );

        if (!empty($errors)) {
            $newResponse = $response->withStatus(400);
            return $newResponse->withJson($errors);
        }
        $matchManager->create(
            $postArr["roundId"],
            $postArr["playerId1"],
            $postArr["playerId2"],
            $postArr["playerId3"],
            $postArr["playerId4"],
            $postArr["set1Home"],
            $postArr["set1Away"],
            $postArr["set2Home"],
            $postArr["set2Away"],
            $postArr["set3Home"],
            $postArr["set3Away"]
        );

        return $response;
    });



    //Bereken tussenstand van huidig seizoen
    $app->post('/seasons/calculate', function (Request $request, Response $response, array $args) {
        checkAccessRights();
        $seasonManager = new SeasonManager($this->db);
        $seasonManager->calculateCurrentSeason();
        return $response;
    });

    /*Should be PUT, but doesn't work on the server :/
    {
        "firstName": "Test",
        "name": "Persoon",
        "gender":"Man",
        "isYouth": false,
        "isVeteran": true,
        "ranking": "C1"
    }
    */
    $app->post('/players/{id}', function (Request $request, Response $response, array $args) {
        checkAccessRights();
        $id = $args['id'];

        $playerManager = new PlayerManager($this->db);
        $playerValidator = new PlayerValidator($this->db);

        $postArr = $request->getParsedBody();

        $errors = $playerValidator->validateExistingPlayer(
            $id,
            $postArr["firstName"],
            $postArr["name"],
            $postArr["gender"],
            $postArr["isYouth"],
            $postArr["isVeteran"],
            $postArr["ranking"]
        );

        if (!empty($errors)) {
            $newResponse = $response->withStatus(400);
            return $newResponse->withJson($errors);
        }
        $playerManager->update(
            $id,
            $postArr["firstName"],
            $postArr["name"],
            $postArr["gender"],
            $postArr["isYouth"],
            $postArr["isVeteran"],
            $postArr["ranking"]
        );

        return $response;
    });

    /* POST ipv PATCH - werkt niet op server
    {
        "playerId1": 7,
        "playerId2": 8,
        "playerId3": 9,
        "playerId4": 11,
        "set1Home": 19,
        "set1Away": 21,
        "set2Home": 6,
        "set2Away": 21,
        "set3Home": 0,
        "set3Away": 0
    }
    */
    $app->post('/matches/{id}', function (Request $request, Response $response, array $args) {
        checkAccessRights();
        $id = $args['id'];

        $matchValidator = new MatchValidator($this->db);
        $matchManager = new MatchManager($this->db);

        $postArr = $request->getParsedBody();

        $errors = $matchValidator->validateUpdateMatch(
            $id,
            $postArr["playerId1"],
            $postArr["playerId2"],
            $postArr["playerId3"],
            $postArr["playerId4"],
            $postArr["set1Home"],
            $postArr["set1Away"],
            $postArr["set2Home"],
            $postArr["set2Away"],
            $postArr["set3Home"],
            $postArr["set3Away"]
        );

        if (!empty($errors)) {
            $newResponse = $response->withStatus(400);
            return $newResponse->withJson($errors);
        }
        $matchManager->update(
            $id,
            $postArr["playerId1"],
            $postArr["playerId2"],
            $postArr["playerId3"],
            $postArr["playerId4"],
            $postArr["set1Home"],
            $postArr["set1Away"],
            $postArr["set2Home"],
            $postArr["set2Away"],
            $postArr["set3Home"],
            $postArr["set3Away"]
        );

        return $response;
    });

    $app->get('/players', function (Request $request, Response $response, array $args) {
        $playerManager = new PlayerManager($this->db);
        $data = $playerManager->getAll();
        return $response->withJson($data);
    });

    $app->get('/players/{id}', function (Request $request, Response $response, array $args) {
        $id = $args['id'];
        $playerManager = new PlayerManager($this->db);
        $queryParams = $request->getQueryParams();
        $seasonId = $queryParams["seasonId"];
        $data = $playerManager->getByIdWithSeasonInfo($id, $seasonId);
        return $response->withJson($data);
    });
    $app->get('/rounds', function (Request $request, Response $response) {
        $roundManager = new RoundManager($this->db);
        $queryParams = $request->getQueryParams();
        $seasonId = $queryParams["seasonId"];

        $data = $roundManager->getAll($seasonId);
        return $response->withJson($data);
    });
    $app->get('/rounds/latest', function (Request $request, Response $response) {
        $roundManager = new RoundManager($this->db);
        $data = $roundManager->getLast();
        return $response->withJson($data);
    });
    $app->get('/rankings', function (Request $request, Response $response, array $args) {
        $items = $request->getQueryParam('$top');
        $rankingManager = new RankingManager($this->db);
        $data = $rankingManager->get($items, true, true, true, true);
        return $response->withJson($data);
    });
    $app->get('/rankings/general', function (Request $request, Response $response, array $args) {
        $items = $request->getQueryParam('$top');
        $rankingManager = new RankingManager($this->db);
        $data = $rankingManager->get($items, true);
        return $response->withJson($data);
    });
    $app->get('/rankings/women', function (Request $request, Response $response, array $args) {
        $items = $request->getQueryParam('$top');
        $rankingManager = new RankingManager($this->db);
        $data = $rankingManager->get($items, false, true);
        return $response->withJson($data);
    });
    $app->get('/rankings/veterans', function (Request $request, Response $response, array $args) {
        $items = $request->getQueryParam('$top');
        $rankingManager = new RankingManager($this->db);
        $data = $rankingManager->get($items, false, false, true);
        return $response->withJson($data);
    });
    $app->get('/rankings/recreants', function (Request $request, Response $response, array $args) {
        $items = $request->getQueryParam('$top');
        $rankingManager = new RankingManager($this->db);
        $data = $rankingManager->get($items, false, false, false, true);
        return $response->withJson($data);
    });
    $app->get('/rounds/{id}', function (Request $request, Response $response, array $args) {
        $roundManager = new RoundManager($this->db);
        $data = $roundManager->getByIdWithMatches($args['id']);
        return $response->withJson($data);
    });
    $app->get('/rounds/{id}/matches', function (Request $request, Response $response, array $args) {
        $matchManager = new MatchManager($this->db);
        $data = $matchManager->getAllByRoundId($args['id']);
        return $response->withJson($data);
    });
    $app->get('/seasons/latest/statistics', function (Request $request, Response $response, array $args) {
        $seasonManager = new SeasonManager($this->db);
        $data = $seasonManager->getStatistics();
        return $response->withJson($data);
    });
};