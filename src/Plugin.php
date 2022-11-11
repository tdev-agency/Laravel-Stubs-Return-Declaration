<?php

namespace TDevAgency\LaravelStubsReturnDeclaration;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Installer\PackageEvent;
use Composer\Package\PackageInterface;
use Composer\Script\Event;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;
use RuntimeException;
use Symfony\Component\Process\Process;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    protected Composer $composer;
    protected IOInterface $io;
    protected string $patchesPath;
    protected ProcessExecutor $executor;

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::PRE_INSTALL_CMD => array('checkPatches'),
            ScriptEvents::PRE_UPDATE_CMD => array('checkPatches'),

            PackageEvents::POST_PACKAGE_INSTALL => array('postInstall', 10),
            PackageEvents::POST_PACKAGE_UPDATE => array('postInstall', 10),
        ];
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->patchesPath = __DIR__ . '/../patches/';
        $this->executor = new ProcessExecutor($this->io);
    }

    public function checkPatches(Event $event)
    {
        try {
            $repositoryManager = $this->composer->getRepositoryManager();
            $localRepository = $repositoryManager->getLocalRepository();
            $installationManager = $this->composer->getInstallationManager();
            $packages = $localRepository->getPackages();

            // Remove packages for which the patch set has changed.
            $promises = array();
            foreach ($packages as $package) {
                if (!($package instanceof AliasPackage)) {
                    if ($package->getName() === 'laravel/framework') {
                        $uninstallOperation = new UninstallOperation(
                            $package,
                            'Removing package so it can be re-installed and re-patched.'
                        );
                        $this->io->write(
                            '<info>Removing package ' . $package->getName(
                            ) . ' so that it can be re-installed and re-patched.</info>'
                        );
                        $promises[] = $installationManager->uninstall($localRepository, $uninstallOperation);
                    }
                }
            }
            $promises = array_filter($promises);
            if ($promises) {
                $this->composer->getLoop()->wait($promises);
            }
        }
            // If the Locker isn't available, then we don't need to do this.
            // It's the first time packages have been installed.
        catch (\LogicException $e) {
            return;
        }
    }

    public function uninstall(
        Composer $composer,
        IOInterface $io
    ) {
    }

    public function postInstall(PackageEvent $event)
    {
        $operation = $event->getOperation();
        $package = $this->getPackageFromOperation($operation);
        $patchesPath = $this->patchesPath . $package->getName();
        $manager = $event->getComposer()->getInstallationManager();
        $installPath = $manager->getInstaller($package->getType())->getInstallPath($package);
        if (!file_exists($patchesPath)) {
            return;
        }

        $files = scandir($patchesPath);

        if ($files === false) {
            return;
        }

        $patches = [];
        $this->collectPaches($patches, $files, $patchesPath, $package->getVersion());

        foreach ($patches as $patch) {
            $content = trim(file_get_contents($patch));
            if ($content === '') {
                continue;
            }
            $patched = $this->applyPatchWithGit($installPath, ['-p1'], $patch);

            // In some rare cases, git will fail to apply a patch, fallback to using
            // the 'patch' command.
            if (!$patched) {
                $this->executeCommand(
                    "patch %s --no-backup-if-mismatch -d %s < %s",
                    '-p1',
                    $installPath,
                    $patch
                );
            }
        }
    }

    /**
     * Get a Package object from an OperationInterface object.
     *
     * @param OperationInterface $operation
     * @return PackageInterface
     * @throws \Exception
     */
    protected function getPackageFromOperation(
        OperationInterface $operation
    ): PackageInterface {
        if ($operation instanceof InstallOperation) {
            $package = $operation->getPackage();
        } elseif ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            throw new RuntimeException('Unknown operation: ' . get_class($operation));
        }

        return $package;
    }

    protected function collectPaches(
        array &$patches,
        array $files,
        string $patchesPath,
        ?string $packageVersion = null
    ) {
        if (null !== $packageVersion) {
            $directories = glob($patchesPath . '/*', GLOB_ONLYDIR);

            $directories = array_filter($directories, function ($dir) use ($packageVersion) {
                $pathArray = explode('/', $dir);
                $version = array_pop($pathArray);
                return version_compare($version, $packageVersion, "<=");
            });
            usort($directories, function ($a, $b) {
                $aPathArray = explode('/', $a);
                $bPathArray = explode('/', $b);
                $versionA = array_pop($aPathArray);
                $versionB = array_pop($bPathArray);
                if (version_compare($versionA, $versionB, '==')) {
                    return 0;
                }
                return version_compare($versionA, $versionB, '>') ? -1 : 1;
            });
            foreach ($directories as $directory) {
                $this->collectPaches($patches, scandir($directory), $directory);
            }
        }
        foreach ($files as $file) {
            if (in_array($file, ['.', '..'], true)) {
                continue;
            }

            $filePath = $patchesPath . '/' . $file;
            if (!array_key_exists($file, $patches) && is_file($filePath)) {
                $patches[$file] = $filePath;
                // Attempt to apply with git apply.
            }
        }
    }

    /**
     * Attempts to apply a patch with git apply.
     *
     * @param $installPath
     * @param $patch_levels
     * @param $filename
     *
     * @return bool
     *   TRUE if patch was applied, FALSE otherwise.
     */
    protected function applyPatchWithGit(
        $installPath,
        $patch_levels,
        $filename
    ): bool {
        // Do not use git apply unless the install path is itself a git repo
        // @see https://stackoverflow.com/a/27283285
        if (!is_dir($installPath . '/.git')) {
            return false;
        }

        $patched = false;
        foreach ($patch_levels as $patch_level) {
            if ($this->io->isVerbose()) {
                $comment = 'Testing ability to patch with git apply.';
                $comment .= ' This command may produce errors that can be safely ignored.';
                $this->io->write('<comment>' . $comment . '</comment>');
            }
            $checked = $this->executeCommand(
                'git -C %s apply --check -v %s %s',
                $installPath,
                $patch_level,
                $filename
            );
            $output = $this->executor->getErrorOutput();
            if (str_starts_with($output, 'Skipped')) {
                // Git will indicate success but silently skip patches in some scenarios.
                //
                // @see https://github.com/cweagans/composer-patches/pull/165
                $checked = false;
            }
            if ($checked) {
                // Apply the first successful style.
                $patched = $this->executeCommand('git -C %s apply %s %s', $installPath, $patch_level, $filename);
                break;
            }
        }
        return $patched;
    }

    /**
     * Executes a shell command with escaping.
     *
     * @param string $cmd
     * @return bool
     */
    protected function executeCommand($cmd): bool
    {
        // Shell-escape all arguments except the command.
        $args = func_get_args();
        foreach ($args as $index => $arg) {
            if ($index !== 0) {
                $args[$index] = escapeshellarg($arg);
            }
        }

        // And replace the arguments.
        $command = sprintf(...$args);
        $output = '';
        if ($this->io->isVerbose()) {
            $this->io->write('<comment>' . $command . '</comment>');
            $io = $this->io;
            $output = function ($type, $data) use ($io) {
                if ($type == Process::ERR) {
                    $io->write('<error>' . $data . '</error>');
                } else {
                    $io->write('<comment>' . $data . '</comment>');
                }
            };
        }
        return ($this->executor->execute($command, $output) == 0);
    }

    public function deactivate(
        Composer $composer,
        IOInterface $io
    ) {
    }
}
