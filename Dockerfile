FROM php:8.2-apache

# Disable all MPM modules first
RUN a2dismod mpm_prefork mpm_worker mpm_event 2>/dev/null; true

# Enable PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Set working directory
WORKDIR /var/www/html

# Copy application code
COPY public_html/ /var/www/html/
COPY database/ /var/www/html/database/
COPY .env.example /var/www/html/.env

# Copy database migrations for reference
RUN mkdir -p /var/www/html/migrations && \
    cp /var/www/html/database/migrations/* /var/www/html/migrations/ || true

# Set Apache document root
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

# Enable mod_rewrite
RUN a2enmod rewrite

# Enable mpm_prefork as ONLY MPM (single process, most compatible)
RUN a2enmod mpm_prefork

# Create uploads directory
RUN mkdir -p /var/www/html/uploads && chmod 755 /var/www/html/uploads

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Set PHP configurations
RUN echo "upload_max_filesize = 10M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 10M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini

# Expose port
EXPOSE 8080

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Start Apache
CMD ["apache2-foreground"]
