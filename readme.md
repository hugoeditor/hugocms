Auf DE MACHEN!!
# HugoCMS (Online-Editor)

> **Veraltet.** Dieser Branch (`master`) beschreibt die alte Fassung von
> HugoCMS. HugoCMS wurde vollständig neu geschrieben; der neue Default-Branch
> ist **`main`**. Bitte dort weiterlesen:
> [hugoeditor/hugocms (main)](https://github.com/hugoeditor/hugocms/tree/main).
> Die getestete und freigegebene Fassung liegt fertig gebaut im Repo
> [hugocms-release](https://github.com/hugoeditor/hugocms-release).
>
> ⚠️ **Der Code hier ist unsicher und wird nicht mehr gepflegt.** Von einem
> weiteren Einsatz wird dringend abgeraten — bestehende Installationen sollten
> auf die neue Fassung umgestellt werden.

> **Outdated.** This branch (`master`) describes the old version of HugoCMS.
> HugoCMS has been rewritten from scratch; the new default branch is **`main`**.
> Please continue there:
> [hugoeditor/hugocms (main)](https://github.com/hugoeditor/hugocms/tree/main).
> The tested and released version is available pre-built in the
> [hugocms-release](https://github.com/hugoeditor/hugocms-release) repository.
>
> ⚠️ **The code here is insecure and no longer maintained.** Its continued use
> is strongly discouraged — existing installations should be migrated to the
> new version.

a standalone CMS with online editor to write Hugo compatible content using Markdown and HTML.

[Read more](https://hugocms.com/en/)

## Requirements

- current browser
- web hosting or web server with linux
- php from version 7.4

## Dependencies

Dependencies on other open source projects:

- [Hugo](https://gohugo.io/)
- [PurgeCSS](https://purgecss.com/)
- [elFinder](https://github.com/Studio-42/elFinder)
- [TinyMCE](https://www.tiny.cloud/)
- [marked](https://github.com/markedjs/marked)
- [ACE](https://ace.c9.io/)
- [Bash](https://www.gnu.org/software/bash/)

## Installation

### Short instructions

1. Download the tarball and unpack it or clone the git repo into the working directory on the web server. To clone the git repo and chechout the latest stable release.

    `git clone https://github.com/hugoeditor/hugocms.git . && git checkout $(git describe --tags $(git rev-list --tags --max-count=1))`

2. Configure the website's document directory with your web host. The directory is the “public” directory (a symbolic link to '_default_project/public/'.

3. Call up the CMS via your own domain with '/edit' appended.

4. Set the password and startup settings for the CMS.

### Pro version

If you want to support us you can buy a license for the pro version with full functionality.

### Complete documentation

[You can read here >>.](https://hugocms.com/en/docs/install-use/)

## License

This project is licensed under the terms of the GNU GPLv3 license
[License](https://www.gnu.org/licenses/gpl-3.0)
