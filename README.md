# TumblrPoster

Enables batch upload of photos on a [Tumblr](http://www.tumblr.com/) blog using the [official API](http://www.tumblr.com/api/).

For more information use the source, Luke!.


#### Important note

In order for the script to work you need to register an app on the [Tumblr API](http://www.tumblr.com/oauth/apps) (it only takes seconds) and then replace the values in the script.

	define("CONSUMER_KEY",    "xxxxxx");
	define("CONSUMER_SECRET", "xxxxxx");
	define("OAUTH_TOKEN",     "xxxxxx");
	define("OAUTH_SECRET",    "xxxxxx");

Also change the name of your blog:

	define("BLOG_NAME",       "brainmess");
	
Change `brainmess` with the name of your blog (`xxxxxx`.tumblr.com).

## Usage

### Basic

Place photos in the `photos` directory then run the following command in a terminal:

	php tumblrpost.php

### Advanced
Several options are available, for detailed help and usage examples run the following command:

	php tumblrpost.php -h (or --help)
	
Output:

	Usage: php ./tumblrpost.php

	-q (or --queued)	*optional* a flag to tell the script to put the post in queue (default is 'published')
	-h (or --help)		prints this help

	Examples:

	php ./tumblrpost.php		(post every photo available)
	php ./tumblrpost.php --queued	(post every photo in the blog queue)

	Notes:

	- short and long options can be used interchangeably (if available)
	- do not specify an option more than once (unexpected behavior might occur)
	- photos are taken from a 'photos' directory where the script resides


## License

See the `LICENSE` file.


## Contributing

You know the drill:

1. Fork
2. Modify
3. Pull request
