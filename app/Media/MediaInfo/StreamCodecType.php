<?php

namespace App\Media\MediaInfo;

enum StreamCodecType: string
{
    case Video = 'video';
    case Audio = 'audio';
    case Data = 'data';
    case Subtitle = 'subtitle';
    case Attachment = 'attachment';

    /**
     * Where streams of this type are listed: video, audio, subtitles, then anything else.
     */
    public function displayOrder(): int
    {
        return match ($this) {
            self::Video => 0,
            self::Audio => 1,
            self::Subtitle => 2,
            self::Data => 3,
            self::Attachment => 4,
        };
    }
}
