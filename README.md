# Noerd Media Module

Install the package. Make sure you already initiated a git project and noerd is already installed.
```
git submodule add git@github.com:noerd-dev/media.git app-modules/media
php artisan noerd:module media
composer update noerd/media
```

Install Command to copy files and configs
```
php artisan noerd:install-media
```

Run database migrations
```
php artisan migrate
```

Compile the assets
```
npm install
npm run build
```
