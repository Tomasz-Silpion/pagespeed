<?php
/**
 * @package Pagespeed_Css
 * @copyright Copyright (c) 2015 mediarox UG (haftungsbeschraenkt) (http://www.mediarox.de)
 * @author Steven Fritzsche <sfritzsche@mediarox.de>
 * @author Thomas Uhlig <tuhlig@mediarox.de>
 */

 use MatthiasMullie\Minify;

/**
 * Standard observer class
 */
class Pagespeed_Css_Model_Observer
{
    /**
     * @const string
     */
    const HTML_TAG_BODY = '</body>';

    /**
     * @const string
     */
    const HTML_TAG_HEAD = '</head>';

    const XPATH_CRITICAL_IMAGES = [
        // explicit critical images with fetchpriority high
        '//img[@fetchpriority="high"]',
        // product view default main image
        './/*[contains(concat(" ",normalize-space(@class)," ")," catalog-product-view ")]//img[@id="image-main"]',
        // category view default image
        './/*[contains(concat(" ",normalize-space(@class)," ")," category-image ")]/img',
    ];

    /**
     * Will finally contain all css tags to move.
     * @var string
     */
    private $cssTags = '';

    /**
     * Contains all exclude regex patterns.
     * @var array
     */
    private $excludeList = array();

    /**
     * Processes the matched single css tag or the conditional css tag group.
     *
     * Step 1: Return if hit is blacklisted by exclude list.
     * Step 2: Add hit to css tag list and return empty string for the replacement.
     *
     * @param array $hits
     * @return string
     */
    public function processHit($hits)
    {
        // Step 1
        if ($this->isHitExcluded($hits[0])) return $hits[0];

        $hits[0] = $this->minifyCss($hits[0]);

        // Step 2
        $this->cssTags .= $hits[0];
        return '';
    }

    
    /**
     * Minify js
     *
     * @param string $scriptContent
     * @return string
     */
    public function minifyCss($cssContent)
    {
        // esclude external js
        if (strpos($cssContent, Mage::getBaseUrl()) === false) {
            return $cssContent;
        }

        if (!preg_match('/href="([^"]+)"/', $cssContent, $href)) {
            return $cssContent;
        }

        // exclude already minified js
        if(preg_match('/\.min\.css/', $cssContent)) {
            return $cssContent;
        }
        $href = $href[1];
        $hrefPath = str_replace(Mage::getBaseUrl(), Mage::getBaseDir() . '/', $href);
        $pathOutput = str_replace(Mage::getBaseDir(), Mage::getBaseDir('media') . DS . 'cssmin', $hrefPath);
        $pathOutput = str_replace(basename($pathOutput), basename($pathOutput, '.css') . '.min.css', $pathOutput);
        // recreate minified file if source file has been modified
        if (file_exists($pathOutput) && filemtime($pathOutput) < filemtime($hrefPath)) { 
            unlink($pathOutput);
        }
        if (!file_exists($pathOutput)) {                
            if (!file_exists(dirname($pathOutput))) {
                mkdir(dirname($pathOutput), 0777, true);
            }

            $_cssContent = file_get_contents($hrefPath);
            // replace relative paths ../ to absolute paths
            $href = str_replace('css/', '', $href);
            $_cssContent = preg_replace_callback('/url\((\'|")?(\.\.\/)?(.*?)(\'|")?\)/', function($matches) use ($href) {
                $path = $matches[3];
                if (strpos($path, 'http') === false) {
                    $path = dirname($href) . '/' . $path;
                }
                return sprintf('url(%s)', $path);
            }, $_cssContent);
            
            $minifier = new Minify\CSS($_cssContent);
            $minifier->minify($pathOutput);
        }

        // replace href with minified href
        $hrefNew = str_replace(Mage::getBaseDir('media') .'/', Mage::getBaseUrl('media'), $pathOutput);
        $hrefNew .= (strpos($hrefNew, '?') !== false ? '&' : '?').sprintf('v=%s', filemtime($pathOutput));
        $cssContent = str_replace($href, $hrefNew, $cssContent);

        
        /* if(preg_match('@media=("|\')(.*?)\1@', $cssContent, $mediaAttribute)) {
            $cssContent = sprintf(
                '<link rel="stylesheet" media="print" onload="this.onload=null;this.media=\'%s\'" href="%s" />',
                $mediaAttribute[2] ?? 'all',
                $hrefNew
            );
        } */

        return $cssContent;
    }

