<?php
/**
 * FaviconDownloader
 * Find favicon URL and download it easy
 * Requirements : PHP 5.2+ with curl extension
 * © 2014 www.finalclap.com
 * @copyright  2014 Vincent Paré
 * @author     Vincent Paré
 * @link       http://www.finalclap.com/
 * @version    1
 */

class FaviconDownloader
{
    public $url         = null; // (string) Page URL
    public $pageUrl     = null; // (string) Page URL, after prospective redirects
    public $siteUrl     = null; // (string) Site root URL (homepage), based on $pageUrl
    public $icoUrl      = null; // (string) full URI to favicon
    public $icoType     = null; // (string) favicon type (file extension, ex: ico|gif|png)
    public $findMethod  = null; // (string) favicon url determination method (default /favicon.ico or found in <head> <link/> tag)
    public $error       = null; // (string) details, in case of failure...
    public $icoExists   = null; // (bool)   tell if the favicon exists (set after calling downloadFavicon)
    public $icoMd5      = null; // (string) md5 of $icoData
    public $icoData     = null; // (binary) favicon binary data
    public $debugInfo   = null; // (array)  additionnal debug info
    public static $httpProxy = null; // (string) HTTP proxy (ex: localhost:8888)

    /**
     * Create a new FaviconDownloader object, search & download favicon if $auto is true
     */
    public function __construct($url = null, $auto = true)
    {
        if(!$url) return;
        $this->url = $url;
        if(!$auto) return;
        $this->getFaviconUrl();
        $this->downloadFavicon();
    }

    /**
     * Download page and search html to find favicon URL. Returns favicon URL.
     */
    public function getFaviconUrl()
    {
        // If already executed, don't need to search again
        if(!empty($this->icoUrl)){
            return $this->icoUrl;
        }
        // Check URL to search
        if(empty($this->url)){
            trigger_error('$this->url is empty', E_USER_WARNING);
            return false;
        }

        // Removing fragment (hash) from URL
        $url = $this->url;
        $urlInfo = parse_url($this->url);
        if(isset($urlInfo['fragment'])){
            $url = str_replace('#'.$urlInfo['fragment'], '', $url);
        }

        // Downloading the page
        $html = self::downloadAs($url, $info);
        if($info['curl_errno'] != 0){
            $this->error = $info['curl_error'];
            return false;
        }

        // Saving final URL (after prospective redirects) and get root URL
        $this->pageUrl = $info['effective_url'];
        $pageUrlInfo = parse_url($this->pageUrl);
        if(!empty($pageUrlInfo['scheme']) && !empty($pageUrlInfo['host'])){
            $this->siteUrl = $pageUrlInfo['scheme'].'://'.$pageUrlInfo['host'].'/';
        }

        // Default favicon URL
        $this->icoUrl = $this->siteUrl.'favicon.ico';
        $this->findMethod = 'default';

        // HTML <head> tag extraction
        preg_match('#^(.*)<\s*body#isU', $html, $matches);
        $htmlHead = isset($matches[1]) ? $matches[1] : $html;

        // HTML <base> tag href extraction
        $base_href = null;
        if(preg_match('#<base[^>]+href=(["\'])([^>]+)\1#i', $htmlHead, $matches)){
            $base_href = rtrim($matches[2],'/').'/';
            $this->debugInfo['base_href'] = &$base_href;
        }

        // HTML <link> icon tag analysis
        if(preg_match('#<\s*link[^>]*(rel=(["\'])[^>\2]*icon[^>\2]*\2)[^>]*>#i', $htmlHead, $matches)){
            $link_tag = $matches[0];
            $this->debugInfo['link_tag'] = &$link_tag;

            // HTML <link> icon tag href analysis
            if(preg_match('#href\s*=\s*(["\'])(.*?)\1#i', $link_tag, $matches)){
                $ico_href = trim($matches[2]);
                $this->debugInfo['ico_href'] = &$ico_href;
                $this->findMethod = 'head';

                // Building full absolute URL
                $urlType = self::urlType($ico_href);
                $this->findMethod .= ' '.$urlType;
                switch($urlType){
                    case 'absolue_full':
                        $this->icoUrl = $ico_href;
                        break;
                    case 'absolute_scheme':
                        $this->icoUrl = $pageUrlInfo['scheme'].':'.$ico_href;
                        break;
                    case 'absolute_path':
                        if(isset($base_href)){
                            $this->icoUrl = $base_href.$ico_href;
                            $this->findMethod .= ' with base href';
                        } else {
                            $this->icoUrl = rtrim($this->siteUrl, '/').'/'.ltrim($ico_href, '/');
                            $this->findMethod .= ' without base href';
                        }
                        break;
                    case 'relative':
                        $path = preg_replace('#/[^/]+?$#i', '/', $pageUrlInfo['path']);
                        if(isset($base_href)){
                            $this->icoUrl = $base_href.$ico_href;
                            $this->findMethod .= ' with base href';
                        } else {
                            $this->icoUrl = $pageUrlInfo['scheme'].'://'.$pageUrlInfo['host'].$path.$ico_href;
                            $this->findMethod .= ' without base href';
                        }
                        break;
                }
            }
        }

        $this->icoType = self::getExtension($this->icoUrl);
        return $this->icoUrl;
    }

