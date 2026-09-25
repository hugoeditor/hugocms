<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Shop;

use HugoCMS\FileManager\Exception\ApiException;
use HugoCMS\FileManager\FileService;
use HugoCMS\FileManager\Mount;
use HugoCMS\FileManager\MountResolver;

/**
 * Vorschaubilder der Shop-Anbindung.
 *
 * Die Produktbilder liegen auf dem Webserver, nicht in OpensourceERP
 * (Entscheidung E6 im Plan dev/shop-hugocms-trennung.md). OpensourceERP nennt
 * deshalb nur die Namen der Bilder und die Größe; verkleinert wird hier.
 *
 * Wie in OpensourceERP bisher: in die Größe einpassen, Seitenverhältnis
 * behalten, nie vergrößern, Transparenz erhalten, Format der Quelle. Ein
 * Vorschaubild gilt als aktuell, wenn es jünger ist als seine Quelle UND die
 * erwartete Größe hat — so wirkt auch eine geänderte Größeneinstellung.
 *
 * Ein erster Lauf betrifft tausende Bilder. Jeder Aufruf arbeitet deshalb
 * höchstens {@see TIME_BUDGET} Sekunden und nennt, wo es weitergeht; der
 * Aufrufer ruft erneut auf (vom Client gesteuerte Abschnitte, wie bei der
 * Hyperlink-Suche). Kein Hintergrundprozess, kein Zustand auf dem Server.
 *
 * Quelle und Ziel stehen in der Mount-Datei ([shop] images, thumbnails), nicht
 * in der Anfrage: Wohin geschrieben wird, entscheidet HugoCMS.
 */
final class ShopThumbnails
{
    /** Bildarten, die verkleinert werden. */
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public const DEFAULT_IMAGES = 'static/images/products';
    public const DEFAULT_THUMBNAILS = 'static/images/thumbnails';

    /** Größte Seite der Vorschau, wenn keine genannt ist; und die Grenzen. */
    public const DEFAULT_SIZE = 200;
    private const MIN_SIZE = 16;
    private const MAX_SIZE = 2000;

    /** Höchstzahl der Namen je Aufruf. */
    private const MAX_NAMES = 50000;

    /** Sekunden Arbeit je Aufruf. */
    private const TIME_BUDGET = 20.0;

    private readonly Mount $mount;
    private readonly MountResolver $resolver;
    private readonly FileService $files;

    /**
     * @param string $source Hugo-Quellverzeichnis der Webseite
     * @param string $imagesDir Produktbilder, relativ zur Quelle
     * @param string $thumbnailsDir Vorschaubilder, relativ zur Quelle
     */
    public function __construct(
        string $source,
        private readonly string $imagesDir,
        private readonly string $thumbnailsDir,
    ) {
        $this->mount = new Mount('shopthumbs', $source, 'Vorschaubilder', ['read', 'write', 'mkdir'], self::IMAGE_EXT);
        $this->resolver = new MountResolver();
        $this->resolver->add($this->mount);
        $this->files = new FileService($this->resolver);
    }

