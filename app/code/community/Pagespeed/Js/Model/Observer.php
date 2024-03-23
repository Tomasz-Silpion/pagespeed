<?php
/**
 * @package Pagespeed_Js
 * @copyright Copyright (c) 2015 mediarox UG (haftungsbeschraenkt) (http://www.mediarox.de)
 * @author Steven Fritzsche <sfritzsche@mediarox.de>
 * @author Thomas Uhlig <tuhlig@mediarox.de>
 */

use MatthiasMullie\Minify;

/**
 * Standard observer class
 */
class Pagespeed_Js_Model_Observer
{
    /**
     * @const string
     */
    const HTML_TAG_BODY = '</body>';

    /**
     * Regular expression to match single js tags.
     * @const string
     */
    const REGEXP_INLINEJS = '#<script\s?+(?![src=])(type="text/javascript"|(?![type=])).*>.*</script>*#isU';

    /**
     * Placeholder for lazy javascript.
     * @const string
     */
    const SCRIPT_LAZY_PLACEHOLDER = 'text/lazy-javascript';

    /**
     * All js resources to lazy load.
     * @var array
     */
    private $jsResources = [];

    /**
     * Contains all exclude regex patterns.
     * @var array
     */
    private $excludeList = [];

    /**
     * Contains all exclude actions regex patterns.
     * @var array
     */
    private $excludeActionList = [];

    /**
     * Processes <script src="%s" /> or the conditional js tag group.
     *
     * Step 1: Return if hit is blacklisted by exclude list.
     * Step 2: Add hit to js tag list and return empty string for the replacement.
     *
     * @param array $hits
     * @return string
     */
    public function processHit($hits)
    {
        // Step 1
        if ($this->isHitExcluded($hits[0])) return $hits[0];

        // Step 2
        $scriptContent = $hits[0];
        
        if (preg_match('/<script[^>]*src="([^"]+)"/', $scriptContent, $src)) {
            if ( Mage::helper('pagespeed_js')->isMinifyEnabled() ) {
                $this->jsResources[] = $this->minifyJs($scriptContent);
            } else {
                $this->jsResources[] = $src[1];
            }
            return '';
        }
    
        return $scriptContent;
    }

    /**
     * Processes the matched inline js tag or the conditional js tag group.
     *
     * Step 1: Return if hit is blacklisted by exclude list.
     * Step 2: Add hit to js tag list and return empty string for the replacement.
     *
     * @param array $hits
     * @return string
     */
    public function processInlineJs($hits)
    {
        // Step 1
        if ($this->isHitExcluded($hits[0])) return $hits[0];

        $scriptContent = $hits[0];
        $scriptContent = $this->replaceDomEvents($scriptContent);
        //Mage::log($scriptContent);
        if ($hits[1]) {
            return str_replace($hits[1], 'type="'. self::SCRIPT_LAZY_PLACEHOLDER .'"', $scriptContent);
        } else {
            return str_replace($hits[1] ?: '<script', '<script type="'. self::SCRIPT_LAZY_PLACEHOLDER .'"', $scriptContent);
        }
    }

    /**
     * Minify js
     *
     * @param string $scriptContent
     * @return string
     */
    public function minifyJs($scriptContent)
    {
        // esclude external js
        if (strpos($scriptContent, Mage::getBaseUrl()) === false) {
            return $scriptContent;
        }

        preg_match('/<script[^>]*src="([^"]+)"/', $scriptContent, $src);
        $src = $src[1];
        // exclude already minified js
        if(preg_match('/\.min\.js/', $src)) {
            return $src;
        }
        
        $srcPath = str_replace(Mage::getBaseUrl(), Mage::getBaseDir() . '/', $src);
        $pathOutput = str_replace(Mage::getBaseDir(), Mage::getBaseDir('media') . DS . 'jsmin', $srcPath);
        $pathOutput = str_replace(basename($pathOutput), basename($pathOutput, '.js') . '.min.js', $pathOutput);
        // recreate minified file if source file has been modified
        if (file_exists($pathOutput) && filemtime($pathOutput) < filemtime($srcPath)) {
            unlink($pathOutput);
        }
        if (!file_exists($pathOutput)) {                
            if (!file_exists(dirname($pathOutput))) {
                mkdir(dirname($pathOutput), 0777, true);
            }
            $srcContent = file_get_contents($srcPath);
            $srcContent = $this->replaceDomEvents($srcContent);
            $minifier = new Minify\JS( $srcContent );
            $minifier->minify($pathOutput);
        }

        // replace src with minified src
        $srcNew = str_replace(Mage::getBaseDir('media') .'/', Mage::getBaseUrl('media'), $pathOutput);
        $srcNew .= (strpos($srcNew, '?') !== false ? '&' : '?').sprintf('v=%s', filemtime($pathOutput));
        $scriptContent = str_replace($src, $srcNew, $scriptContent);
        return $srcNew;
    }

