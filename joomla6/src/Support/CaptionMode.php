<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Content.fgautolightbox
 * @copyright   Copyright (C) 2026 FG. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

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
