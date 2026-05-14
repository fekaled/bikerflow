<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case RestaurantManager = 'restaurant_manager';
    case Biker = 'biker';

    /**
     * Get human-readable labels for all cases.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'admin' => 'Administrador',
            'restaurant_manager' => 'Gerente de Restaurante',
            'biker' => 'Entregador',
        ];
    }
}
