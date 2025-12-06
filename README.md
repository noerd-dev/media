# noerd/media

noerd Package is required
```
composer require noerd/noerd
php artisan noerd:install
```

Install the package. Make sure you already initiated a git project.
```
git submodule add git@github.com:noerd-dev/media.git app-modules/media
php artisan noerd:module media
composer update noerd/media
php artisan noerd:install-media
```