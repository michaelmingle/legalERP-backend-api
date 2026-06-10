<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Please login first'
            ], 401);
        }

        $user = Auth::user();
        
        // Get user's role (from the role column in users table)
        $userRole = strtolower($user->role);
        
        // If user has super_admin role, allow access to everything
        if ($userRole === 'super_admin') {
            return $next($request);
        }
        
        // Check if user has any of the required roles
        foreach ($roles as $role) {
            if ($userRole === strtolower($role)) {
                return $next($request);
            }
        }
        
        // Forbidden - user doesn't have required role
        return response()->json([
            'success' => false,
            'message' => 'Forbidden - You do not have permission to access this resource',
            'required_roles' => $roles,
            'user_role' => $userRole
        ], 403);
    }
}