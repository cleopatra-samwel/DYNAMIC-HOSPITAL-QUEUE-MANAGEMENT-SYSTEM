<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 8's app/Listeners/* classes (NotifyOnTicketCalled,
        // NotifyOnTicketStatusChanged, NotifyApproachingOnPriorityRecalculated)
        // need no registration here — Application::configure() in
        // bootstrap/app.php always chains ->withEvents() internally before
        // any of that file's own with*() calls run, which auto-discovers
        // every class in app/Listeners with a type-hinted handle(SomeEvent
        // $event) method and wires it up automatically. An explicit
        // Event::listen() call for the same pair here would silently
        // double-register it — confirmed the hard way: each of those three
        // events was firing its listener twice, doubling every Notification
        // row it wrote, until this was removed.
    }
}