    /**
     * Télécharge le favicon (et vérifie son existance au passage)
     */
    public function downloadFavicon()
    {
        // Check params
        if(empty($this->icoUrl)){
            return false;
        }

        // Prevent useless re-download
        if(!empty($this->icoData)){
            return false;
        }

        // Download favicon
        $content = self::downloadAs($this->icoUrl, $info);
        $this->debugInfo['favicon_download_metadata'] = $info;

        // Failover : if getting a 404 with favicon URL found in HTML source, trying with the default favicon URL
        if($content === false && $info['http_code'] == 404 && $this->findMethod != 'default' && !isset($this->debugInfo['failover'])){
            $this->icoUrl = $this->siteUrl.'favicon.ico';
            $this->findMethod = 'default';
            $this->icoType = self::getExtension($this->icoUrl);
            $this->debugInfo['failover'] = true;
            return $this->downloadFavicon();
        }

        // Download error
        if($content === false){
            $this->error = 'HTTP '.$info['http_code'];
            return false;
        }

        // Check favicon content
        if(strlen($content) == 0){
            $this->error = "Empty content";
            return false;
        }
        if(in_array($info['content_type'], array('text/html', 'text/plain')) || preg_match('#(</html>|</b>)#i', $content)){
            $this->error = "Seems to be HTML page";
            return false;
        }

        // All right baby !
        $this->icoData   = $content;
        $this->icoMd5    = md5($content);
        $this->icoExists = true;
        return true;
    }

    /**
     * Download URL as Firefox with cURL
     * Details available in $info if provided
     */
    public static function downloadAs($url, &$info = null)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Don't check SSL certificate to allow autosigned certificate
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);  // Follow redirects (302, 301)
        curl_setopt($ch, CURLOPT_MAXREDIRS, 20);         // Follow up to 20 redirects
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:27.0) Gecko/20100101 Firefox/27.0');
        if(!empty(self::$httpProxy)){
            curl_setopt($ch, CURLOPT_PROXY, self::$httpProxy); // Set HTTP proxy
        }
        $content = curl_exec($ch);
        $info['curl_errno'] = curl_errno($ch);
        $info['curl_error'] = curl_error($ch);
        $info['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $info['effective_url'] = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $info['redirect_count'] = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
        $info['content_type'] = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if($info['curl_errno'] !== 0 || in_array($info['http_code'], array(403, 404, 500, 503))){
            return false;
        }
        return $content;
    }

    /**
     * Return file extension from an URL or a file path
     */
    public static function getExtension($filename)
    {
        if(preg_match('#^(https?|ftp)#i', $filename)){
            $url = parse_url($filename);
            $filename = $url['path'];
        }

        $info = pathinfo($filename);
        return isset($info['extension']) ? $info['extension'] : "";
    }

    /**
     * Return URL type, either :
     * - absolute_full   ex: http://www.domain.com/images/fav.ico
     * - absolute_scheme ex: //www.domain.com/images/fav.ico
     * - absolute_path   ex: /images/fav.ico
     * - relative        ex: ../images/fav.ico
     */
    public static function urlType($url)
    {
        if(empty($url)) return false;
        $urlInfo = parse_url($url);
        if(!empty($urlInfo['scheme'])) return 'absolue_full';
        if(preg_match('#^//#i', $url)) return 'absolute_scheme';
        if(preg_match('#^/[^/]#i', $url)) return 'absolute_path';
        return 'relative';
    }

    /**
     * Show object printable properties, or return it if $return is true
     */
    public function debug($return = false)
    {
        $dump = clone $this;
        unset($dump->icoData);
        if($return) return $dump;
        print_r($dump);
    }
}
?>
