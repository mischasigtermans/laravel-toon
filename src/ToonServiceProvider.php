<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon;

use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use MischaSigtermans\Toon\Converters\ToonDecoder;
use MischaSigtermans\Toon\Converters\ToonEncoder;
use MischaSigtermans\Toon\Facades\Toon as ToonFacade;

class ToonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/toon.php', 'toon');

        $this->app->singleton(Toon::class, function () {
            return new Toon(new ToonEncoder, new ToonDecoder);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/toon.php' => config_path('toon.php'),
        ], 'toon-config');

        Collection::macro(
            'toToon',
            fn (): string => ToonFacade::encode($this)
        );
    }
}
