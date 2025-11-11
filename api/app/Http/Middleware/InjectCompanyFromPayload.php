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
        // Only process if session doesn't already have company context
        if (!session()->has('company')) {
            // Check for company_uuid in various payload locations
            $companyUuid = $request->input('user.company_uuid')
                        ?? $request->input('company_uuid')
                        ?? $request->header('X-Company-UUID');

            if ($companyUuid) {
                // Verify company exists
                $company = Company::where('uuid', $companyUuid)->first();

                if ($company) {
                    // Inject into session
                    session(['company' => $companyUuid]);

                    Log::info("✅ Injected company context into session", [
                        'company_uuid' => $companyUuid,
                        'company_name' => $company->name,
                        'endpoint' => $request->path()
                    ]);
                } else {
                    Log::warning("⚠️  Invalid company_uuid in payload", [
                        'company_uuid' => $companyUuid,
                        'endpoint' => $request->path()
                    ]);
                }
            }
        }

        return $next($request);
    }
}
