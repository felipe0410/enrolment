FROM registry.code.go1.com.au/domain-infrastructure/docker-images/docker-php:8.2-nginx
COPY . /app
RUN rm -rf /app/.git/
RUN cp -r /app/vendor/go1/app/public /app/
WORKDIR /app
