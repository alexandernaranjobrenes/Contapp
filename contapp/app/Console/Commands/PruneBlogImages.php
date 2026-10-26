<?php

namespace App\Console\Commands;

use App\Domains\Blog\Services\BlogImageService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Las imágenes del blog que se subieron desde el editor y ningún guardado
 * usó (se cerró la pestaña sin guardar): al día se borran del bucket
 * (CLAUDE.md secc. 34 y 41). Lo corre el programador cada hora.
 */
#[Signature('pivox:prune-blog-images')]
#[Description('Borra del bucket las imágenes del blog que ningún artículo usó')]
class PruneBlogImages extends Command
{
    public function handle(BlogImageService $images): int
    {
        $count = $images->pruneOrphans();

        $this->info("Imágenes del blog sin usar borradas: {$count}.");

        return self::SUCCESS;
    }
}