    /**
     * Is hit on exclude list?
     *
     * @param string $hit
     * @return bool
     */
    protected function isHitExcluded($hit)
    {
        $c = 0;
        preg_replace($this->excludeList, '', $hit, -1, $c);
        return ($c > 0);
    }

    /**
     * Move Css (head & inline) to the bottom. ({excluded_css}{stripped_html}{css}</body></html>)
     *
     * Step 1: Return if module is disabled.
     * Step 2: Load needed data.
     * Step 3: Return if no </body> is found in html.
     * Step 4: Search and replace conditional css units. (example: <!--[if lt IE 7]>{multiple css tags}<![endif]-->)
     * Step 5: Search and replace external css tags. (link-tags must xhtml-compliant closed by "/>")
     * Step 6: Search and replace inline css tags.
     * Step 7: Return if no css is found.
     * Step 8: Remove blank lines from html.
     * Step 9: Recalculating </body> position, insert css groups right before body ends and set response.
     *  Final order:
     *      1. excluded css
     *      2. stripped html
     *      3. conditional css tags
     *      4. external css tags
     *      5. inline css tags
     *      6. </body></html>
     *
     * @param Varien_Event_Observer $observer
     */
    public function parseCssToBottom(Varien_Event_Observer $observer)
    {
        if ($observer->getFront()->getRequest()->isXmlHttpRequest()) {
            return;
        }
        //$timeStart = microtime(true);

        // Step 1
        /** @var Pagespeed_Css_Helper_Data $helper */
        $helper = Mage::helper('pagespeed_css');
        if (!$helper->isEnabled()) return;

        // Step 2
        $response = $observer->getFront()->getResponse();
        $html = $response->getBody();
        $this->excludeList = $helper->getExcludeList();

        
        // link preload product image
        $_criticalImages = $this->getCriticalImages($html);
        foreach ($_criticalImages as $src) {
            $observer->getFront()->getResponse()->setHeader('Early-Hints', 'true');
            $observer->getFront()->getResponse()->setHeader('X-Accel-Buffering', 'no'); // for nginx no buffering
            $observer->getFront()->getResponse()->setHeader('Link', sprintf('<%s>; Content-type rel=preload; as=image', $src));

            $src = sprintf('<link rel="preload" as="image" href="%s" />', $src);
            $html = str_replace(self::HTML_TAG_HEAD, "\n".$src . self::HTML_TAG_HEAD, $html);
        }

        // Step 3
        $closedBodyPosition = strripos($html, self::HTML_TAG_HEAD);
        if (false === $closedBodyPosition) return;

        // Step 4
        $html = preg_replace_callback(
            '#\<\!--\[if[^\>]*>\s*<link[^>]*type\="text\/css"[^>]*/>\s*<\!\[endif\]-->#isU',
            'self::processHit',
            $html
        );

        // Step 5
        $html = preg_replace_callback(
            '#<link[^>]*type\=["\']text\/css["\'][^>]*/>#isU',
            'self::processHit',
            $html
        );

        // Step 6
        $html = preg_replace_callback(
            '#<link[^>]*rel\=["\']stylesheet["\'][^>]*>#isU',
            'self::processHit',
            $html
        );

        // Step 7
        $html = preg_replace_callback(
            '#<style.*</style>#isUm',
            'self::processHit',
            $html
        );

        // Step 8
        if (!$this->cssTags) return;

        // Step 9
        $html = preg_replace('/^\h*\v+/m', '', $html);

        // Step 10
        $closedBodyPosition = strripos($html, self::HTML_TAG_HEAD);
        $html = substr_replace($html, $this->cssTags, $closedBodyPosition, 0);
        $response->setBody($html);

        //Mage::log(round(((microtime(true) - $timeStart) * 1000)) . ' ms taken to parse Css to bottom');
    }

    private function getCriticalImages($html)
    {
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query(implode('|', self::XPATH_CRITICAL_IMAGES));
        $images = [];
        foreach ($nodes as $node) {
            $images[] = $node->getAttribute('src');
        }
        return array_unique($images);
    }
}