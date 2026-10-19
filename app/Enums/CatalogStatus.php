<?php

namespace App\Enums;

/** Publication lifecycle shared by categories, collections and products. */
enum CatalogStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
