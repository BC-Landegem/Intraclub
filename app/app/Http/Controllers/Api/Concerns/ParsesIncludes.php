<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

/**
 * `?include=games,ranking_history` — komma-gescheiden sub-resources die mee in
 * de response mogen.
 *
 * Welke namen mogen, staat op de route (`->defaults('include', [...])`) en wordt
 * door ValidateIncludes nagekeken vóór de controller aan de beurt is. Hier blijft
 * alleen het uitlezen over: wat hier binnenkomt, is toegelaten.
 */
trait ParsesIncludes
{
    /** @return list<string> */
    protected function includes(Request $request): array
    {
        return $request->attributes->get('includes', []);
    }

    protected function wants(Request $request, string $include): bool
    {
        return in_array($include, $this->includes($request), true);
    }
}