    /**
     * Move Js (head & inline) to the bottom. ({excluded_js}{stripped_html}{js}</body></html>)
     *
     * Step 1: Return if module is disabled.
     * Step 2: Load needed data.
     * Step 3: Return if no </body> is found in html.
     * Step 4: Search and replace conditional js units. (example: <!--[if lt IE 7]>{multiple js tags}<![endif]-->)
     * Step 5: Search and replace normal js tags.
     * Step 6: Return if no js is found.
     * Step 7: Remove blank lines from html.
     * Step 8: Recalculating </body> position, insert js groups right before body ends and set response.
     *  Final order:
     *      1. excluded js
     *      2. stripped html
     *      3. conditional js tags
     *      4. normal js tags
     *      5. </body></html>
     *
     * @param Varien_Event_Observer $observer
     */
    public function parseJsToBottom(Varien_Event_Observer $observer)
    {   
        if ($observer->getFront()->getRequest()->isXmlHttpRequest()) {
            return;
        }
        //$timeStart = microtime(true);

        // Step 1
        /** @var Pagespeed_Js_Helper_Data $helper */
        $helper = Mage::helper('pagespeed_js');
        if (!$helper->isEnabled()) return;

        $this->excludeList = $helper->getExcludeList();
        $this->excludeActionList = $helper->getExcludeActionList();
        if ($this->isActionExcluded(Mage::app()->getFrontController()->getAction()->getFullActionName())) {
            return;
        }

        // Step 2
        $response = $observer->getFront()->getResponse();
        $html = $response->getBody();

        if(!$html) return;        

        // Step 3
        $html = preg_replace_callback(
            '#\<\!--\[if[^\>]*>\s*<script.*(?![^>]*src=).*></script>\s*<\!\[endif\]-->#is',
            'self::processHit',
            $html
        );

        // Step 4
        $html = preg_replace_callback(
            '#<script.*</script>#isU',
            'self::processHit',
            $html
        );

        // Step 5
        $html = preg_replace_callback(
            self::REGEXP_INLINEJS,
            'self::processInlineJs',
            $html
        );

        // Step 6
        if (!$this->jsResources) return;

        // Step 7
        $html = preg_replace('/^\h*\v+/m', '', $html);
        
        // Step 8
        $closedBodyPosition = strripos($html, self::HTML_TAG_BODY);
        if (false === $closedBodyPosition) return;
        $html = substr_replace($html, $this->injectScriptLoader(), $closedBodyPosition, 0);
        
        // Step 9
        if ($helper->isMinifyHtmlEnabled()) {
            $html = $this->minifyHtml($html);
        }
        
        $response->setBody($html);

        //Mage::log(round(((microtime(true) - $timeStart) * 1000)) . ' ms taken to parse Js to bottom');
    }
    
    /**
     * Is hit on exclude list?
     *
     * @param string $hit
     * @return bool
     */
    protected function isHitExcluded($hit)
    {
        if (strpos($hit, 'defer') OR strpos($hit, 'async')) {
            return true;
        }
        $c = 0;
        preg_replace($this->excludeList, '', $hit, -1, $c);
        return ($c > 0);
    }

    /**
     * Is action excluded?
     *
     * @param string $hit
     * @return bool
     */
    protected function isActionExcluded($fullActionName)
    {
        $c = 0;
        preg_replace($this->excludeActionList, '', $fullActionName, -1, $c);
        return ($c > 0);
    }

