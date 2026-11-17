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

    /**
     * Tailwind classes coloring a performer's tag. Written out in full so Tailwind finds them in this file.
     */
    public function tagClasses(): string
    {
        return match ($this) {
            self::Female, self::TransgenderFemale => 'bg-pink-100 text-pink-800 dark:bg-pink-900/50 dark:text-pink-200',
            self::Male, self::TransgenderMale => 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-200',
            self::Intersex => 'bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-200',
            self::NonBinary => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
        };
    }
}
