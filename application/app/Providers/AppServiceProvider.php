<?php

namespace App\Providers;

use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
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
        FilamentColor::register([
            'gray' => Color::Zinc,
            'info' => Color::Blue,
            'danger' =>  Color::Red,
            'primary' => Color::Rose,
            'success' => Color::Green,
            'warning' => Color::Rose,
        ]);
    }
}