    /**
     * Bearbeitet die Namen ab $offset, bis die Zeit um ist.
     *
     * @param mixed $names Dateinamen der Produktbilder (ohne Verzeichnis)
     * @return array{created: int, current: int, missing: list<string>, failed: list<string>, next: int, done: bool, total: int}
     */
    public function run(mixed $names, int $size, int $offset): array
    {
        if (!is_array($names) || !array_is_list($names)) {
            throw ApiException::badRequest('SHOP-THUMBNAILS-INVALID');
        }
        if (count($names) > self::MAX_NAMES) {
            throw ApiException::badRequest('SHOP-THUMBNAILS-TOO-MANY', [self::MAX_NAMES]);
        }
        if (!function_exists('imagecreatetruecolor')) {
            throw new ApiException('ECONFIG', 500, 'SHOP-THUMBNAILS-NO-GD');
        }
        $size = max(self::MIN_SIZE, min(self::MAX_SIZE, $size > 0 ? $size : self::DEFAULT_SIZE));
        $offset = max(0, $offset);

        $result = ['created' => 0, 'current' => 0, 'missing' => [], 'failed' => [], 'next' => $offset,
                   'done' => false, 'total' => count($names)];
        $start = microtime(true);

        for ($i = $offset; $i < count($names); $i++) {
            if (microtime(true) - $start > self::TIME_BUDGET) {
                $result['next'] = $i;
                return $result;
            }
            $name = is_string($names[$i]) ? $names[$i] : '';
            match ($this->one($name, $size)) {
                'created' => $result['created']++,
                'current' => $result['current']++,
                'missing' => $result['missing'][] = $name,
                default => $result['failed'][] = $name,
            };
        }

        $result['next'] = count($names);
        $result['done'] = true;

        return $result;
    }

    /** @return string created, current, missing oder failed */
    private function one(string $name, int $size): string
    {
        // Nur ein Dateiname: kein Verzeichnis, nichts Verstecktes, Bildendung
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')
            || str_starts_with($name, '.') || !$this->mount->accepts($name)) {
            return 'failed';
        }

        $source = $this->mount->root() . '/' . $this->imagesDir . '/' . $name;
        if (!is_file($source)) {
            return 'missing';
        }
        $info = @getimagesize($source);
        if ($info === false) {
            return 'failed';
        }
        [$width, $height, $type] = $info;
        $factor = min(1, $size / max($width, $height));
        $newWidth = max(1, (int) round($width * $factor));
        $newHeight = max(1, (int) round($height * $factor));

        $targetRel = $this->thumbnailsDir . '/' . $name;
        $targetAbs = $this->mount->root() . '/' . $targetRel;
        if (is_file($targetAbs) && filemtime($targetAbs) >= filemtime($source)) {
            $have = @getimagesize($targetAbs);
            if ($have !== false && $have[0] === $newWidth && $have[1] === $newHeight) {
                return 'current';
            }
        }

        $binary = $this->scale($source, (int) $type, $width, $height, $newWidth, $newHeight);
        if ($binary === null) {
            return 'failed';
        }

        try {
            $this->ensureDir($this->thumbnailsDir);
            $target = $this->resolver->resolve($this->resolver->encodeId('shopthumbs', $targetRel), false);
            $this->files->putImage($this->mount, $target['rel'], $target['abs'], $binary);
        } catch (ApiException) {
            return 'failed';
        }

        return 'created';
    }

    /** Verkleinert und gibt die Bilddaten im Format der Quelle zurück. */
    private function scale(string $source, int $type, int $width, int $height, int $newWidth, int $newHeight): ?string
    {
        $load = [
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
        ];
        if (!isset($load[$type]) || !function_exists($load[$type])) {
            return null;
        }
        $image = @$load[$type]($source);
        if ($image === false) {
            return null;
        }

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        $ok = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($thumb, null, 85),
            IMAGETYPE_PNG => imagepng($thumb),
            IMAGETYPE_GIF => imagegif($thumb),
            default => function_exists('imagewebp') && imagewebp($thumb, null, 85),
        };
        $binary = (string) ob_get_clean();

        return $ok && $binary !== '' ? $binary : null;
    }

    /** Legt fehlende Verzeichnisse Ebene für Ebene über FileService an. */
    private function ensureDir(string $rel): void
    {
        $current = '';
        foreach (explode('/', $rel) as $segment) {
            $next = $current === '' ? $segment : $current . '/' . $segment;
            if (!is_dir($this->mount->root() . '/' . $next)) {
                $parent = $this->resolver->resolve($this->resolver->encodeId('shopthumbs', $current), true);
                $this->files->makeDir($this->mount, $parent['rel'], $parent['abs'], $segment);
            }
            $current = $next;
        }
    }
}
