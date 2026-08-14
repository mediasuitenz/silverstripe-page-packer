<?php

namespace MadeCurious\PagePacker\Serialization;

use RuntimeException;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use ZipArchive;

/**
 * Builds/reads the module's export container: a single .zip with `manifest.json` (the node graph
 * from {@see SiteTreeSerializer}) plus an optional `assets/<hash>/<name>`
 * entry per referenced File/Image. Always a zip regardless of the "include assets" toggle, so
 * the CMS upload control always expects one consistent extension.
 */
class AssetBundler
{
    use Injectable;
    use Configurable;

    private static $import_folder = 'page-packer-imports';

    /** @var array<string, array> hash => ['filename', 'mime', 'zipPath'] collected during export */
    private array $assets = [];

    /** @var array<string, string> hash => raw bytes, only populated when embedding is requested */
    private array $embeddedBytes = [];

    /** @var string|null local filesystem path of an opened import zip, set by readZip() */
    private ?string $openZipPath = null;

    /**
     * Records that $file was referenced for inclusion in the export manifest
     */
    public function captureAsset(File $file, bool $embedBytes): string
    {
        $hash = $file->getHash();

        if ($hash === null || $hash === '') {
            throw new RuntimeException("File #{$file->ID} has no stored content to export.");
        }

        if (!isset($this->assets[$hash])) {
            $this->assets[$hash] = [
                'filename' => $file->Name,
                'class' => get_class($file),
                'mime' => $file->getMimeType(),
                'zipPath' => "assets/{$hash}/{$file->Name}",
            ];
        }

        if ($embedBytes && !isset($this->embeddedBytes[$hash])) {
            $this->embeddedBytes[$hash] = $file->getString();
        }

        return $hash;
    }

    /**
     * @return array<string, array> The `assets` section of the export manifest.
     */
    public function manifest(): array
    {
        return $this->assets;
    }

    /**
     * Writes manifest.json + embedded assets to a new zip, returns stored a File
     */
    public function writeZip(array $manifest, string $filename): File
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'stie-export-');

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create export zip at {$zipPath}.");
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        foreach ($manifest['assets'] ?? [] as $hash => $assetInfo) {
            if (isset($this->embeddedBytes[$hash])) {
                $zip->addFromString($assetInfo['zipPath'], $this->embeddedBytes[$hash]);
            }
        }

        $zip->close();

        $folder = Folder::find_or_make(static::config()->get('import_folder'));
        $file = File::create();
        $file->setFromLocalFile($zipPath, $filename);
        $file->ParentID = $folder->ID;
        $file->write();

        unlink($zipPath);

        return $file;
    }

    /**
     * Opens an uploaded export zip and returns its decoded manifest. Must be called before any
     * {@see materializeAsset()} call against the same manifest, since asset bytes are read
     * lazily from the opened zip on demand.
     */
    public function readZip(File $zipFile): array
    {
        $this->openZipPath = tempnam(sys_get_temp_dir(), 'stie-import-');
        file_put_contents($this->openZipPath, $zipFile->getString());

        $zip = new ZipArchive();

        if ($zip->open($this->openZipPath) !== true) {
            throw new RuntimeException('The uploaded file is not a valid zip archive.');
        }

        $manifestJson = $zip->getFromName('manifest.json');
        $zip->close();

        if ($manifestJson === false) {
            throw new RuntimeException('The uploaded zip does not contain a manifest.json.');
        }

        $manifest = json_decode($manifestJson, true);

        if (!is_array($manifest)) {
            throw new RuntimeException('The uploaded manifest.json is not valid JSON.');
        }

        return $manifest;
    }

    /**
     * Resolves an asset reference from a manifest's `assets` section to a real File/Image on
     * this site
     */
    public function materializeAsset(string $hash, array $assetsManifest): ?File
    {
        $existing = File::get()->filter(['FileHash' => $hash])->first();

        if ($existing) {
            return $existing;
        }

        if (!isset($assetsManifest[$hash])) {
            return null;
        }

        $assetInfo = $assetsManifest[$hash];
        $bytes = $this->readEmbeddedBytes($assetInfo['zipPath'] ?? '');

        if ($bytes === null) {
            return null;
        }

        $class = $assetInfo['class'] ?? File::class;

        if (!is_a($class, File::class, true)) {
            $class = File::class;
        }

        $folder = Folder::find_or_make(static::config()->get('import_folder'));
        $file = $class::create();
        $file->setFromString($bytes, $assetInfo['filename'] ?? basename($assetInfo['zipPath']));
        $file->ParentID = $folder->ID;
        $file->write();

        return $file;
    }

    /**
     * Whether the opened zip (see {@see readZip()}) actually contains embedded bytes for any of
     * the manifest's referenced assets
     */
    public function hasEmbeddedAssets(array $manifest): bool
    {
        $assets = (array) ($manifest['assets'] ?? []);

        if (!$assets || $this->openZipPath === null) {
            return false;
        }

        $zip = new ZipArchive();

        if ($zip->open($this->openZipPath) !== true) {
            return false;
        }

        $found = false;

        foreach ($assets as $assetInfo) {
            if ($zip->locateName($assetInfo['zipPath'] ?? '') !== false) {
                $found = true;

                break;
            }
        }

        $zip->close();

        return $found;
    }

    private function readEmbeddedBytes(string $zipPath): ?string
    {
        if ($zipPath === '' || $this->openZipPath === null) {
            return null;
        }

        $zip = new ZipArchive();

        if ($zip->open($this->openZipPath) !== true) {
            return null;
        }

        $bytes = $zip->getFromName($zipPath);
        $zip->close();

        return $bytes === false ? null : $bytes;
    }

    public function __destruct()
    {
        if ($this->openZipPath && file_exists($this->openZipPath)) {
            unlink($this->openZipPath);
        }
    }
}
