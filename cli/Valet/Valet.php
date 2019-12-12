<?php

namespace Valet;

use Httpful\Request;

class Valet
{
    var $cli, $files, $latestVersion;

    var $valetBin = '/usr/local/bin/valet';

    /**
     * Create a new Valet instance.
     *
     * @param  CommandLine  $cli
     * @param  Filesystem  $files
     */
    function __construct(CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
    }

    /**
     * Symlink the Valet Bash script into the user's local bin.
     *
     * @return void
     */
    function symlinkToUsersBin()
    {
        $this->unlinkFromUsersBin();

        $this->cli->runAsUser('ln -s "'.realpath(__DIR__.'/../../valet').'" '.$this->valetBin);
    }

    /**
     * Remove the symlink from the user's local bin.
     *
     * @return void
     */
    function unlinkFromUsersBin()
    {
        $this->cli->quietlyAsUser('rm '.$this->valetBin);
    }

    /**
     * Get the paths to all of the Valet extensions.
     *
     * @return array
     */
    function extensions()
    {
        if (! $this->files->isDir(VALET_HOME_PATH.'/Extensions')) {
            return [];
        }

        return collect($this->files->scandir(VALET_HOME_PATH.'/Extensions'))
                    ->reject(function ($file) {
                        return is_dir($file);
                    })
                    ->map(function ($file) {
                        return VALET_HOME_PATH.'/Extensions/'.$file;
                    })
                    ->values()->all();
    }

    /**
     * Determine if this is the latest version of Valet.
     *
     * @param  string  $currentVersion
     * @return bool
     * @throws \Httpful\Exception\ConnectionErrorException
     */
    function onLatestVersion($currentVersion)
    {
        $latestVersion = $this->getLatestVersionNumber();

        return version_compare($currentVersion, trim($latestVersion, 'v'), '>=');
    }

    /**
     * Retrieve the latest version number of Valet.
     *
     * @return string
     * @throws \Httpful\Exception\ConnectionErrorException
     */
    function getLatestVersionNumber()
    {
        if ($this->latestVersion) return $this->latestVersion;

        $response = Request::get('https://api.github.com/repos/laravel/valet/releases/latest')->send();

        return $this->latestVersion = $response->body->tag_name;
    }

    /**
     * Diagnose latest Valet version issues
     * @param  string $version current version
     * @return string status response
     */
    function checkVersionDetails($version)
    {
        if (! $this->onLatestVersion($version)) {
            if ($this->latestVersion) {
                return '<error>A new version ' . $this->latestVersion . ' of Valet is available.</error>' . \PHP_EOL;
            }
            return '<comment>Unable to obtain the latest version details from Github.</comment>';
        }
        return '<info>You are running the latest version of Valet.</info>';
    }

    /**
     * Create the "sudoers.d" entry for running Valet.
     *
     * @return void
     */
    function createSudoersEntry()
    {
        $this->files->ensureDirExists('/etc/sudoers.d');

        $this->files->put('/etc/sudoers.d/valet', 'Cmnd_Alias VALET = /usr/local/bin/valet *
%admin ALL=(root) NOPASSWD:SETENV: VALET'.PHP_EOL);
    }

    /**
     * Remove the "sudoers.d" entry for running Valet.
     *
     * @return void
     */
    function removeSudoersEntry()
    {
        $this->files->unlink('/etc/sudoers.d/valet');
    }

    /**
     * Check the validity of the sudoers entries.
     * 
     * @return string
     */
    function checkSudoersSupport()
    {
        /**
         * Check that the sudoers.d directory is configured to be read (ie: older OS), else the Valet entry would serve no purpose
         */
        $contents = $this->files->get('/etc/sudoers'); // note: requires root permission to read
        if (false === $contents) {
            output('<comment>Warning: Could not read the /etc/sudoers to verify it is configured to read the sudoers.d files.' . \PHP_EOL . 'You may check the file manually by running `sudo cat /etc/sudoers`</comment>');
        } elseif (false === strpos($contents, '#includedir /private/etc/sudoers.d')) {
            output('<comment>sudoers.d directory is not marked as enabled, so sudoers support is not possible for Valet.</comment>');
        }

        /**
         * Check if there are any problems with the sudoers.d/valet file.
         */
        if($this->files->exists('/etc/sudoers.d/valet')) {
            $contents = $this->files->get('/etc/sudoers.d/valet');
            if (false === $contents || false === strpos($contents, 'admin ALL=(root) NOPASSWD:SETENV: VALET')) {
                output('<error>The sudoers.d/valet entry is not configured for root permissions. Run `valet trust` to add sudo support.</error>');
            } else {
                info('The sudoers.d/valet entry is present.');
            }
        } else {
            output('<comment>The sudoers.d/valet configuration is not present. This is fine, but you will have to type your password to run most Valet commands, unless you run `valet trust`.</comment>');
        }
    }

    /**
     * Run composer global diagnose
     */
    function composerGlobalDiagnose()
    {
        return $this->cli->runAsUser('composer global diagnose');
    }

    /**
     * Run composer global update
     */
    function composerGlobalUpdate()
    {
        $this->cli->runAsUser('composer global update');
    }
}
