<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Fleetbase\Models\Company;
use Illuminate\Support\Facades\Log;

/**
 * Middleware to inject company context from request payload into session.
 *
 * This solves the issue where Railway internal networking doesn't propagate session cookies,
 * causing session('company') and Auth::getCompany() to return null.
 *
 * When company_uuid is provided in the request payload (e.g., user.company_uuid),
 * this middleware sets it in the session before the controller processes the request.
 */
class InjectCompanyFromPayload
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Check for company_uuid in various payload locations
        $companyUuid = $request->input('user.company_uuid')
                    ?? $request->input('company_uuid')
                    ?? $request->header('X-Company-UUID');

        if ($companyUuid) {
            // Verify company exists
            $company = Company::where('uuid', $companyUuid)->first();

            if ($company) {
                // Store company object in request attributes so it's available throughout request lifecycle
                $request->attributes->set('injected_company', $company);
                $request->attributes->set('injected_company_uuid', $companyUuid);

                // CRITICAL FIX #1: Add company_uuid to top-level request input
                // This ensures Auth::getCompany()'s fallback (line 187) finds it via request()->input('company_uuid')
                $request->merge(['company_uuid' => $companyUuid]);

                // CRITICAL FIX #2: Set session company IMMEDIATELY if session is started
                // This ensures Auth::getCompany() returns correct company DURING request processing
                if (session()->isStarted()) {
                    session(['company' => $companyUuid]);
                    Log::info("✅ Set session company BEFORE controller", [
                        'company_uuid' => $companyUuid,
                        'company_name' => $company->name,
                        'endpoint' => $request->path()
                    ]);
                } else {
                    Log::info("✅ Injected company context (session not started, using request fallback)", [
                        'company_uuid' => $companyUuid,
                        'company_name' => $company->name,
                        'endpoint' => $request->path()
                    ]);
                }
            } else {
                Log::warning("⚠️  Invalid company_uuid in payload", [
                    'company_uuid' => $companyUuid,
                    'endpoint' => $request->path()
                ]);
            }
        }

        // Continue with the request
        $response = $next($request);

        // AFTER the request has been processed, ensure session has company for subsequent requests
        if ($companyUuid && session()->isStarted() && !session()->has('company')) {
            session(['company' => $companyUuid]);
            Log::info("✅ Persisted company context to session (post-response)", [
                'company_uuid' => $companyUuid
            ]);
        }

        return $response;
    }
}
