<?php

namespace App\Enums;

enum StashFingerprintAlgorithm: string
{
    case MD5 = 'MD5';
    case OsHash = 'OSHASH';
    case PHash = 'PHASH';
}