    private function injectScriptLoader()
    {
        $this->jsResources = array_unique($this->jsResources);

        $jsChain = json_encode($this->jsResources);
        $scriptLoader = <<<JS_START_SCRIPT_LOADER

        function scriptLoader(){let e=(e,r)=>new Promise((t,i)=>{let n=window.document.createElement("script");for(let o in n.src=e,n.async=!1,n.crossOrigin="anonymous",r=r||{})n[o]=r[o];n.addEventListener("load",()=>{t(n)},!1),n.addEventListener("error",()=>{i(n),console.log("[ERROR] Loading Script: "+n.src)},!1),window.document.body.appendChild(n)});this.load=(r,t)=>(Array.isArray(r)||(r=[r]),Promise.all(r.map(r=>e(r,t)))),this.loadChain=function(e){let r=Array.isArray(arguments)?arguments:Array.prototype.slice.call(arguments),t=this.require(r.shift()),i=this;return r.length?t.then(()=>{i.requireChain(...r)}):t}}

        (events => {
            const dispatchUserInteractionEvent = () => {
                events.forEach(type => window.removeEventListener(type, dispatchUserInteractionEvent))
                window.dispatchEvent(new Event('init-external-scripts'))
            };
            events.forEach(type => window.addEventListener(type, dispatchUserInteractionEvent, {once: true, passive: true}))
        })(['touchstart', 'scroll', 'mousemove', 'click', 'mousewheel', 'keyup', 'wakeup']);
        
        window.addEventListener('init-external-scripts', () => {
          const loader = new scriptLoader();
            loader.load(
                {$jsChain}
            ).then(({length}) => {
JS_START_SCRIPT_LOADER;

        $_placeholder = self::SCRIPT_LAZY_PLACEHOLDER;
        $scriptLoader.= <<<JS_BODY_SCRIPT_LOADER
                // clone with text/javascript and remove all lazy javascript, then wait for all lazy javascript to be loaded
                let lazyJs = document.querySelectorAll('script[type="{$_placeholder}"]');
                let lazyJsClone = [];
                let i = 0;
                function loadScript(i) {
                    new Promise(function (resolve, reject) {
                        lazyJsClone[i] = lazyJs[i].cloneNode(true);
                        lazyJsClone[i].type = 'text/javascript';
                        lazyJsClone[i].onload = resolve(lazyJsClone[i]);
                        lazyJsClone[i].onerror = reject(lazyJsClone[i]);
                        lazyJs[i].parentNode.appendChild(lazyJsClone[i]);
                        lazyJs[i].parentNode.removeChild(lazyJs[i]);
                    }).then(function (script) {
                        window.dispatchEvent(new Event('load'));
                        window.dispatchEvent(new Event('onload'));
                        //console.log('lazyJsClone[i]', script);
                        i++;
                        if (i < lazyJs.length) {
                            window.dispatchEvent(new Event('onunload'));
                            window.dispatchEvent(new Event('unload'));
                            loadScript(i);
                        } else {
                            {$this->getDipatchEventsJs()}
                        }
                    }).catch(function (error) {
                        console.log('lazyJsClone[i]', error);
                    });
                }
                loadScript(i);
            });
JS_BODY_SCRIPT_LOADER;

        $scriptLoader.= <<<JS_END_SCRIPT_LOADER
        }, {once: true, passive: true});
JS_END_SCRIPT_LOADER;        
        
        if ( Mage::helper('pagespeed_js')->isMinifyEnabled() ) {
            $minifier = new Minify\JS( $scriptLoader );
            $scriptLoader = $minifier->minify();
        }
        $scriptLoader = '<script defer="true">'.$scriptLoader.'</script>';
        return $scriptLoader;
    }

