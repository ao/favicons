<?php
error_reporting(0);

// Change the location where images are stored/retrieved
//$_CACHE_PATH = "../favicon_cache";    // one directory up
$_CACHE_PATH = "cache";                 // current directory

if (!isset($_GET['url'])) die();
	
if (substr( $_GET['url'], 0, 4 ) !== "http") {
    $_GET['url'] = "http://".$_GET['url'];
}

$parse = parse_url($_GET['url']);
$domain = $parse['host'];

if (isset($_GET['refresh'])) {
    @unlink('../'+$_CACHE_PATH+'/'.$domain);
}


if (isset($_GET['debug'])) {
    require 'FaviconDownloader.php';
    $_favicon = new FaviconDownloader($_GET['url']);
    $_favicon->debug();
    die();
}


if (file_exists($_CACHE_PATH+'/'.$domain)) {
    //show cached copy first!
    header('Content-Type: image/png');
    echo file_get_contents($_CACHE_PATH+'/'.$domain);
    die();
}


require 'FaviconDownloader.php';

$favicon = new FaviconDownloader($_GET['url']);



if($favicon->icoExists){
    if (!file_exists($_CACHE_PATH+'/'.$domain)) {
        file_put_contents($_CACHE_PATH+'/'.$domain, $favicon->icoData);
    }

    header('Content-Type: image/png');
    echo file_get_contents($_CACHE_PATH+'/'.$domain);
} else {
    header('Content-Type: image/png');
    echo file_get_contents('default.png');
}
