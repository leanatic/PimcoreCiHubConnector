# Installation
This bundle depends on the [Pimcore DataHub](https://github.com/pimcore/data-hub), which needs to be installed first.

To install the Simple REST Adapter complete following steps:
1. Install via composer
  ```shell
  composer require bo-hub/ci-hub-adapter-bundle
  composer require bo-hub/ci-hub-api-bundle
  ```

2. Enable via command-line (or inside the Pimcore extension manager)
  ```shell
  bin/console pimcore:bundle:enable SimpleRESTAdapterBundle
  ```
  
3. Extend security.yaml
  ```yaml
  access_control:
    - { path: ^/datahub, roles: PUBLIC_ACCESS }
  ```

4. Clear cache and reload Pimcore
  ```shell
  bin/console cache:clear --no-warmup
  ```

> Make sure, that the priority of the Pimcore DataHub is higher than the priority of the Simple REST Adapter.
> This can be specified as parameter of the `pimcore:bundle:enable` command or in the Pimcore extension manager.

### Other Examples
* [Docker Setup](03-docker-setup-example.md)

## Bundle Configuration
Configure the OpenSearch client and index name prefix with Symfony configuration. The OpenSearch connection itself
is a client of `pimcore/opensearch-client` (`pimcore_open_search_client.clients`):

```yaml
# Default configuration for "SimpleRESTAdapterBundle"
datahub_rest_adapter:

    # Prefix for index names.
    index_name_prefix:    datahub_restindex

    # Default providers that populate the index.
    asset_provider: 'CIHub\Bundle\SimpleRESTAdapterBundle\Provider\AssetProvider'
    data_object_provider: 'CIHub\Bundle\SimpleRESTAdapterBundle\Provider\DataObjectProvider'

    # Default transformer for the filter parameter. Used in search and tree-items endpoint.
    filter_field_name_transformer: 'CIHub\Bundle\SimpleRESTAdapterBundle\Transformer\FilterFieldNameTransformer'

    # Name of the OpenSearch client (pimcore_open_search_client.clients) to be used.
    opensearch_client_name: default

    # Global OpenSearch index settings.
    index_settings:

        # Defaults:
        number_of_shards:    5
        number_of_replicas:  0
        max_ngram_diff:      20
        analysis:
            analyzer:
                datahub_ngram_analyzer:
                    type:                custom
                    tokenizer:           datahub_ngram_tokenizer
                    filter:
                        - lowercase
                datahub_whitespace_analyzer:
                    type:                custom
                    tokenizer:           datahub_whitespace_tokenizer
                    filter:
                        - lowercase
            normalizer:
                lowercase:
                    type:                custom
                    filter:
                        - lowercase
            tokenizer:
                datahub_ngram_tokenizer:
                    type:                ngram
                    min_gram:            2
                    max_gram:            20
                    token_chars:
                        - letter
                        - digit
                datahub_whitespace_tokenizer:
                    type:                whitespace
```

> Supported OpenSearch version: ^2.7 (asset metadata is indexed as `flat_object`).

To make sure the indexing queue is processed and index is filled, the following command has to be executed on
a regular basis, e.g. every 5 minutes.

```cron
*/5 * * * * php /var/www/html/bin/console messenger:consume datahub_es_index_queue --limit=20 --time-limit=240 >/dev/null 2>&1
```
