# Update Docker dependencies

Action for automatic updating Dockerfile and docker-compose.yml dependencies.

## Inputs

### `skip-update`

A comma separated list of images that should not be checked for newer version.

Default `""`.

Example `"mysql,my/image"`.

### `semantically-versioned-repos`

A comma separated list of images that are semantically versioned. You can also override/disable this by adding `:false`.

Default `""`.

Example `"library/mysql,library/composer:false"`.

### `update-dockerfile`

**Required** Whether check for Dockerfile updates or not.

Default `true`.

Example `false`.

### `update-docker-compose`

**Required** Whether check for docker-composer.yml updates or not.

Default `true`.

Example `false`.

## Outputs

### `updates-count`

The number of newer versions detected.

### `message`

Example commit message that includes updated dependencies.

## Example usage

```yaml
  - uses: donatorsky/update-docker-dependencies@v1
    with:
      skip-update: 'mysql'
      update-docker-compose: false
```
