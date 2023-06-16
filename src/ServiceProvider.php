<?php

namespace ZhMead\TianquePayment;

use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Laravel\Lumen\Application as LumenApplication;

class ServiceProvider extends LaravelServiceProvider
{
    public function register()
    {
        $this->setupConfig();
    }

    protected function setupConfig()
    {
        $source = realpath(__DIR__ . '/config/tianque.php');
        if ($this->app instanceof LumenApplication) {
            $this->app->configure('tianque');
        } else {
            $this->publishes([$source => config_path('tianque.php')], 'tianque');
        }

        $this->mergeConfigFrom($source, 'tianque');
    }
}