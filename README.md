# Composer Repository Mirror

## Setup

### Scripted Setup

- See https://github.com/peter279k/mirror-kickstarter if you are looking for automated scripts dealing with the setup of mirrors.

### Manual Setup

- `git clone https://github.com/composer/mirror mirror`
- `cd mirror`
- `composer install`
- `cp mirror.config.php.dist mirror.config.php`
- Edit `mirror.config.php` to fit your needs, and mind the TODO entries which MUST be filled-in.
- Run it using supervisord or similar, it is made to shutdown regularly to avoid leaks or getting stuck for any reason. There are 3 scripts you should run:
    - `./mirror.php --v1` should be run permanently to sync Composer 1 metadata (if you do not need Composer 1 metadata, you don't have to run this, but then you must set `has_v1_mirror` to `false` in the config)
    - `./mirror.php --v2` should be run permanently to sync Composer 2 metadata
    - `./mirror.php --gc` should be run once an hour or so with a cron job to clean up old v1 files (if you do not need Composer 1 metadata, you don't need to run this)

## Debugging and force-resync of v2 metadata

In case the v2 metadata gets very outdated because you did not update the mirror for a while, this will be detected
and a resync will happen automatically.

However, if you want to run a resync manually to see what is going on you can use:

`./mirror.php --resync -v`

This will make sure the v2 metadata is in sync again (wait for the script to complete which may take a while) and
then running `./mirror.php --v2` regularly again should get you back on regular updates.

## Requirements

- PHP 7.3+
- A web server configured:
  - to send Last-Modified headers, and respond correctly to `If-Modified-Since` requests with `304 Not Modified`
  - to allow HTTP/2 if possible as HTTP/1 performance will be much reduced
  - to respond correctly with 404s for missing files

## Update

In your mirror dir:

- `git pull origin master`
- `composer install`

Then restart the workers
