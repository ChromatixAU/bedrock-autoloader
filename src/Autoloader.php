<?php

namespace Roots\Bedrock;

/**
 * Class Autoloader
 * @package Roots\Bedrock
 * @author Roots
 * @link https://roots.io/
 */
class Autoloader
{
    /** @var static Array of blacklisted plugins */
    private static $blacklistedPlugins = [
        'wpengine-common/dify-widget.php'
    ];

    /** @var static Array of blacklisted plugin folders */
    private static $blacklistedFolders = [
        'wpe-cache-plugin',
        'wpe-elasticpress-autosuggest-logger',
        'wpe-wp-sign-on-plugin',
        'wpengine-common',
    ];

    /** @var static Singleton instance */
    private static $instance;

    /** @var array Store Autoloader cache and site option */
    private $cache;

    /** @var array Autoloaded plugins */
    private $autoPlugins;

    /** @var array Autoloaded mu-plugins */
    private $muPlugins;

    /** @var int Number of plugins */
    private $count;

    /** @var array Newly activated plugins */
    private $activated;

    /** @var string Relative path to the mu-plugins dir */
    private $relativePath;

    /**
     * Create singleton, populate vars, and set WordPress hooks
     */
    public function __construct()
    {
        if (isset(self::$instance)) {
            return;
        }

        self::$instance = $this;

        $this->relativePath = '/../' . basename(WPMU_PLUGIN_DIR);

        if (is_admin()) {
            add_filter('show_advanced_plugins', [$this, 'showInAdmin'], 0, 2);
        }

        $this->loadPlugins();
    }

   /**
    * Run some checks then autoload our plugins.
    */
    public function loadPlugins()
    {
        $this->checkCache();
        $this->validatePlugins();
        $this->countPlugins();

        array_map(static function () {
            include_once WPMU_PLUGIN_DIR . '/' . func_get_args()[0];
        }, array_keys($this->cache['plugins']));

        add_action('plugins_loaded', [$this, 'pluginHooks'], -9999);
    }

    /**
     * Filter show_advanced_plugins to display the autoloaded plugins.
     * @param $show bool Whether to show the advanced plugins for the specified plugin type.
     * @param $type string The plugin type, i.e., `mustuse` or `dropins`
     * @return bool We return `false` to prevent WordPress from overriding our work
     * {@internal We add the plugin details ourselves, so we return false to disable the filter.}
     */
    public function showInAdmin($show, $type)
    {
        $screen = get_current_screen();
        $current = is_multisite() ? 'plugins-network' : 'plugins';

        if ($screen->base !== $current || $type !== 'mustuse' || !current_user_can('activate_plugins')) {
            return $show;
        }

        $this->updateCache();

        $this->autoPlugins = array_map(function ($auto_plugin) {
            $auto_plugin['Name'] .= ' *';
            return $auto_plugin;
        }, $this->autoPlugins);

        $GLOBALS['plugins']['mustuse'] = array_unique(array_merge($this->autoPlugins, $this->muPlugins), SORT_REGULAR);

        return false;
    }

    /**
     * This sets the cache or calls for an update
     */
    private function checkCache()
    {
        $cache = get_site_option('bedrock_autoloader');

        if ($cache === false || (isset($cache['plugins'], $cache['count']) && count($cache['plugins']) !== $cache['count'])) {
            $this->updateCache();
            return;
        }

        $this->cache = $cache;
    }

    /**
     * Get the plugins and mu-plugins from the mu-plugin path and remove duplicates.
     * Check cache against current plugins for newly activated plugins.
     * After that, we can update the cache.
     */
    private function updateCache()
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $this->autoPlugins = get_plugins($this->relativePath);
        $this->muPlugins   = get_mu_plugins();
        $plugins           = array_diff_key($this->autoPlugins, $this->muPlugins);
        // Exclude blacklisted plugins.
        $filteredPlugins   = array_filter($plugins, fn($k) => !in_array($k, self::$blacklistedPlugins), ARRAY_FILTER_USE_KEY);
        $rebuild           = !isset($this->cache['plugins']);
        $this->activated   = $rebuild ? $filteredPlugins : array_diff_key($filteredPlugins, $this->cache['plugins']);
        $this->cache       = ['plugins' => $filteredPlugins, 'count' => $this->countPlugins()];

        update_site_option('bedrock_autoloader', $this->cache);
    }

    /**
     * This accounts for the plugin hooks that would run if the plugins were
     * loaded as usual. Plugins are removed by deletion, so there's no way
     * to deactivate or uninstall.
     */
    public function pluginHooks()
    {
        if (!is_array($this->activated)) {
            return;
        }

        foreach ($this->activated as $plugin_file => $plugin_info) {
            do_action('activate_' . $plugin_file);
        }
    }

    /**
     * Check that the plugin file exists, if it doesn't update the cache.
     */
    private function validatePlugins()
    {
        foreach ($this->cache['plugins'] as $plugin_file => $plugin_info) {
            if (!file_exists(WPMU_PLUGIN_DIR . '/' . $plugin_file)) {
                $this->updateCache();
                break;
            }
        }
    }

    /**
     * Count the number of autoloaded plugins.
     *
     * Count our plugins (but only once) by counting the top level folders in the
     * mu-plugins dir. If it's more or less than last time, update the cache.
     *
     * @return int Number of autoloaded plugins.
     */
    private function countPlugins()
    {
        if (isset($this->count)) {
            return $this->count;
        }

        // Exclude blacklisted folders.
        $folders = glob(WPMU_PLUGIN_DIR . '/*/', GLOB_ONLYDIR | GLOB_NOSORT);
        $filteredFolders = array_filter($folders, fn($k) => !in_array(basename($k), self::$blacklistedFolders));
        $count = count($filteredFolders);

        if (!isset($this->cache['count']) || $count !== $this->cache['count']) {
            $this->count = $count;
            $this->updateCache();
        }

        return $this->count;
    }
}
