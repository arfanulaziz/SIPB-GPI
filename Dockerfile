FROM php:8.2-apache

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

# Enable mod_rewrite for .htaccess
RUN a2enmod rewrite

# Create uploads directory
RUN mkdir -p /var/www/html/uploads && chmod 755 /var/www/html/uploads

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port
EXPOSE 8080

# Start Apache
CMD ["apache2-foreground"]
