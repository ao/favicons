# favicons
Favicons for all!

## How to use
It's really easy to use this!

You'll need PHP (5.6+ or 7 is recommended)

By default favicon images are stored in 'cache' directory, but you can change this easily by adjusting the constant at the top of the `index.php` file.

It's currently set to the same root directory as this code:
```
$_CACHE_PATH = "cache";
```

But you can change it to write to somewhere else, e.g.:
```
$_CACHE_PATH = "../favicon_cache";    // one directory up
```

Make sure that the cache directory is writable by the web application user, or whoever triggers the execution of the PHP script.

## What to call
http://wherever_you_have_hosted_this.com/favicons/?url=https://www.youtube.com
