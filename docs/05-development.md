# Development

# How to install a bundle inside Pimcore app.

## SimpleRESTAdapterBundle & PimcoreCIHubAdapterBundle
Choose your directories and use them in the following examples. 

Clone repository (fork) to the `/var/SimpleRESTAdapterBundle` directory.

    git clone https://github.com/BrandOriented/SimpleRESTAdapterBundle .

Clone repository (fork) to the `/var/PimcoreCIHubAdapterBundle` directory.

    git clone https://github.com/BrandOriented/PimcoreCIHubAdapterBundle.git .

Add the following as the first elements in the `repositories` section of the `composer.json` file.
```
    {
      "type": "path",
      "url": "./var/SimpleRESTAdapterBundle",
      "options": {
        "symlink": true
      }
    },
    {
      "type": "path",
      "url": "./var/PimcoreCIHubAdapterBundle",
      "options": {
        "symlink": true
      }
    }
```

Replace installation with the local version.

    composer req bo-hub/ci-hub-adapter-bundle "dev-main"
    composer req "bo-hub/ci-hub-api-bundle:dev-main as 3.0"

## Studio plugin
The Pimcore Studio screens of the bundle (CI HUB Tokens) are in `assets/studio` (TypeScript, Studio UI SDK). A bundle is
not built on deploy, so the build is committed in `src/Resources/public/studio/build/<buildId>` (`assets:install`
serves it under `/bundles/simplerestadapter/`, `Webpack\WebpackEntryPointProvider` registers it with Studio, only when
Studio is installed). After a change:

```bash
cd assets/studio && npm install && npm run check-types && npm run build
```

and commit the new build directory (the build id comes from the sources, an old directory is removed by the build).
The texts are in `src/Resources/translations/studio.*.yaml`, the API in `src/Controller/Studio`.

## Integration tests
`tests/Integration` tests the bundle against a real Pimcore: they boot the kernel of the project the bundle is
installed in and use its database (the user "admin" must exist; the users a test creates are deleted again). Run them
from the project:

```bash
vendor/bin/phpunit vendor/bo-hub/ci-hub-api-bundle/tests/Integration
```

or add the directory as a test suite to the project's `phpunit.xml`.
