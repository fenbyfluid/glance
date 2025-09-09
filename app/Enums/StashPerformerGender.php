<?php

namespace App\Enums;

enum StashPerformerGender: string
{
    case Female = 'FEMALE';
    case Intersex = 'INTERSEX';
    case Male = 'MALE';
    case NonBinary = 'NON_BINARY';
    case TransgenderFemale = 'TRANSGENDER_FEMALE';
    case TransgenderMale = 'TRANSGENDER_MALE';
}
