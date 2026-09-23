<?php

declare(strict_types=1);

namespace App\DTO;

enum MediaType: string
{
    case VIDEO = 'video';
    case CAROUSEL = 'carousel';
    case UNKNOWN = 'unknown';
}