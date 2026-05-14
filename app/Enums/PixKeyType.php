<?php

namespace App\Enums;

enum PixKeyType: string
{
    case Cpf = 'cpf';
    case Phone = 'phone';
    case Email = 'email';
    case Random = 'random';
}
