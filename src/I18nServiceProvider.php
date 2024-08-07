<?php

namespace Pine\I18n;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class I18nServiceProvider extends ServiceProvider
{

    public function register()
    {
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Publish the assets
        $this->publishes([
            __DIR__.'/../resources/js' => base_path('resources/js/vendor'),
        ]);

        // Register the @translations blade directive
        // expiression: key in window, resource keys to include
        Blade::directive('translations', function ($expression) {
            // logger('rebuilding....');
            $expression = preg_replace("/[\(\)]/", '', $expression);
            eval("\$params = [$expression];");
            list($key, $includes) = array_pad($params, 2, null);
            $includes = array_map('trim', explode(',', $includes));

            $cases = $this->translations($includes)->map(function ($translations, $locale) {
                return sprintf(
                    config('app.fallback_locale') === $locale
                        ? 'default: echo "%2$s"; break;'
                        : 'case "%1$s": echo "%2$s"; break;',
                    $locale, addslashes($translations)
                );
            })->implode(' ');

            return sprintf(
                '<script>window[\'%s\'] = <?php switch (App::getLocale()) { %s } ?>;</script>',
                $key ?: "'translations'", $cases
            );
        });
    }

    /**
     * Get the translations.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function translations(array $includes = []): Collection
    {
        $path = null;

        if (is_dir(base_path('lang'))) {
            $path = base_path('lang');
        } elseif (is_dir(base_path('resources/lang'))) {
            $path = base_path('resources/lang');
        } elseif (is_dir(base_path('vendor/laravel/framework/src/Illuminate/Translation/lang'))) {
            $path = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang');
        }

        $translations = is_null($path) ? collect() : $this->mapWithKeys(collect(File::directories($path)), function ($dir) use ($includes) {
            return [
                basename($dir) => collect($this->getFiles($dir))->flatMap(function ($file) use ($includes) {
                    if (in_array(basename($file, '.php'), $includes)) {
                        return [
                            basename($file, '.php') => (include $file),
                        ];
                    } else {
                        return [];
                    }
                }),
            ];
        });

        $jsonTranslations = $this->jsonTranslations($path);

        // $packageTranslations = $this->packageTranslations();

        return $this->mapWithKeys(
            $translations->keys()
                // ->merge($packageTranslations->keys())
                ->merge($jsonTranslations->keys())
                ->unique()
                ->values()
                // ->mapWithKeys(function ($locale) use ($translations, $jsonTranslations, $packageTranslations) {
            , function ($locale) use ($translations, $jsonTranslations) {
                                $locales = array_unique([
                                    $locale,
                                    config('app.fallback_locale'),
                                ]);

                                /*
                                 * Laravel docs describe the following behavior:
                                 *
                                 * - Package translations may be overridden with app translations:
                                 *      https://laravel.com/docs/10.x/localization#overriding-package-language-files
                                 * - Does a JSON translation file redefine a translation key used by a package or a
                                 *      PHP defined translation, the package defined or PHP defined tarnslation will be
                                 *      overridden:
                                 *      https://laravel.com/docs/10.x/localization#using-translation-strings-as-keys
                                 *          (Paragraph "Key / File conflicts")
                                 */
                                $prioritizedTranslations = [
                                    // $packageTranslations,
                                    $translations,
                                    $jsonTranslations,
                                ];

                                $fullTranslations = collect();
                                foreach ($prioritizedTranslations as $t) {
                                    foreach ($locales as $l) {
                                        if ($t->has($l)) {
                                            // $fullTranslations = $fullTranslations->replace($t->get($l));
                                            $fullTranslations = collect(array_replace($fullTranslations->all(), $this->getArrayableItems($t->get($l))));
                                            break;
                                        }
                                    }
                                }

                                return [
                                    $locale => $fullTranslations,
                                ];
                            });
    }

    /**
     * Get the package translations.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function packageTranslations()
    {
        $namespaces = $this->app['translator']->getLoader()->namespaces();

        return collect($namespaces)->map(function ($dir, $namespace) {
            return collect(File::directories($dir))->flatMap(function ($dir) use ($namespace) {
                return [
                    basename($dir) => collect([
                        $namespace.'::' => collect($this->getFiles($dir))->flatMap(function ($file) {
                            return [
                                $file->getBasename('.php') => (include $file->getPathname()),
                            ];
                        })->toArray(),
                    ]),
                ];
            })->toArray();
        })->reduce(function ($collection, $item) {
            return collect(array_merge_recursive($collection->toArray(), $item));
        }, collect())->map(function ($item) {
            return collect($item);
        });
    }

    /**
     * Get the application json translation files.
     *
     * @param string $dir Path to the application active lang dir.
     * @return \Illuminate\Support\Collection
     */
    protected function jsonTranslations($dir)
    {
        return $this->mapWithKeys(collect(File::glob($dir . '/*.json')),
            function ($path) {
                return [
                    basename($path, '.json') => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
                ];
            });
    }

    /**
     * Get the files of the given directory.
     *
     * @param  string  $dir
     * @return array
     */
    protected function getFiles($dir)
    {
        return is_dir($dir) ? File::files($dir) : [];
    }

    protected function mapWithKeys(Collection $collection, callable $callback)
    {
        $result = [];

        foreach ($collection->all() as $key => $value) {
            $assoc = $callback($value, $key);

            foreach ($assoc as $mapKey => $mapValue) {
                $result[$mapKey] = $mapValue;
            }
        }

        return new Collection($result);
    }

    protected function getArrayableItems($items)
    {
        if ($items instanceof Collection) {
            return $items->all();
        } elseif ($items instanceof Arrayable) {
            return $items->toArray();
        } elseif ($items instanceof Jsonable) {
            return json_decode($items->toJson(), true);
        }

        return (array) $items;
    }
}
