<?php

namespace Valet;

use DomainException;

class Nginx
{
    var $brew;
    var $cli;
    var $files;
    var $configuration;
    var $site;
    const NGINX_CONF = '/usr/local/etc/nginx/nginx.conf';

    /**
     * Create a new Nginx instance.
     *
     * @param  Brew  $brew
     * @param  CommandLine  $cli
     * @param  Filesystem  $files
     * @param  Configuration  $configuration
     * @param  Site  $site
     * @return void
     */
    function __construct(Brew $brew, CommandLine $cli, Filesystem $files,
                         Configuration $configuration, Site $site)
    {
        $this->cli = $cli;
        $this->brew = $brew;
        $this->site = $site;
        $this->files = $files;
        $this->configuration = $configuration;
    }

    /**
     * Install the configuration files for Nginx.
     *
     * @return void
     */
    function install()
    {
        if (!$this->brew->hasInstalledNginx()) {
            $this->brew->installOrFail('nginx', []);
        }

        $this->installConfiguration();
        $this->installServer();
        $this->installNginxDirectory();
    }

    /**
     * Install the Nginx configuration file.
     *
     * @return void
     */
    function installConfiguration()
    {
        info('Installing nginx configuration...');

        $contents = $this->files->get(__DIR__.'/../stubs/nginx.conf');

        $this->files->putAsUser(
            static::NGINX_CONF,
            str_replace(['VALET_USER', 'VALET_HOME_PATH'], [user(), VALET_HOME_PATH], $contents)
        );
    }

    /**
     * Install the Valet Nginx server configuration file.
     *
     * @return void
     */
    function installServer()
    {
        $this->files->ensureDirExists('/usr/local/etc/nginx/valet');

        $this->files->putAsUser(
            '/usr/local/etc/nginx/valet/valet.conf',
            str_replace(
                ['VALET_HOME_PATH', 'VALET_SERVER_PATH', 'VALET_STATIC_PREFIX'],
                [VALET_HOME_PATH, VALET_SERVER_PATH, VALET_STATIC_PREFIX],
                $this->files->get(__DIR__.'/../stubs/valet.conf')
            )
        );

        $this->files->putAsUser(
            '/usr/local/etc/nginx/fastcgi_params',
            $this->files->get(__DIR__.'/../stubs/fastcgi_params')
        );
    }

    /**
     * Install the Nginx configuration directory to the ~/.config/valet directory.
     *
     * This directory contains all site-specific Nginx servers.
     *
     * @return void
     */
    function installNginxDirectory()
    {
        info('Installing nginx directory...');

        if (! $this->files->isDir($nginxDirectory = VALET_HOME_PATH.'/Nginx')) {
            $this->files->mkdirAsUser($nginxDirectory);
        }

        $this->files->putAsUser($nginxDirectory.'/.keep', "\n");

        $this->rewriteSecureNginxFiles();
    }

    /**
     * Check nginx.conf for errors.
     */
    private function lint()
    {
        $this->cli->run(
            'sudo nginx -c '.static::NGINX_CONF.' -t',
            function ($exitCode, $outputMessage) {
                throw new DomainException("Nginx cannot start; please check your nginx.conf [$exitCode: $outputMessage].");
            }
        );
    }

    /**
     * Generate fresh Nginx servers for existing secure sites.
     *
     * @return void
     */
    function rewriteSecureNginxFiles()
    {
        $tld = $this->configuration->read()['tld'];

        $this->site->resecureForNewTld($tld, $tld);
    }

    /**
     * Restart the Nginx service after checking the config files for errors.
     *
     * @return void
     */
    function restart()
    {
        $this->lint();

        $this->brew->restartService($this->brew->nginxServiceName());
    }

    /**
     * Stop the Nginx service.
     *
     * @return void
     */
    function stop()
    {
        info('Stopping nginx...');

        $this->cli->quietly('sudo brew services stop '. $this->brew->nginxServiceName());
    }

    /**
     * Forcefully uninstall Nginx.
     *
     * @return void
     */
    function uninstall()
    {
        $this->brew->stopService(['nginx', 'nginx-full']);
        $this->brew->uninstallFormula('nginx nginx-full');
        $this->cli->quietly('rm -rf /usr/local/etc/nginx /usr/local/var/log/nginx');
    }

    /**
     * Check and optionally repair Valet's Nginx config.
     * @param  boolean $repair
     */
    function checkConfiguration($repair = false)
    {
        $this->brew->hasInstalledNginx();
        $this->files->exists('/usr/local/etc/nginx/valet/valet.conf');
        $this->files->exists('/usr/local/etc/nginx/fastcgi_params');
        $this->files->exists(static::NGINX_CONF);
        $this->lint();

        // @TODO - Maybe: report what services are listening on ports 80 and 443? ie: what could be conflicting with nginx to prevent it responding
        // @TODO - Maybe: report on any .conf files that don't contain the necessary php-fpm socket path (and aren't proxies)?
    }
}
