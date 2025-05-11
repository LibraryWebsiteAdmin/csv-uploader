# Use an official PHP image with Apache
FROM php:8.1-apache

# Install PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

# Copy PHP files to the web server's root directory
COPY process_csv.php /var/www/html/
COPY .htaccess /var/www/html/

# Enable Apache rewrite module for .htaccess
RUN a2enmod rewrite

# Update Apache config to allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/html>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Ensure Apache can serve the files
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html

# Expose port 8080
EXPOSE 8080

# Start Apache in the foreground
CMD ["apache2-foreground"]
