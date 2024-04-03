<?php
/**
 * @package Pagespeed_Js
 * @copyright Copyright (c) 2015 mediarox UG (haftungsbeschraenkt) (http://www.mediarox.de)
 * @author Steven Fritzsche <sfritzsche@mediarox.de>
 * @author Thomas Uhlig <tuhlig@mediarox.de>
 */

/**
 * Standard helper
 */
class Pagespeed_Js_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * Configuration paths
     */
    const PAGESPEED_JS_ENABLED = 'pagespeed/js/enabled';
    const PAGESPEED_JS_ONLY_ON_USER_INTERACTION = 'pagespeed/js/only_on_user_interaction';
    const PAGESPEED_JS_EXCLUDE_ENABLED = 'pagespeed/js/exclude_enabled';
    const PAGESPEED_JS_EXCLUDE = 'pagespeed/js/exclude';
    const PAGESPEED_JS_EXCLUDE_ACTIONS = 'pagespeed/js/exclude_actions';
    const PAGESPEED_JS_MINIFY = 'pagespeed/js/minify';
    const PAGESPEED_JS_MINIFY_HTML = 'pagespeed/js/minify_html';

    /**
     * Is js module enabled ?
     *
     * @return bool
     */
    public function isEnabled()
    {
        return Mage::getStoreConfigFlag(self::PAGESPEED_JS_ENABLED);
    }

    /**
     * Init only on user interaction?
     *
     * @return bool
     */
    public function initOnlyOnUserInteraction()
    {
        return Mage::getStoreConfigFlag(self::PAGESPEED_JS_ONLY_ON_USER_INTERACTION);
    }

    /**
     * Is exclude list enabled ?
     *
     * @return bool
     */
    public function isExcludeEnabled()
    {
        return Mage::getStoreConfigFlag(self::PAGESPEED_JS_EXCLUDE_ENABLED);
    }

    /**
     * Is minify js enabled ?
     *
     * @return bool
     */
    public function isMinifyEnabled()
    {
        return Mage::getStoreConfigFlag(self::PAGESPEED_JS_MINIFY);
    }

    /**
     * Is minify html enabled ?
     *
     * @return bool
     */
    public function isMinifyHtmlEnabled()
    {
        return Mage::getStoreConfigFlag(self::PAGESPEED_JS_MINIFY_HTML);
    }

    /**
     * Retrieve js configuration exclude list
     *
     * @return array of regex patterns
     */
    public function getExcludeList()
    {
        $result = array();
        if ($this->isExcludeEnabled()) {
            $exclude = Mage::getStoreConfig(self::PAGESPEED_JS_EXCLUDE);
            $exclude = explode(PHP_EOL, $exclude);
            foreach ($exclude as $item) {
                if ($item = trim($item)) {
                    $result[] = $item;
                }
            }
        }
        return $result;
    }

    /**
     * Retrieve exclude actions from configuration
     *
     * @return array of regex patterns
     */
    public function getExcludeActionList()
    {
        $result = array();
        $exclude = Mage::getStoreConfig(self::PAGESPEED_JS_EXCLUDE_ACTIONS);
        $exclude = explode(PHP_EOL, $exclude);
        foreach ($exclude as $item) {
            if ($item = trim($item)) {
                $result[] = $item;
            }
        }
        return $result;
    }
}