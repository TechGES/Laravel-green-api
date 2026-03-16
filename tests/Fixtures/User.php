<?php

namespace Ges\LaravelGreenApi\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class User extends Model
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}
