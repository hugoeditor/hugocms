# HugoCMS Editor
a standalone CMS with online editor to write Hugo compatible content using Markdown and HTML.

[Read more](https://hugocms.com/en/)

## Requirements

- current browser
- php from version 7.4
- Hugo

## Dependencies

Dependencies on other open source projects:

- [Hugo](https://gohugo.io/)
- [elFinder](https://github.com/Studio-42/elFinder)
- [TinyMCE](https://www.tiny.cloud/)
- [marked](https://github.com/markedjs/marked)
- [ACE](https://ace.c9.io/)

## Installation

### Short instructions for webmasters

1. Download the tarball and unpack it into the working directory on the web server. [Download here](https://github.com/hugoeditor/hugocms/releases/)

2. Configure the website's document directory. The directory is the 'public ' directory.

3. Call up the CMS via your own domain with '/edit' appended.

4. (Optional) Copy the license files sent by mail into the 'hugocms' directory.

5. Set the password and startup settings for the CMS.

### Short instructions for developers

Starting from the working folder to be used with Hugo:

1. Install [Hugo](https://gohugo.io/) in the folder named 'hugo'

2. Create the new website with Hugo.

3. Clone the *HugoCMS Editor* repo into the 'static/edit/' directory, for example.

4. Create a folder named 'hugocms' in the working folder.

6. Create the *Hugo* configuration file (*config.json*).

7. Create the the website with *Hugo*.

8. Call up the CMS via your own domain with '/edit' appended.

9. Set the password and startup settings for the CMS.

[Read more](https://hugoeditor.com/en/install-use/)

## License

This project is licensed under the terms of the GNU GPLv3 license
[License](https://www.gnu.org/licenses/gpl-3.0)
