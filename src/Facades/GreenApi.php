<?php

namespace Ges\LaravelGreenApi\Facades;

use Illuminate\Support\Facades\Facade;

class GreenApi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'green-api';
    }
}
