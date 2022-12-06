Hubzilla Photo Cache
====================


## Cache control variables

**system.photo_cache_minres**
Mininal image resolution for caching. 0 - if image was resized (default 1024), N - minimum size of any dimension in px.

**system.photo_cache_grid**
Enable image caching for the Grid. 0 - off (cache off Grid only), 1 - on.

**system.photo_cache_ownexp**
Do not respect external max-age / expiry directives. 0 - off, 1 - on (use own settings).

**system.photo_cache_time**
Max age in seconds. Default is 86400.

**system.photo_cache_leak**
Enable cached content for non local viewers. 0 - off (default, redirected to original location), 1 - on.
