<?php

declare(strict_types=1);

namespace FG\Plugin\Content\Fgautolightbox\Support;

\defined('_JEXEC') or die;

/**
 * Čo sa má zobraziť ako popisok pod obrázkom v lightboxe.
 */
enum CaptionMode: string
{
    case Alt = 'alt';
    case Filename = 'filename';
    case None = 'none';
}
