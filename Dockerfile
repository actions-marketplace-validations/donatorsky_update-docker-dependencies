FROM php:8.0-cli-alpine

COPY ./src/ /usr/src/app/

ENTRYPOINT ["php", "/usr/src/app/index.php"]
