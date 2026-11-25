<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes one line per incoming HTTP request to storage/logs/requests-YYYY-MM-DD.log,
 * so you can see exactly what the ZKTeco device (and everything else) sends.
 *
 * Turn off with LOG_HTTP_REQUESTS=false.
 */
class LogIncomingRequests
{
    /**
     * Only device requests have their body logged: other bodies can hold passwords. Of those, user and
     * template uploads are left out too, since they can carry device passwords and fingerprint / face data.
     */
    private const BODY_LOGGED_PATHS = ['iclock/*'];

    private const BODY_LOGGED_TABLES = ['ATTLOG'];

    private const MAX_BODY_LENGTH = 5000;

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('log_started_at', microtime(true));

        return $next($request);
    }

    /**
     * Runs after the response has been sent, so logging never slows the device down.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! config('logging.log_http_requests')) {
            return;
        }

        try {
            $startedAt = $request->attributes->get(
                'log_started_at',
                defined('LARAVEL_START') ? LARAVEL_START : microtime(true)
            );

            $context = [
                'method'      => $request->method(),
                'url'         => $request->fullUrl(),
                'ip'          => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'status'      => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];

            if ($request->is(...self::BODY_LOGGED_PATHS)) {
                $body = $this->deviceBodyIsSafe($request)
                    ? $request->getContent()
                    : '[not logged: may contain passwords or biometric data, ' . strlen($request->getContent()) . ' bytes]';
                $context['body'] = mb_strlen($body) > self::MAX_BODY_LENGTH
                    ? mb_substr($body, 0, self::MAX_BODY_LENGTH) . '… [' . mb_strlen($body) . ' chars total]'
                    : $body;
                $context['response'] = mb_substr((string) $response->getContent(), 0, 500);
            }

            Log::channel('requests')->info("{$context['method']} /{$request->path()} {$context['status']}", $context);
        } catch (Throwable $e) {
            // Logging must never break a request
        }
    }

    private function deviceBodyIsSafe(Request $request): bool
    {
        if ($request->is('iclock/getrequest', 'iclock/devicecmd') || $request->isMethod('get')) {
            return true;
        }

        $table = $request->query('table');

        return $request->is('iclock/cdata') && is_string($table) && in_array(strtoupper($table), self::BODY_LOGGED_TABLES, true);
    }
}
