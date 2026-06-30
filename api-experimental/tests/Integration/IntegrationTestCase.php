<?php

declare(strict_types=1);

namespace App\Test\Integration;

use App\Factory\ContainerFactory;
use DI\Container;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/**
 * Base class for HTTP-level integration tests.
 *
 * Boots the real DI container and Slim app, (re)creates the schema once, then
 * truncates and re-seeds a known fixture before every test so each test runs
 * against a deterministic database. Requests go through the full middleware
 * stack, routing, actions, services and repositories against a real database.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?ContainerInterface $container = null;
    private static bool $schemaLoaded = false;

    protected App $app;
    protected PDO $pdo;

    /**
     * Tables in dependency order (children first) for truncation/drops.
     *
     * @var array<int, string>
     */
    private const TABLES = [
        'PlayerRoundStatistic',
        'PlayerSeasonStatistic',
        'Match',
        'Round',
        'Season',
        'Player',
    ];

    protected function setUp(): void
    {
        $container = self::container();
        $this->app = $container->get(App::class);
        $this->pdo = self::pdo($container);

        if (!self::$schemaLoaded) {
            $this->loadSchema();
            self::$schemaLoaded = true;
        }

        $this->resetDatabase();
        $this->seed();
    }

    private static function container(): ContainerInterface
    {
        if (self::$container === null) {
            $container = ContainerFactory::createInstance();
            self::assertInstanceOf(Container::class, $container);
            self::$container = $container;
        }

        return self::$container;
    }

    /**
     * A direct PDO connection to the same database, used to load the schema and
     * seed fixtures. CakePHP 5 no longer exposes the driver's PDO, so the test
     * harness opens its own connection from the configured settings.
     */
    private static function pdo(ContainerInterface $container): PDO
    {
        /** @var array<string, mixed> $db */
        $db = $container->get('settings')['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            (string) $db['host'],
            (string) ($db['port'] ?? '3306'),
            (string) $db['database'],
        );

        return new PDO($dsn, (string) $db['username'], (string) $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function loadSchema(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            $this->pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $schema = (string) file_get_contents(__DIR__ . '/../../resources/schema/schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            $this->pdo->exec($statement);
        }
    }

    private function resetDatabase(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            $this->pdo->exec(sprintf('TRUNCATE TABLE `%s`', $table));
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Insert the shared fixture used by every test.
     */
    protected function seed(): void
    {
        $this->pdo->exec("INSERT INTO `Season` (`Id`, `Name`) VALUES (1, '2023 - 2024')");

        // Id, Firstname, Name, Member, Gender, BirthDate, DoubleRanking, PlaysCompetition
        $players = [
            [1, 'Anna', 'Albers', 1, 'Woman', '1990-01-01', 5, 1],
            [2, 'Bart', 'Beckers', 1, 'Man', '1970-01-01', 8, 1],   // veteran (>=45)
            [3, 'Carl', 'Claes', 1, 'Man', '1995-01-01', 10, 0],    // recreant (no competition)
            [4, 'Dana', 'Dirix', 1, 'Woman', '2000-01-01', 6, 1],
            [5, 'Eve', 'Engels', 0, 'Woman', '1998-01-01', 4, 1],   // non-member (excluded from lists)
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO `Player` (`Id`, `Firstname`, `Name`, `Member`, `Gender`, `BirthDate`, `DoubleRanking`, `PlaysCompetition`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($players as $p) {
            $stmt->execute($p);
        }

        // Season statistics for the four members. Columns: SeasonId, PlayerId, ...
        $seasonStats = [
            [1, 1, 19.0, 6, 4, 60, 40, 2, 2],
            [1, 2, 18.5, 6, 3, 60, 33, 2, 2],
            [1, 3, 18.0, 3, 1, 30, 12, 1, 1],
            [1, 4, 17.5, 3, 2, 30, 18, 1, 1],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO `PlayerSeasonStatistic`
             (`SeasonId`, `PlayerId`, `BasePoints`, `SetsPlayed`, `SetsWon`, `PointsPlayed`, `PointsWon`, `RoundsPresent`, `MatchesPlayed`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($seasonStats as $s) {
            $stmt->execute($s);
        }

        $this->pdo->exec(
            "INSERT INTO `Round` (`Id`, `Number`, `Date`, `AverageAbsent`, `SeasonId`, `Calculated`, `DrawClosed`)
             VALUES (1, 1, '2023-09-15', 12.0, 1, 1, 0)"
        );

        $this->pdo->exec(
            'INSERT INTO `Match`
             (`Id`, `RoundId`, `Player1Id`, `Player2Id`, `Player3Id`, `Player4Id`,
              `Set1Home`, `Set1Away`, `Set2Home`, `Set2Away`, `Set3Home`, `Set3Away`)
             VALUES (1, 1, 1, 2, 3, 4, 21, 15, 21, 18, 0, 0)'
        );

        // Round statistics (averages) for the calculated round 1.
        $roundStats = [
            [1, 1, 1, 0, 18.7],
            [1, 2, 1, 0, 18.2],
            [1, 3, 1, 0, 17.4],
            [1, 4, 1, 0, 17.1],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO `PlayerRoundStatistic` (`RoundId`, `PlayerId`, `Present`, `DrawnOut`, `Average`)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($roundStats as $r) {
            $stmt->execute($r);
        }
    }

    /**
     * Build and dispatch a request through the full application.
     *
     * @param array<string, mixed>|null $body  JSON body for POST requests
     * @param array<string, string> $query     Query string parameters
     */
    protected function request(string $method, string $path, ?array $body = null, array $query = []): ResponseInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, $path);

        if ($query !== []) {
            $request = $request->withQueryParams($query);
        }

        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json');
            $request->getBody()->write((string) json_encode($body));
            $request->getBody()->rewind();
        }

        return $this->app->handle($request);
    }

    /**
     * Decode a JSON response body to an array.
     *
     * @return array<mixed>
     */
    protected function jsonBody(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return (array) json_decode((string) $response->getBody(), true);
    }
}
