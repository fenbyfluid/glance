<?php

namespace App\Media\MediaInfo;

enum StreamCodecType: string
{
    case Video = 'video';
    case Audio = 'audio';
    case Data = 'data';
    case Subtitle = 'subtitle';
    case Attachment = 'attachment';
}
