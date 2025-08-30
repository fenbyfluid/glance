<?php

namespace App\Enums;

enum ExtractedTextStatus: int
{
    case TimedOut = -2;
    case Error = -1;
    case Pending = 0;
    case Complete = 1;
    case Empty = 2;
}
