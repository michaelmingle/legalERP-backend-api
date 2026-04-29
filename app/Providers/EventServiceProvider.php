<?php

namespace App\Providers;

use App\Listeners\LogActivityListener;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // Model events
        'eloquent.created: *' => [LogActivityListener::class],
        'eloquent.updated: *' => [LogActivityListener::class],
        'eloquent.deleted: *' => [LogActivityListener::class],
        
        // Auth events
        Login::class => [
            'App\Listeners\LogAuthActivity@logLogin',
        ],
        Logout::class => [
            'App\Listeners\LogAuthActivity@logLogout',
        ],
    ];
}