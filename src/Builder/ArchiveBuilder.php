<?php

/*
 * This file is part of composer/satis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Satis\Builder;

use Composer\Composer;
use Composer\Factory;
use Composer\Util\Filesystem;
use Composer\Package\Archiver\ArchiveManager;
use Composer\Package\CompletePackage;
use Composer\Satis\Event\PreArchiveDumpEvent;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds the archives of the repository.
 *
 * @author James Hautot <james@rezo.net>
 */
class ArchiveBuilder extends Builder
{
    /** @var Composer A Composer instance. */
    private $composer;

    /** @var InputInterface */
    private $input;

    /**
     * {@inheritdoc}
     */
    public function dump(array $packages)
    {
        $helper = new ArchiveBuilderHelper($this->output, $this->config['archive']);
        $basedir = $helper->getDirectory($this->outputDir);
        $this->output->writeln(sprintf("<info>Creating local downloads in '%s'</info>", $basedir));
        $format = $this->config['archive']['format'] ?? 'zip';
        $endpoint = $this->config['archive']['prefix-url'] ?? $this->config['homepage'];
        $includeArchiveChecksum = (bool) ($this->config['archive']['checksum'] ?? true);
        $ignoreFilters = (bool) ($this->config['archive']['ignore-filters'] ?? false);
        $overrideDistType = (bool) ($this->config['archive']['override-dist-type'] ?? false);
        $composerConfig = $this->composer->getConfig();
        $factory = new Factory();
        /* @var \Composer\Downloader\DownloadManager $downloadManager */
        $downloadManager = $this->composer->getDownloadManager();
        /* @var \Composer\Package\Archiver\ArchiveManager $archiveManager */
        $archiveManager = $factory->createArchiveManager($composerConfig, $downloadManager);
        $archiveManager->setOverwriteFiles(false);

        shuffle($packages);

        $progressBar = null;
        $hasStarted = false;
        $verbosity = $this->output->getVerbosity();
        $renderProgress = $this->input->getOption('stats') && OutputInterface::VERBOSITY_NORMAL == $verbosity;

        if ($renderProgress) {
            $packageCount = 0;

            foreach ($packages as $package) {
                if (!$helper->isSkippable($package)) {
                    ++$packageCount;
                }
            }

            $progressBar = new ProgressBar($this->output, $packageCount);
            $progressBar->setFormat(
                ' %current%/%max% [%bar%] %percent:3s%% - Installing %packageName% (%packageVersion%)'
            );
        }

        /* @var \Composer\Package\CompletePackage $package */
        foreach ($packages as $package) {
            if ($helper->isSkippable($package)) {
                continue;
            }

            if ($renderProgress) {
                $progressBar->setMessage($package->getName(), 'packageName');
                $progressBar->setMessage($package->getPrettyVersion(), 'packageVersion');

                if (!$hasStarted) {
                    $progressBar->start();
                    $hasStarted = true;
                } else {
                    $progressBar->display();
                }
            } else {
                $this->output->writeln(
                    sprintf(
                        "<info>Dumping package '%s' in version '%s'.</info>",
                        $package->getName(),
                        $package->getPrettyVersion()
                    )
                );
            }

            try {
                if ($renderProgress) {
                    $this->output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
                }

                $intermediatePath = preg_replace('#[^a-z0-9-_/]#i', '-', $package->getName());

                $packageName = $archiveManager->getPackageFilename($package);

                if ('pear-library' === $package->getType()) {
                    // PEAR packages are archives already
                    $filesystem = new Filesystem();
                    $path = sprintf(
                        '%s/%s/%s.%s',
                        realpath($basedir),
                        $intermediatePath,
                        $packageName,
                        pathinfo($package->getDistUrl(), PATHINFO_EXTENSION)
                    );

                    if (!file_exists($path)) {
                        $downloadDir = sys_get_temp_dir() . '/composer_archiver/' . $packageName;
                        $filesystem->ensureDirectoryExists($downloadDir);
                        $downloadManager->download($package, $downloadDir, false);
                        $filesystem->ensureDirectoryExists(dirname($path));
                        $filesystem->rename($downloadDir . '/' . pathinfo($package->getDistUrl(), PATHINFO_BASENAME), $path);
                        $filesystem->removeDirectory($downloadDir);
                    }

                    // Set archive format to `file` to tell composer to download it as is
                    $archiveFormat = 'file';
                } else {
                    $targetDir = sprintf('%s/%s', $basedir, $intermediatePath);
                    $archiveFormat = $format;

                    if (true === $overrideDistType) {
                        $filesystem = new Filesystem();
                        $filesystem->ensureDirectoryExists($targetDir);
                        $originalDistType = $package->getDistType();
                        $package->setDistType($format);
                        $path = realpath($targetDir) . '/' . $archiveManager->getPackageFilename($package) . '.' . $format;

                        if (!file_exists($path)) {
                            $package->setDistType($originalDistType);
                            $downloaded = $this->archive($archiveManager, $package, $format, $targetDir, $ignoreFilters);
                            $filesystem->rename($downloaded, $path);
                        }
                    } else {
                        $path = $this->archive($archiveManager, $package, $format, $targetDir, $ignoreFilters);
                    }
                }

                $archive = basename($path);
                $distUrl = sprintf('%s/%s/%s/%s', $endpoint, $this->config['archive']['directory'], $intermediatePath, $archive);
                $package->setDistType($archiveFormat);
                $package->setDistUrl($distUrl);
                $package->setDistSha1Checksum($includeArchiveChecksum ? hash_file('sha1', $path) : null);
                $package->setDistReference($package->getSourceReference());

                if ($renderProgress) {
                    $this->output->setVerbosity($verbosity);
                }
            } catch (\Exception $exception) {
                if ($renderProgress) {
                    $this->output->setVerbosity($verbosity);
                }

                if (!$this->skipErrors) {
                    throw $exception;
                }
                $this->output->writeln(sprintf("<error>Skipping Exception '%s'.</error>", $exception->getMessage()));
            }

            if ($renderProgress) {
                $progressBar->advance();
            }
        }

        if ($renderProgress) {
            $progressBar->finish();

            $this->output->writeln('');
        }
    }

    private function archive(ArchiveManager $archiveManager, CompletePackage $package, string $format, string $targetDir, bool $ignoreFilters): string
    {
        $pathIsTarget = false;
        $path = $archiveManager->archivePrepare($package, $format, $targetDir, $pathIsTarget);
        if ($pathIsTarget) {
            $this->output->writeln(sprintf("<info>Reusing existing target: '%s'.</info>", $path));
        } else {
            $this->output->writeln(sprintf("<info>Executing pre-archive-dump-cmd on '%s'.</info>", $path));
            $this->composer->getEventDispatcher()->dispatch(
                'pre-archive-dump-cmd',
                new PreArchiveDumpEvent(
                    'pre-archive-dump-cmd',
                    $path
                )
            );
            $path = $archiveManager->archiveSourceDump($package, $format, $targetDir, $path, $ignoreFilters);
        }

        return $path;
    }

    /**
     * Sets the Composer instance.
     *
     * @param Composer $composer A Composer instance
     *
     * @return $this
     */
    public function setComposer(Composer $composer)
    {
        $this->composer = $composer;

        return $this;
    }

    /**
     * @param InputInterface $input
     *
     * @return $this;
     */
    public function setInput(InputInterface $input)
    {
        $this->input = $input;

        return $this;
    }
}
