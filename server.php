<?php

/**
 * Define the user's "~/.valet" path.
 */

define('VALET_HOME_PATH', posix_getpwuid(fileowner(__FILE__))['dir'].'/.valet');
define('VALET_STATIC_PREFIX', '41c270e4-5535-4daa-b23e-c269744c2f45');

/**
 * Show the Valet 404 "Not Found" page.
 */
function show_valet_404()
{
    http_response_code(404);
    require __DIR__.'/cli/templates/404.html';
    exit;
}

/**
 * Wildcard DNS providers are supported. Usage is simple: run "valet link <your-local-ip>"
 * You may then reach your site by visiting http://your-local-ip.xip.io (or nip.io)
 * Add additional providers in the 'wildcard_providers' array in config.json
 */
function valet_support_wildcard_dns($requestDomain, $valetConfig = null)
{
    $wildcardProviders = array_merge(array('xip.io', 'nip.io'), isset($valetConfig['wildcard_providers']) ? $valetConfig['wildcard_providers'] : []);

    // return URL with provider TLD trimmed off
    $filteredDomain = array_reduce($wildcardProviders, function ($carry, $provider) use ($requestDomain) {
        $provider = trim($provider, '. ,');
        if (preg_match('~(.*)(' . preg_quote('.' . $provider, '~') . ')$~', $requestDomain, $matches)) {
            return $matches[1];
        }
        return $carry;
    });

    return $filteredDomain ?: $requestDomain;
}

/**
 * Load the Valet configuration.
 */
$valetConfig = json_decode(
    file_get_contents(VALET_HOME_PATH.'/config.json'), true
);

/**
 * Parse the URI and site / host for the incoming request.
 */
$uri = urldecode(
    explode("?", $_SERVER['REQUEST_URI'])[0]
);

$siteName = basename(
    // Filter host to support wildcard dns feature
    valet_support_wildcard_dns($_SERVER['HTTP_HOST'], $valetConfig),
    '.'.$valetConfig['domain']
);

if (strpos($siteName, 'www.') === 0) {
    $siteName = substr($siteName, 4);
}

/**
 * Determine the fully qualified path to the site.
 */
$valetSitePath = null;
$domain = array_slice(explode('.', $siteName), -1)[0];

foreach ($valetConfig['paths'] as $path) {
    if (is_dir($path.'/'.$siteName)) {
        $valetSitePath = $path.'/'.$siteName;
        break;
    }

    if (is_dir($path.'/'.$domain)) {
        $valetSitePath = $path.'/'.$domain;
        break;
    }
}

if (is_null($valetSitePath)) {
    show_valet_404();
}

$valetSitePath = realpath($valetSitePath);

/**
 * Find the appropriate Valet driver for the request.
 */
$valetDriver = null;

require __DIR__.'/cli/drivers/require.php';

$valetDriver = ValetDriver::assign($valetSitePath, $siteName, $uri);

if (! $valetDriver) {
    show_valet_404();
}

/**
 * Overwrite the HTTP host for Ngrok.
 */
if (isset($_SERVER['HTTP_X_ORIGINAL_HOST'])) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_X_ORIGINAL_HOST'];
}

/**
 * Allow driver to mutate incoming URL.
 */
$uri = $valetDriver->mutateUri($uri);

/**
 * Determine if the incoming request is for a static file.
 */
$isPhpFile = pathinfo($uri, PATHINFO_EXTENSION) === 'php';

if ($uri !== '/' && ! $isPhpFile && $staticFilePath = $valetDriver->isStaticFile($valetSitePath, $siteName, $uri)) {
    return $valetDriver->serveStaticFile($staticFilePath, $valetSitePath, $siteName, $uri);
}

/**
 * Attempt to dispatch to a front controller.
 */
$frontControllerPath = $valetDriver->frontControllerPath(
    $valetSitePath, $siteName, $uri
);

if (! $frontControllerPath) {
    show_valet_404();
}

chdir(dirname($frontControllerPath));

require $frontControllerPath;
