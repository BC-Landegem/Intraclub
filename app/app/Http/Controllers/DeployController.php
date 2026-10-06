<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/*
 * Uitvoerpad voor GitHub Actions. De hosting heeft geen SSH, dus wat lokaal een
 * artisan-commando is, gebeurt hier over HTTP achter een Bearer-token.
 *
 * Zonder DEPLOY_TOKEN in de .env geven alle acties 404 — niet 403, want dat zou
 * verklappen dat er iets te vinden is.
 */
class DeployController extends Controller
{
    /** De enige artisan-commando's die via HTTP mogen draaien. */
    private const TASKS = [
        'migrate' => ['migrate', ['--force' => true]],
        'optimize' => ['optimize', []],
        'clear' => ['optimize:clear', []],
    ];

    public function run(Request $request, string $task): JsonResponse
    {
        $this->authorizeToken($request);

        [$command, $options] = self::TASKS[$task];

        try {
            $exit = Artisan::call($command, $options);
        } catch (Throwable $e) {
            return $this->failure($task, $e);
        }

        return response()->json([
            'task' => $task,
            'php' => PHP_VERSION,
            'exit_code' => $exit,
            'output' => Artisan::output(),
        ], $exit === 0 ? 200 : 500);
    }

    /**
     * Een gefaalde taak moet zichzelf verklaren: er is geen SSH om in de logs te
     * gaan kijken, en APP_DEBUG staat (terecht) uit. Wie het token heeft, mag de
     * foutmelding zien.
     */
    private function failure(string $task, Throwable $e): JsonResponse
    {
        return response()->json([
            'task' => $task,
            'php' => PHP_VERSION,
            'error' => $e::class.': '.$e->getMessage(),
            'at' => $e->getFile().':'.$e->getLine(),
            'output' => rescue(fn (): string => Artisan::output(), '', report: false),
        ], 500);
    }

    private function authorizeToken(Request $request): void
    {
        $token = config('deploy.token');

        abort_if(blank($token), 404);
        abort_unless(hash_equals((string) $token, (string) $request->bearerToken()), 404);
    }
}
