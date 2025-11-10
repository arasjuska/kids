<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SanitizeUtf8
{
    public function handle(Request $request, Closure $next)
    {
        $payload = $request->all();
        $this->clean($payload);
        $request->merge($payload);

        return $next($request);
    }

    /**
     * @param  mixed  $value
     */
    private function clean(mixed &$value): void
    {
        if (is_array($value)) {
            foreach ($value as &$item) {
                $this->clean($item);
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        $sanitized = $sanitized === false ? '' : $sanitized;
        $sanitized = preg_replace('/[^\P{C}\n\t]/u', '', $sanitized) ?? '';

        $value = $sanitized;
    }
}
