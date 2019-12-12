<?php

namespace Valet;

use DomainException;

class PhpFpm
{
    var $brew, $cli, $files;

    var $taps = [
        'homebrew/homebrew-core'
    ];

    /**
     * Create a new PHP FPM class instance.
     *
     * @param  Brew  $brew
     * @param  CommandLine  $cli
     * @param  Filesystem  $files
     * @return void
     */
    function __construct(Brew $brew, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->brew = $brew;
        $this->files = $files;
    }

    /**
     * Install and configure PhpFpm.
     *
     * @return void
     */
    function install()
    {
        if (! $this->brew->hasInstalledPhp()) {
            $this->brew->ensureInstalled('php', [], $this->taps);
        }

        $this->files->ensureDirExists('/usr/local/var/log', user());

        $this->updateConfiguration();

        $this->restart();
    }

    /**
     * Forcefully uninstall all of Valet's supported PHP versions and configurations
     * 
     * @return void
     */
    function uninstall()
    {
        $this->brew->uninstallAllPhpVersions();
        rename('/usr/local/etc/php', '/usr/local/etc/php-valet-bak'.time());
        $this->cli->run('rm -rf /usr/local/var/log/php-fpm.log');
    }

    /**
     * Update the PHP FPM configuration.
     * @TODO - Future: number the valet.sock file according to php version
     * @TODO - Future: make the valet.sock path configurable (for better multi-user support)
     *
     * @return void
     */
    function updateConfiguration()
    {
        info('Updating PHP configuration...');

        $fpmConfigFile = $this->fpmConfigPath();

        $this->files->ensureDirExists(dirname($fpmConfigFile), user());

        // rename (to disable) old FPM Pool configuration, regardless of whether it's a default config or one customized by an older Valet version
        $oldFile = dirname($fpmConfigFile) . '/www.conf';
        if (file_exists($oldFile)) {
            rename($oldFile, $oldFile . '-backup');
        }

        if (false === strpos($fpmConfigFile, '5.6')) {
            // for PHP 7 we can simply drop in a valet-specific fpm pool config, and not touch the default config
            $contents = $this->files->get(__DIR__.'/../stubs/etc-phpfpm-valet.conf');
            $contents = str_replace(['VALET_USER', 'VALET_HOME_PATH'], [user(), VALET_HOME_PATH], $contents);
        } else {
            // for PHP 5 we must do a direct edit of the fpm pool config to switch it to Valet's needs
            $contents = $this->files->get($fpmConfigFile);
            $contents = preg_replace('/^user = .+$/m', 'user = '.user(), $contents);
            $contents = preg_replace('/^group = .+$/m', 'group = staff', $contents);
            $contents = preg_replace('/^listen = .+$/m', 'listen = '.VALET_HOME_PATH.'/valet.sock', $contents);
            $contents = preg_replace('/^;?listen\.owner = .+$/m', 'listen.owner = '.user(), $contents);
            $contents = preg_replace('/^;?listen\.group = .+$/m', 'listen.group = staff', $contents);
            $contents = preg_replace('/^;?listen\.mode = .+$/m', 'listen.mode = 0777', $contents);
        }
        $this->files->put($fpmConfigFile, $contents);


        // ini stubs
        // @TODO - scan stubs dir for php-*.ini naming convention, so we can distribute more defaults easily
        // @TODO - scan .config/valet/stubs dir if exists (ref getSiteStub() in https://github.com/laravel/valet/pull/941 )
        $fpmConfigPath = dirname($this->fpmConfigPath());

        foreach (['php-memory-limits.ini', 'php-error-log.ini'] as $file) {
            $contents = $this->files->get(__DIR__ . '/../stubs/' . $file);
            $contents = str_replace(['VALET_USER', 'VALET_HOME_PATH'], [user(), VALET_HOME_PATH], $contents);
            $destFile = str_replace('/php-fpm.d', '', $fpmConfigPath);
            $destFile .= '/conf.d/' . $file;
            $this->files->ensureDirExists(dirname($destFile), user());
            $this->files->putAsUser($destFile, $contents);
        }
    }

    /**
     * Restart the PHP FPM process.
     *
     * @return void
     */
    function restart()
    {
        $this->brew->restartLinkedPhp();
    }

    /**
     * Stop the PHP FPM process.
     *
     * @return void
     */
    function stop()
    {
        call_user_func_array(
            [$this->brew, 'stopService'],
            Brew::SUPPORTED_PHP_VERSIONS
        );
    }

