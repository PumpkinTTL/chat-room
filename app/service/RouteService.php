<?php

namespace app\service;

class RouteService extends \think\Service
{
    public function boot()
    {
        $this->loadRoutesFrom($this->app->getRootPath() . 'route/app.php');
    }
}
