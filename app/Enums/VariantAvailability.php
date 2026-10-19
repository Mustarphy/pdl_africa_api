<?php

namespace App\Enums;

enum VariantAvailability: string
{
    case Available = 'available';
    case MadeToOrder = 'made_to_order';
    case OutOfStock = 'out_of_stock';
}