    private function replaceDomEvents($scriptContent)
    {
        // replace all window.addEventListener("DomContentLoad") e document.addEventListener("DomContentLoad") with window.addEventListener("DomContentLoad2")
        $scriptContent = preg_replace('/((window|document)\.addEventListener\([\'"])DomContentLoad/', 'window.addEventListener("DomContentLoad2', $scriptContent);
        
        // replace  document.observe("dom:loaded", function() { with window.addEventListener("DomContentLoad2", function() {
        $scriptContent = preg_replace('/(document|window)\.observe\s?\(([\'"])(dom:loaded)([\'"])\s?,\s?function/', 'window.addEventListener("DomContentLoad2", function', $scriptContent);

        // replace $(document).ready(function() with window.addEventListener("DomContentLoad2", function()
        $scriptContent = preg_replace('/\$\((document)\)\.ready\s?\(\s?function/', 'window.addEventListener("load2", function', $scriptContent);
        
        // replace $j(document).ready(function() with window.addEventListener("DomContentLoad2", function()
        $scriptContent = preg_replace('/\$j\((document)\)\.ready\s?\(\s?function/', 'window.addEventListener("load2", function', $scriptContent);

        //replace window.addEventListener('init-external-scripts', () => { with window.addEventListener('init-external-scripts2', () => {
        $scriptContent = preg_replace('/window\.addEventListener\(([\'"])init-external-scripts([\'"])/', 'window.addEventListener("init-external-scripts2"', $scriptContent);

        return $scriptContent;
    }

    private function getDipatchEventsJs()
    {
        return <<<JS
        //window.dispatchEvent(new Event('onunload'));
        //window.dispatchEvent(new Event('unload'));
        document.fire('dom:loaded');
        window.dispatchEvent(new Event('DomContentLoad2'));
        window.dispatchEvent(new Event('load2'));
        window.dispatchEvent(new Event('init-external-scripts2'));
        window.dispatchEvent(new Event('init-inlinejs'));
        JS;
    }

    protected function minifyHtml($html)
    {       
        //remove redundant (white-space) characters
        $replace = array(
            //remove tabs before and after HTML tags
            '/\>[^\S ]+/s'   => '>',
            '/[^\S ]+\</s'   => '<',
            //shorten multiple whitespace sequences; keep new-line characters because they matter in JS!!!
            '/([\t ])+/s'  => ' ',
            //remove leading and trailing spaces
            '/^([\t ])+/m' => '',
            '/([\t ])+$/m' => '',
            // remove JS line comments (simple only); do NOT remove lines containing URL (e.g. 'src="http://server.com/"')!!!
            '~//[a-zA-Z0-9 ]+$~m' => '',
            //remove empty lines (sequence of line-end and white-space characters)
            '/[\r\n]+([\t ]?[\r\n]+)+/s'  => "\n",
            //remove empty lines (between HTML tags); cannot remove just any line-end characters because in inline JS they can matter!
            '/\>[\r\n\t ]+\</s'    => '><',
            //remove "empty" lines containing only JS's block end character; join with next line (e.g. "}\n}\n</script>" --> "}}</script>"
            '/}[\r\n\t ]+/s'  => '}',
            '/}[\r\n\t ]+,[\r\n\t ]+/s'  => '},',
            //remove new-line after JS's function or condition start; join with next line
            '/\)[\r\n\t ]?{[\r\n\t ]+/s'  => '){',
            '/,[\r\n\t ]?{[\r\n\t ]+/s'  => ',{',
            //remove new-line after JS's line end (only most obvious and safe cases)
            '/\),[\r\n\t ]+/s'  => '),',
            //remove quotes from HTML attributes that does not contain spaces; keep quotes around URLs!
            '~([\r\n\t ])?([a-zA-Z0-9]+)="([a-zA-Z0-9_/\\-]+)"([\r\n\t ])?~s' => '$1$2=$3$4', //$1 and $4 insert first white-space character found before/after attribute
        );
        $html = preg_replace(array_keys($replace), array_values($replace), $html);
        
        //remove optional ending tags (see http://www.w3.org/TR/html5/syntax.html#syntax-tag-omission )
        $remove = array(
            '</option>', '</li>', '</dt>', '</dd>', '</tr>', '</th>', '</td>'
        );
        $html = str_ireplace($remove, '', $html);
    
        return $html;
    }
}