    /**
     * Get the path to the FPM configuration file for the current PHP version.
     *
     * @return string
     */
    function fpmConfigPath()
    {
        $version = $this->brew->linkedPhp();

        $versionNormalized = preg_replace(
            '/php@?(\d)\.?(\d)/',
            '$1.$2',
            $version === 'php' ? Brew::LATEST_PHP_VERSION : $version
        );

        return $versionNormalized === '5.6'
            ? '/usr/local/etc/php/5.6/php-fpm.conf'
            : "/usr/local/etc/php/${versionNormalized}/php-fpm.d/valet-fpm.conf";
    }

    /**
     * Only stop running php services
     */
    function stopRunning()
    {
        $this->brew->stopService(
            $this->brew->getRunningServices()
                ->filter(function ($service) {
                    return substr($service, 0, 3) === 'php';
                })
                ->all()
        );
    }

    /**
     * Use a specific version of php
     *
     * @param $version
     * @return string
     */
    function useVersion($version)
    {
        $version = $this->validateRequestedVersion($version);

        // Install the relevant formula if not already installed
        $this->brew->ensureInstalled($version);

        // Unlink the current php if there is one
        if ($this->brew->hasLinkedPhp()) {
            $currentVersion = $this->brew->getLinkedPhpFormula();
            info(sprintf('Unlinking current version: %s', $currentVersion));
            $this->brew->unlink($currentVersion);
        }

        info(sprintf('Linking new version: %s', $version));
        $this->brew->link($version, true);

        $this->install();

        return $version === 'php' ? $this->brew->determineAliasedVersion($version) : $version;
    }

    /**
     * Validate the requested version to be sure we can support it.
     *
     * @param $version
     * @return string
     */
    function validateRequestedVersion($version)
    {
        // If passed php7.2 or php72 formats, normalize to php@7.2 format:
        $version = preg_replace('/(php)([0-9+])(?:.)?([0-9+])/i', '$1@$2.$3', $version);

        if ($version === 'php') {
            if (strpos($this->brew->determineAliasedVersion($version), '@')) {
                return $version;
            }
        
            if ($this->brew->hasInstalledPhp()) {
                throw new DomainException('Brew is already using PHP '.PHP_VERSION.' as \'php\' in Homebrew. To use another version, please specify. eg: php@7.3');
            }
        }

        if (!$this->brew->supportedPhpVersions()->contains($version)) {
            throw new DomainException(
                sprintf(
                    'Valet doesn\'t support PHP version: %s (try something like \'php@7.3\' instead)',
                    $version
                )
            );
        }

        return $version;
    }

    /**
     * Check and optionally repair Valet's PHP config.
     * @param  boolean $repair
     */
    function checkConfiguration($repair = false)
    {
        output($this->cli->runAsUser("brew list --versions | grep -E 'php(@\d\.\d)?'"));
        output($this->cli->runAsUser('which -a php'));
        output($this->cli->runAsUser('php -v'));

        $fpmConfigPath = dirname($this->fpmConfigPath());
        $destFile = $fpmConfigPath . '/valet-fpm.conf';
        $this->files->exists($destFile);

        /** 
         * thinking aloud ...
         * 
         * @TODO - can we really test the PHP config? Or just for Valet essentials?
         *
        ls -al /usr/local/etc/php 
        ls -al /usr/local/etc/php/7.4 
        ls -al /usr/local/etc/php/7.4/php-fpm.d
        ls -al /usr/local/etc/php/7.4/conf.d

        * what about forced re-linking with homebrew, when there appear to be conflicts about which version is actually running?
        * Concern: using "brew reinstall" leaves fragments of the recipe, named "-reinstall": do we clean this up? does it conflict with us?

        * What about brew permissions? We run PHP as root, which changes file ownership, meaning brew can't do manual cleanups outside of valet
        * and thus manually copy/pasting brew's suggested `sudo rm` command is required. 
        *
        * What about etc dir permissions? It's only been rare situations where this has ever been an issue, so is it worth checking? changing? mentioning?
        sudo chown -R MYUSER:MYGROUP /usr/local/etc/php/7.3   (before installing php again)
        */
       
         /*
         * @TODO - explore optionally supporting built-in PHP version if compatible, but still updating its configuration enough for Valet to use it? ** HERE BE DRAGONS **
         */
    }
}
