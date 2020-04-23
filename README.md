# Composer Repository Mirror

## Setup

- `git clone https://github.com/composer/mirror mirror`
- `cd mirror`
- `composer install`
- `cp mirror.config.php.dist mirror.config.php`
- Edit `mirror.config.php` to fit your needs, and mind the TODO entries which MUST be filled-in.
- Run it using supervisord or similar, it is made to shutdown regularly to avoid leaks or getting stuck for any reason. There are 3 scripts you should run:
    - `./mirror.php --v1` should be run permanently to sync Composer 1 metadata
    - `./mirror.php --v2` should be run permanently to sync Composer 2 metadata
    - `./mirror.php --gc` should be run once an hour or so with a cron job to clean up old files

## Update

In your mirror dir:

- `git pull origin master`
- `composer install`

Then restart the workers
