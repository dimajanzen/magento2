<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Asset;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\View\Design\FileResolution\Fallback\Resolver\Simple;

/**
 * A service for preprocessing content of assets
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Source
{
    /**
     * @var \Magento\Framework\Filesystem
     */
    private $filesystem;

    /**
     * @var \Magento\Framework\View\Asset\PreProcessor\Cache
     */
    protected $cache;

    /**
     * @var \Magento\Framework\Filesystem\Directory\ReadInterface
     */
    protected $rootDir;

    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface
     */
    protected $varDir;

    /**
     * @var \Magento\Framework\View\Asset\PreProcessor\Pool
     */
    private $preProcessorPool;

    /**
     * @var \Magento\Framework\View\Design\FileResolution\Fallback\StaticFile
     */
    protected $fallback;

    /**
     * @var \Magento\Framework\View\Design\Theme\ListInterface
     */
    private $themeList;

    /**
     * @var string
     */
    private $appMode;

    /**
     * @param PreProcessor\Cache $cache
     * @param \Magento\Framework\Filesystem $filesystem
     * @param PreProcessor\Pool $preProcessorPool
     * @param \Magento\Framework\View\Design\FileResolution\Fallback\StaticFile $fallback
     * @param \Magento\Framework\View\Design\Theme\ListInterface $themeList
     * @param string $appMode
     */
    public function __construct(
        PreProcessor\Cache $cache,
        \Magento\Framework\Filesystem $filesystem,
        PreProcessor\Pool $preProcessorPool,
        \Magento\Framework\View\Design\FileResolution\Fallback\StaticFile $fallback,
        \Magento\Framework\View\Design\Theme\ListInterface $themeList,
        $appMode = \Magento\Framework\App\State::MODE_DEFAULT
    ) {
        $this->cache = $cache;
        $this->filesystem = $filesystem;
        $this->rootDir = $filesystem->getDirectoryRead(DirectoryList::ROOT);
        $this->varDir = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->preProcessorPool = $preProcessorPool;
        $this->fallback = $fallback;
        $this->themeList = $themeList;
        $this->appMode = $appMode;
    }

    /**
     * Get absolute path to the asset file
     *
     * @param LocalInterface $asset
     * @return bool|string
     */
    public function getFile(LocalInterface $asset)
    {
        $result = $this->preProcess($asset);
        if (!$result) {
            return false;
        }
        list($dirCode, $path) = $result;
        return $this->filesystem->getDirectoryRead($dirCode)->getAbsolutePath($path);
    }

    /**
     * Get content of an asset
     *
     * @param LocalInterface $asset
     * @return bool|string
     */
    public function getContent(LocalInterface $asset)
    {
        $result = $this->preProcess($asset);
        if (!$result) {
            return false;
        }
        list($dirCode, $path) = $result;
        return $this->filesystem->getDirectoryRead($dirCode)->readFile($path);
    }

    /**
     * Perform necessary preprocessing and materialization when the specified asset is requested
     *
     * Returns an array of two elements:
     * - directory code where the file is supposed to be found
     * - relative path to the file
     *
     * Automatically caches the obtained successful results or returns false if source file was not found
     *
     * @param LocalInterface $asset
     * @return array|bool
     */
    private function preProcess(LocalInterface $asset)
    {
        $sourceFile = $this->findSourceFile($asset);
        if (!$sourceFile) {
            return false;
        }
        $dirCode = DirectoryList::ROOT;
        $path = $this->rootDir->getRelativePath($sourceFile);
        $cacheId = $path . ':' . $asset->getPath();
        $cached = $this->cache->load($cacheId);
        if ($cached) {
            return unserialize($cached);
        }
        $chain = new \Magento\Framework\View\Asset\PreProcessor\Chain(
            $asset,
            $this->rootDir->readFile($path),
            $this->getContentType($path),
            $path,
            $this->appMode
        );
        $preProcessors = $this->preProcessorPool
            ->getPreProcessors($chain->getOrigContentType(), $chain->getTargetContentType());
        foreach ($preProcessors as $processor) {
            $processor->process($chain);
        }
        $chain->assertValid();
        if ($chain->isChanged()) {
            $dirCode = DirectoryList::VAR_DIR;
            $path = DirectoryList::TMP_MATERIALIZATION_DIR . '/source/' . $chain->getTargetAssetPath();
            $this->varDir->writeFile($path, $chain->getContent());
        }
        $result = [$dirCode, $path];
        $this->cache->save(serialize($result), $cacheId);
        return $result;
    }

    /**
     * Infer a content type from the specified path
     *
     * @param string $path
     * @return string
     */
    public function getContentType($path)
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Search for asset file depending on its context type
     *
     * @param LocalInterface $asset
     * @return bool|string
     * @throws \LogicException
     */
    private function findSourceFile(LocalInterface $asset)
    {
        $context = $asset->getContext();
        if ($context instanceof \Magento\Framework\View\Asset\File\FallbackContext) {
            $result = $this->findFileThroughFallback($asset, $context);
        } elseif ($context instanceof \Magento\Framework\View\Asset\File\Context) {
            $result = $this->findFile($asset, $context);
        } else {
            $type = get_class($context);
            throw new \LogicException("Support for {$type} is not implemented.");
        }
        return $result;
    }

    /**
     * Find asset file via fallback mechanism
     *
     * @param LocalInterface $asset
     * @param \Magento\Framework\View\Asset\File\FallbackContext $context
     * @return bool|string
     */
    private function findFileThroughFallback(
        LocalInterface $asset,
        \Magento\Framework\View\Asset\File\FallbackContext $context
    ) {
        $themeModel = $this->themeList->getThemeByFullPath($context->getAreaCode() . '/' . $context->getThemePath());
        $sourceFile = $this->fallback->getFile(
            $context->getAreaCode(),
            $themeModel,
            $context->getLocaleCode(),
            $asset->getFilePath(),
            $asset->getModule()
        );
        return $sourceFile;
    }

    /**
     * Find asset file by simply appending its path to the directory in context
     *
     * @param LocalInterface $asset
     * @param \Magento\Framework\View\Asset\File\Context $context
     * @return string
     */
    private function findFile(LocalInterface $asset, \Magento\Framework\View\Asset\File\Context $context)
    {
        $dir = $this->filesystem->getDirectoryRead($context->getBaseDirType());
        Simple::assertFilePathFormat($asset->getFilePath());
        return $dir->getAbsolutePath($asset->getPath());
    }

    /**
     * @param \Magento\Framework\View\Asset\LocalInterface $asset
     *
     * @return bool|string
     */
    public function findRelativeSourceFilePath(LocalInterface $asset)
    {
        $sourceFile = $this->findSourceFile($asset);
        if (!$sourceFile) {
            return false;
        }
        return $this->rootDir->getRelativePath($sourceFile);
    }
}
