1. Install apache2-utils
    on Debian with apt install apache2-utils

2. Install Hugo
   e.g. apt install hugo
    or with tarball

3. Run hugo new site <directory>
   to set write permissions sudo -u <user> hugo new site <directory
   it should be noted that Hugo writes to the public subdirectory by default.

4. Clone the repo into the 'static/edit' directory, for example

5. Create a password with htpasswd and configure Nginx or Apache2 to protect the directory

6. Create the Hugo configuration file (config.json).

7. Call up the editor via your own domain with '/edit' appended

